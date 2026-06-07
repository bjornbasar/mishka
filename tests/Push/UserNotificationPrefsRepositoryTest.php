<?php

declare(strict_types=1);

namespace App\Tests\Push;

use App\Push\UserNotificationPrefsRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.0 — per-user push prefs (one row per user; upsert pattern mirrors
 * UserPreferenceRepository).
 */
final class UserNotificationPrefsRepositoryTest extends TestCase
{
    private Connection $db;
    private UserNotificationPrefsRepository $repo;
    private int $uid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new UserNotificationPrefsRepository($this->db);

        $this->uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'T') RETURNING id",
            ['e' => 'u-' . bin2hex(random_bytes(3)) . '@example.com'],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_get_for_returns_defaults_when_no_row_yet(): void
    {
        // Defaults: 15-min reminder, digest enabled. v0.6.6 adds two more
        // booleans, both defaulting TRUE (opt-out semantics).
        $prefs = $this->repo->getFor($this->uid);

        self::assertSame(15, $prefs['event_reminder_minutes']);
        self::assertTrue($prefs['overdue_chore_digest']);
        self::assertTrue($prefs['new_chore_assigned_enabled']);
        self::assertTrue($prefs['new_event_enabled']);
    }

    public function test_set_for_upserts_and_round_trips(): void
    {
        $this->repo->setFor($this->uid, [
            'event_reminder_minutes' => 30,
            'overdue_chore_digest' => false,
            'new_chore_assigned_enabled' => false,
            'new_event_enabled' => false,
        ]);

        $prefs = $this->repo->getFor($this->uid);
        self::assertSame(30, $prefs['event_reminder_minutes']);
        self::assertFalse($prefs['overdue_chore_digest']);
        self::assertFalse($prefs['new_chore_assigned_enabled']);
        self::assertFalse($prefs['new_event_enabled']);

        // Second setFor updates the same row, doesn't insert a second.
        $this->repo->setFor($this->uid, [
            'event_reminder_minutes' => 5,
            'overdue_chore_digest' => true,
            'new_chore_assigned_enabled' => true,
            'new_event_enabled' => true,
        ]);

        $prefs = $this->repo->getFor($this->uid);
        self::assertSame(5, $prefs['event_reminder_minutes']);
        self::assertTrue($prefs['overdue_chore_digest']);
        self::assertTrue($prefs['new_chore_assigned_enabled']);
        self::assertTrue($prefs['new_event_enabled']);

        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_notification_prefs WHERE user_id = :uid',
            ['uid' => $this->uid],
        );
        self::assertSame(1, $count);
    }

    public function test_set_for_clamps_minutes_to_valid_range(): void
    {
        // The CHECK constraint rejects negative and >1440. The repo accepts
        // any int and lets the DB enforce — but the controller should clamp
        // before this is reached. Here we just verify the CHECK fires.
        $this->expectException(\PDOException::class);
        $this->repo->setFor($this->uid, [
            'event_reminder_minutes' => -1,
            'overdue_chore_digest' => true,
            'new_chore_assigned_enabled' => true,
            'new_event_enabled' => true,
        ]);
    }

    public function test_fk_cascade_on_user_delete(): void
    {
        $this->repo->setFor($this->uid, [
            'event_reminder_minutes' => 30,
            'overdue_chore_digest' => true,
            'new_chore_assigned_enabled' => true,
            'new_event_enabled' => true,
        ]);

        $this->db->run('DELETE FROM users WHERE id = :id', ['id' => $this->uid]);

        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_notification_prefs WHERE user_id = :id',
            ['id' => $this->uid],
        );
        self::assertSame(0, $count);
    }

    // v0.6.6: partial-update semantics. setFor only updates keys present
    // in the input array; absent keys preserve their current value (or the
    // default if no row exists). This makes the v0.6.5→v0.6.6 deploy window
    // safe — a stale browser tab posting only the v0.6.5 keys does NOT
    // silently flip the new v0.6.6 booleans to false.
    public function test_set_for_is_a_partial_update(): void
    {
        // Establish a row with all 4 keys set to non-defaults.
        $this->repo->setFor($this->uid, [
            'event_reminder_minutes' => 60,
            'overdue_chore_digest' => false,
            'new_chore_assigned_enabled' => false,
            'new_event_enabled' => false,
        ]);

        // Partial setFor with only 2 keys — mimics a v0.6.5-cached form.
        $this->repo->setFor($this->uid, [
            'event_reminder_minutes' => 30,
            'overdue_chore_digest' => true,
        ]);

        $prefs = $this->repo->getFor($this->uid);
        // The 2 keys in the input took effect:
        self::assertSame(30, $prefs['event_reminder_minutes']);
        self::assertTrue($prefs['overdue_chore_digest']);
        // The 2 keys ABSENT from the input preserved their prior values:
        self::assertFalse($prefs['new_chore_assigned_enabled']);
        self::assertFalse($prefs['new_event_enabled']);
    }

    public function test_set_for_partial_first_time_uses_defaults_for_absent_keys(): void
    {
        // No existing row. Partial setFor with only 1 key. The 3 absent keys
        // should land at their defaults (15 / true / true / true).
        $this->repo->setFor($this->uid, [
            'event_reminder_minutes' => 45,
        ]);

        $prefs = $this->repo->getFor($this->uid);
        self::assertSame(45, $prefs['event_reminder_minutes']);
        self::assertTrue($prefs['overdue_chore_digest']);
        self::assertTrue($prefs['new_chore_assigned_enabled']);
        self::assertTrue($prefs['new_event_enabled']);
    }
}
