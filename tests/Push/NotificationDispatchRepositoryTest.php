<?php

declare(strict_types=1);

namespace App\Tests\Push;

use App\Push\NotificationDispatchRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.0 — at-most-once dispatch ledger.
 *
 * claim() returns true iff this (user, kind, ref_id) is being seen for the
 * first time. The UNIQUE index makes the underlying INSERT race-safe: two
 * concurrent cron ticks can both call claim() for the same event; only one
 * INSERT survives the constraint, the other returns false.
 *
 * prune() drops old rows so the table doesn't grow forever (B4).
 */
final class NotificationDispatchRepositoryTest extends TestCase
{
    private Connection $db;
    private NotificationDispatchRepository $repo;
    private int $uid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new NotificationDispatchRepository($this->db);

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

    public function test_claim_wins_on_first_call(): void
    {
        self::assertTrue($this->repo->claim($this->uid, 'event_reminder', 42));
    }

    public function test_claim_loses_on_second_call_same_key(): void
    {
        self::assertTrue($this->repo->claim($this->uid, 'event_reminder', 42));
        self::assertFalse($this->repo->claim($this->uid, 'event_reminder', 42));
    }

    public function test_claim_for_different_kind_or_ref_id_or_user_does_not_collide(): void
    {
        $other = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'O') RETURNING id",
            ['e' => 'o-' . bin2hex(random_bytes(3)) . '@example.com'],
        );

        self::assertTrue($this->repo->claim($this->uid, 'event_reminder', 42));
        // Different kind, same uid + ref_id — independent.
        self::assertTrue($this->repo->claim($this->uid, 'overdue_digest', 42));
        // Different ref_id — independent.
        self::assertTrue($this->repo->claim($this->uid, 'event_reminder', 99));
        // Different user — independent.
        self::assertTrue($this->repo->claim($other, 'event_reminder', 42));
    }

    public function test_prune_drops_rows_older_than_cutoff(): void
    {
        // Two rows; backdate one.
        $this->repo->claim($this->uid, 'event_reminder', 1);
        $this->repo->claim($this->uid, 'event_reminder', 2);

        // Backdate the first via direct UPDATE (the repo only inserts NOW()).
        $past = gmdate('Y-m-d H:i:s', time() - 95 * 86400);
        $this->db->run(
            "UPDATE notification_dispatches
             SET dispatched_at = :past
             WHERE user_id = :uid AND ref_id = 1",
            ['past' => $past, 'uid' => $this->uid],
        );

        $deleted = $this->repo->prune(90);
        self::assertSame(1, $deleted);

        // Verify only the recent one survives.
        $remaining = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM notification_dispatches WHERE user_id = :uid',
            ['uid' => $this->uid],
        );
        self::assertSame(1, $remaining);
    }

    public function test_check_constraint_rejects_unknown_kind(): void
    {
        // The schema-level CHECK guards forward-compat — adding a new kind
        // needs a schema bump, not just controller code.
        $this->expectException(\PDOException::class);
        $this->repo->claim($this->uid, 'made_up_kind', 1);
    }
}
