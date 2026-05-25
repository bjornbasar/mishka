<?php

declare(strict_types=1);

namespace App\Calendar;

use Karhu\Db\Connection;

/**
 * Cancellations + overrides for recurring events (v0.3.1).
 *
 * Each row in `event_exceptions` represents one occurrence of a series being
 * either CANCELLED (override_event_id IS NULL) or OVERRIDDEN (override_event_id
 * → standalone Event row in `events` with series_event_id back-ref to the series).
 *
 * The cascade-on-series-edit policy (locked by the user in plan round-3): on a
 * clean time-shift, override `original_starts_at` rows shift by the same delta;
 * on a structural rrule/all_day change, all exceptions and their override events
 * are dropped via the two-step DELETE that `dropAllForEvent` performs.
 *
 * Why two-step? The FK on `event_exceptions.override_event_id REFERENCES events(id)
 * ON DELETE CASCADE` propagates only when the referenced Event row is deleted —
 * not when the exception row is deleted. So we must delete the override Event
 * rows FIRST (which cascades the corresponding exception rows), THEN delete the
 * pure cancellation rows. Wrapped in a transaction.
 */
final class EventExceptionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Mark an occurrence as cancelled. Idempotent via the UNIQUE
     * (event_id, original_starts_at) constraint — re-cancel is a no-op.
     */
    public function cancel(int $eventId, \DateTimeImmutable $originalStartsAt): void
    {
        try {
            $this->db->run(
                'INSERT INTO event_exceptions (event_id, original_starts_at, override_event_id)
                 VALUES (:e, :occ, NULL)',
                ['e' => $eventId, 'occ' => $this->formatLocal($originalStartsAt)],
            );
        } catch (\PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                return;  // already cancelled; idempotent
            }
            throw $e;
        }
    }

    /**
     * Create an override: insert the override Event row (with series_event_id
     * back-ref + no rrule) AND the event_exceptions row pointing at it.
     * Atomic — nested-txn guard mirrors EventRepository::create.
     *
     * @param array{
     *     title: string,
     *     description: string,
     *     location: string,
     *     starts_at_local: string,
     *     ends_at_local: string,
     *     timezone: string,
     *     all_day: bool,
     * } $overrideData
     * @return int new override event id
     */
    public function addOverride(
        int $seriesEventId,
        \DateTimeImmutable $originalStartsAt,
        array $overrideData,
    ): int {
        $pdo = $this->db->pdo();
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            // The override Event row needs household_id + created_by from the series.
            $series = $this->db->fetchOne(
                'SELECT household_id, created_by FROM events WHERE id = :id',
                ['id' => $seriesEventId],
            );
            if ($series === null) {
                throw new \RuntimeException("Series event {$seriesEventId} not found");
            }

            $overrideId = (int) $this->db->fetchScalar(
                'INSERT INTO events
                   (household_id, created_by, title, description, location,
                    starts_at_local, ends_at_local, timezone, all_day,
                    series_event_id)
                 VALUES
                   (:hid, :uid, :title, :description, :location,
                    :start, :end, :tz, :all_day, :series)
                 RETURNING id',
                [
                    'hid' => (int) $series['household_id'],
                    'uid' => (int) $series['created_by'],
                    'title' => (string) $overrideData['title'],
                    'description' => (string) $overrideData['description'],
                    'location' => (string) $overrideData['location'],
                    'start' => $this->truncateSeconds((string) $overrideData['starts_at_local']),
                    'end' => $this->truncateSeconds((string) $overrideData['ends_at_local']),
                    'tz' => (string) $overrideData['timezone'],
                    'all_day' => !empty($overrideData['all_day']) ? 1 : 0,
                    'series' => $seriesEventId,
                ],
            );

            $this->db->run(
                'INSERT INTO event_exceptions (event_id, original_starts_at, override_event_id)
                 VALUES (:e, :occ, :ov)',
                [
                    'e' => $seriesEventId,
                    'occ' => $this->formatLocal($originalStartsAt),
                    'ov' => $overrideId,
                ],
            );

            if ($transactionStarted) {
                $pdo->commit();
            }
            return $overrideId;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<array{id: int, event_id: int, original_starts_at: string,
     *                    override_event_id: ?int, override_event: ?array<string, mixed>}>
     */
    public function listForEvent(int $eventId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ex.id, ex.event_id, ex.original_starts_at, ex.override_event_id,
                    ov.title AS ov_title, ov.starts_at_local AS ov_starts_at_local,
                    ov.ends_at_local AS ov_ends_at_local
             FROM event_exceptions ex
             LEFT JOIN events ov ON ov.id = ex.override_event_id
             WHERE ex.event_id = :e
             ORDER BY ex.original_starts_at ASC',
            ['e' => $eventId],
        );

        $out = [];
        foreach ($rows as $row) {
            $overrideEvent = null;
            if ($row['override_event_id'] !== null) {
                $overrideEvent = [
                    'id' => (int) $row['override_event_id'],
                    'title' => (string) $row['ov_title'],
                    'starts_at_local' => (string) $row['ov_starts_at_local'],
                    'ends_at_local' => (string) $row['ov_ends_at_local'],
                ];
            }
            $out[] = [
                'id' => (int) $row['id'],
                'event_id' => (int) $row['event_id'],
                'original_starts_at' => (string) $row['original_starts_at'],
                'override_event_id' => $row['override_event_id'] === null ? null : (int) $row['override_event_id'],
                'override_event' => $overrideEvent,
            ];
        }
        return $out;
    }

    /**
     * Cascade shift: add $delta to every `original_starts_at` row for the series.
     * Used when the series start time moves by a clean delta and the rrule shape
     * is unchanged. Override Event rows themselves are NOT shifted (their times
     * were user-set; that's the whole point of an override).
     *
     * @return int number of rows shifted
     */
    public function cascadeShift(int $eventId, \DateInterval $delta): int
    {
        $rows = $this->db->fetchAll(
            'SELECT id, original_starts_at FROM event_exceptions WHERE event_id = :e',
            ['e' => $eventId],
        );

        $shifted = 0;
        foreach ($rows as $row) {
            $old = new \DateTimeImmutable((string) $row['original_starts_at']);
            $new = $old->add($delta);
            $this->db->run(
                'UPDATE event_exceptions SET original_starts_at = :new WHERE id = :id',
                ['new' => $new->format('Y-m-d H:i:s'), 'id' => (int) $row['id']],
            );
            $shifted++;
        }
        return $shifted;
    }

    /**
     * Drop all exceptions for a series (used on structural rrule changes).
     *
     * BLOCKING-bug-fix from round-3 review: FK CASCADE on `event_exceptions →
     * events(override_event_id)` only propagates when the override Event row is
     * DELETED — not when the exception row is. So this method does the two-step
     * delete explicitly:
     *
     *   1. SELECT override_event_ids for this series
     *   2. DELETE each override Event row (CASCADE wipes its exception row)
     *   3. DELETE the remaining (pure-cancellation) exception rows
     *
     * Atomic — nested-txn guard.
     *
     * @return int total rows dropped (override events + cancellation rows)
     */
    public function dropAllForEvent(int $eventId): int
    {
        $pdo = $this->db->pdo();
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            $dropped = 0;

            $overrideIds = $this->db->fetchAll(
                'SELECT override_event_id FROM event_exceptions
                 WHERE event_id = :e AND override_event_id IS NOT NULL',
                ['e' => $eventId],
            );
            foreach ($overrideIds as $row) {
                // Delete the override Event row — FK CASCADE on
                // event_exceptions.override_event_id wipes the matching exception row.
                $this->db->run('DELETE FROM events WHERE id = :id', ['id' => (int) $row['override_event_id']]);
                $dropped++;
            }

            // Now wipe any remaining (pure-cancellation) rows.
            $cancellations = (int) $this->db->run(
                'DELETE FROM event_exceptions WHERE event_id = :e AND override_event_id IS NULL',
                ['e' => $eventId],
            );
            $dropped += $cancellations;

            if ($transactionStarted) {
                $pdo->commit();
            }
            return $dropped;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function formatLocal(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d H:i:s');
    }

    private function truncateSeconds(string $local): string
    {
        return preg_replace('/:\d{2}$/', ':00', $local) ?? $local;
    }

    private function isUniqueViolation(\PDOException $e): bool
    {
        // PG: 23505; SQLite: 23000 with "UNIQUE constraint failed" in the message
        $sqlState = $e->getCode();
        if ($sqlState === '23505') {
            return true;
        }
        if ($sqlState === '23000' && str_contains($e->getMessage(), 'UNIQUE')) {
            return true;
        }
        return false;
    }
}
