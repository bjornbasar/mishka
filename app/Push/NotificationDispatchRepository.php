<?php

declare(strict_types=1);

namespace App\Push;

use Karhu\Db\Connection;

/**
 * v0.6.0 — at-most-once dispatch ledger for push notifications.
 *
 * `claim()` is the atomic guard: INSERT … ON CONFLICT DO NOTHING returns
 * `rowCount === 1` iff the row didn't exist before. The UNIQUE index on
 * (user_id, kind, ref_id) makes this race-safe across concurrent cron
 * scanners. Caller proceeds with enqueue only on true.
 *
 * The ref_id semantics by kind are documented in db/schema.sql:
 *   - 'event_reminder' → events.id
 *   - 'overdue_digest' → YYYYMMDD as int in household tz (e.g., 20260601)
 *
 * `prune()` keeps the table from growing unbounded (B4). Run at the top of
 * each push:scan tick; deletes anything older than `$daysToKeep`.
 */
final class NotificationDispatchRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Returns true iff this (user, kind, ref_id) was claimed by THIS call.
     * The CHECK constraint on `kind` will throw on unknown values.
     */
    public function claim(int $userId, string $kind, int $refId): bool
    {
        $rows = $this->db->run(
            'INSERT INTO notification_dispatches (user_id, kind, ref_id)
             VALUES (:uid, :kind, :ref)
             ON CONFLICT (user_id, kind, ref_id) DO NOTHING',
            ['uid' => $userId, 'kind' => $kind, 'ref' => $refId],
        );
        return $rows === 1;
    }

    /**
     * Delete dispatch rows older than `$daysToKeep`. Returns the row count
     * deleted (for ops logging). Comparison uses gmdate so SQLite-portable.
     */
    public function prune(int $daysToKeep): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $daysToKeep * 86400);
        return $this->db->run(
            'DELETE FROM notification_dispatches WHERE dispatched_at < :cutoff',
            ['cutoff' => $cutoff],
        );
    }
}
