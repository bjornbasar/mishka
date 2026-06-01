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
        // Default: 15-min reminder, digest enabled. Locked in the schema CHECK.
        $prefs = $this->repo->getFor($this->uid);

        self::assertSame(15, $prefs['event_reminder_minutes']);
        self::assertTrue($prefs['overdue_chore_digest']);
    }

    public function test_set_for_upserts_and_round_trips(): void
    {
        $this->repo->setFor($this->uid, [
            'event_reminder_minutes' => 30,
            'overdue_chore_digest' => false,
        ]);

        $prefs = $this->repo->getFor($this->uid);
        self::assertSame(30, $prefs['event_reminder_minutes']);
        self::assertFalse($prefs['overdue_chore_digest']);

        // Second setFor updates the same row, doesn't insert a second.
        $this->repo->setFor($this->uid, [
            'event_reminder_minutes' => 5,
            'overdue_chore_digest' => true,
        ]);

        $prefs = $this->repo->getFor($this->uid);
        self::assertSame(5, $prefs['event_reminder_minutes']);
        self::assertTrue($prefs['overdue_chore_digest']);

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
        ]);
    }

    public function test_fk_cascade_on_user_delete(): void
    {
        $this->repo->setFor($this->uid, [
            'event_reminder_minutes' => 30,
            'overdue_chore_digest' => true,
        ]);

        $this->db->run('DELETE FROM users WHERE id = :id', ['id' => $this->uid]);

        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_notification_prefs WHERE user_id = :id',
            ['id' => $this->uid],
        );
        self::assertSame(0, $count);
    }
}
