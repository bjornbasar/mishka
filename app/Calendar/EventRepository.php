<?php

declare(strict_types=1);

namespace App\Calendar;

use Karhu\Db\Connection;

/**
 * Calendar events — one-off only at v0.3.0. v0.3.1 wires up the `rrule` column
 * for recurring series + the `series_event_id` back-reference for overrides;
 * those columns ship in this schema (inert) so the next release doesn't ALTER.
 *
 * Time storage: `starts_at_local` + `ends_at_local` are bare TIMESTAMPs (local
 * wall-clock), paired with a `timezone` VARCHAR. UTC-as-TIMESTAMPTZ drifts under
 * DST for recurring events. Recurrence expansion happens in the event's tz.
 *
 * DEFENSIVE FILTER on findInRangeForHousehold: excludes override events (where
 * series_event_id IS NOT NULL) even though v0.3.0 never creates them. Future-
 * proofs the query so v0.3.1's overrides don't accidentally double-render via
 * this path. The round-3 review flagged this as a load-bearing filter.
 */
final class EventRepository
{
    /** Whitelist of columns the controller layer is allowed to set via $data. */
    private const WRITABLE_COLUMNS = [
        'title', 'description', 'location',
        'starts_at_local', 'ends_at_local', 'timezone',
        'all_day',
        'rrule',  // v0.3.1+
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Create an event. Nested-txn guard mirrors HouseholdRepository::createForOwner.
     * Validates timezone is a real IANA name; truncates seconds (minute precision).
     *
     * @param array{
     *     household_id: int,
     *     created_by: int,
     *     title: string,
     *     description?: string,
     *     location?: string,
     *     starts_at_local: string,    // 'Y-m-d H:i:s' local; seconds will be truncated
     *     ends_at_local: string,
     *     timezone: string,            // IANA name
     *     all_day?: bool,
     *     rrule?: ?string,             // v0.3.1+; null/empty means no recurrence
     * } $data
     * @return int new event id
     */
    public function create(array $data): int
    {
        $this->validateTimezone((string) $data['timezone']);

        $pdo = $this->db->pdo();
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            $rrule = isset($data['rrule']) ? (string) $data['rrule'] : null;
            if ($rrule === '') {
                $rrule = null;
            }

            $id = (int) $this->db->fetchScalar(
                'INSERT INTO events
                   (household_id, created_by, title, description, location,
                    starts_at_local, ends_at_local, timezone, all_day, rrule)
                 VALUES
                   (:hid, :uid, :title, :description, :location,
                    :start, :end, :tz, :all_day, :rrule)
                 RETURNING id',
                [
                    'hid' => (int) $data['household_id'],
                    'uid' => (int) $data['created_by'],
                    'title' => (string) $data['title'],
                    'description' => (string) ($data['description'] ?? ''),
                    'location' => (string) ($data['location'] ?? ''),
                    'start' => $this->truncateSeconds((string) $data['starts_at_local']),
                    'end' => $this->truncateSeconds((string) $data['ends_at_local']),
                    'tz' => (string) $data['timezone'],
                    'all_day' => !empty($data['all_day']) ? 1 : 0,
                    'rrule' => $rrule,
                ],
            );

            if ($transactionStarted) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{id: int, household_id: int, created_by: int, title: string,
     *               description: string, location: string, starts_at_local: string,
     *               ends_at_local: string, timezone: string, all_day: bool,
     *               rrule: ?string, series_event_id: ?int,
     *               created_at: string, updated_at: string}|null
     */
    public function findById(int $eventId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM events WHERE id = :id', ['id' => $eventId]);
        return $row === null ? null : $this->normaliseRow($row);
    }

    /**
     * Events whose [starts_at_local, ends_at_local] window intersects [start, end].
     * Filters series_event_id IS NULL defensively (excludes future v0.3.1 overrides).
     * Range bounds are in the household's timezone — v0.3 locks every event to that
     * tz, so string comparison is correct.
     *
     * @return list<array{id: int, household_id: int, created_by: int, title: string,
     *                    description: string, location: string, starts_at_local: string,
     *                    ends_at_local: string, timezone: string, all_day: bool,
     *                    rrule: ?string, series_event_id: ?int,
     *                    created_at: string, updated_at: string}>
     */
    public function findInRangeForHousehold(
        int $householdId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): array {
        // Defensive filters:
        //   - series_event_id IS NULL excludes override events (v0.3.1+)
        //   - rrule IS NULL OR rrule = '' excludes recurring series (handled by
        //     findRecurringForHousehold + RangeExpander instead — otherwise a
        //     recurring series whose anchor date falls in the range would emit
        //     here AND from the expander, double-rendering)
        $rows = $this->db->fetchAll(
            "SELECT * FROM events
             WHERE household_id = :hid
               AND series_event_id IS NULL
               AND (rrule IS NULL OR rrule = '')
               AND starts_at_local <= :rangeEnd
               AND ends_at_local   >= :rangeStart
             ORDER BY starts_at_local ASC",
            [
                'hid' => $householdId,
                'rangeStart' => $start->format('Y-m-d H:i:s'),
                'rangeEnd' => $end->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(fn(array $r): array => $this->normaliseRow($r), $rows);
    }

    /**
     * Every recurring series for a household. RangeExpander uses this (separately
     * from findInRangeForHousehold) because recurring series can have occurrences
     * inside the range even if their `starts_at_local` is far outside it.
     *
     * @return list<array{id: int, household_id: int, created_by: int, title: string,
     *                    description: string, location: string, starts_at_local: string,
     *                    ends_at_local: string, timezone: string, all_day: bool,
     *                    rrule: ?string, series_event_id: ?int,
     *                    created_at: string, updated_at: string}>
     */
    public function findRecurringForHousehold(int $householdId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM events
             WHERE household_id = :hid
               AND rrule IS NOT NULL
               AND rrule <> \'\'
             ORDER BY starts_at_local ASC',
            ['hid' => $householdId],
        );
        return array_map(fn(array $r): array => $this->normaliseRow($r), $rows);
    }

    /**
     * Optimistic-concurrency update.
     * @param array<string, mixed> $data column → value (only whitelisted columns are written)
     * @throws ConcurrentUpdateException when the row's current updated_at no longer matches.
     */
    public function update(int $eventId, array $data, string $expectedUpdatedAt): void
    {
        $current = $this->db->fetchScalar(
            'SELECT updated_at FROM events WHERE id = :id',
            ['id' => $eventId],
        );
        if ($current === false || $current === null || (string) $current !== $expectedUpdatedAt) {
            throw new ConcurrentUpdateException();
        }

        if (isset($data['timezone'])) {
            $this->validateTimezone((string) $data['timezone']);
        }

        // Build a parameterised UPDATE only over the whitelisted columns the
        // caller actually supplied (defends against controller bugs that try
        // to set system columns like id, created_at, household_id).
        $sets = [];
        $params = ['id' => $eventId];
        foreach (self::WRITABLE_COLUMNS as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $value = $data[$col];
            if ($col === 'starts_at_local' || $col === 'ends_at_local') {
                $value = $this->truncateSeconds((string) $value);
            }
            if ($col === 'all_day') {
                $value = !empty($value) ? 1 : 0;
            }
            if ($col === 'rrule' && ($value === '' || $value === false)) {
                $value = null;  // empty string means "no recurrence" — normalise to NULL
            }
            $sets[] = "{$col} = :{$col}";
            $params[$col] = $value;
        }
        if ($sets === []) {
            return;  // nothing to update
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';

        $this->db->run(
            'UPDATE events SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params,
        );
    }

    public function delete(int $eventId): void
    {
        $this->db->run('DELETE FROM events WHERE id = :id', ['id' => $eventId]);
    }

    private function validateTimezone(string $tz): void
    {
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException("Invalid timezone: {$tz}");
        }
    }

    /**
     * Round a 'Y-m-d H:i:s' string DOWN to the minute. Slug uniqueness in v0.3.1
     * occurrence URLs assumes minute precision.
     */
    private function truncateSeconds(string $local): string
    {
        return preg_replace('/:\d{2}$/', ':00', $local) ?? $local;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, household_id: int, created_by: int, title: string,
     *               description: string, location: string, starts_at_local: string,
     *               ends_at_local: string, timezone: string, all_day: bool,
     *               rrule: ?string, series_event_id: ?int,
     *               created_at: string, updated_at: string}
     */
    private function normaliseRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'household_id' => (int) $row['household_id'],
            'created_by' => (int) $row['created_by'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'location' => (string) $row['location'],
            'starts_at_local' => (string) $row['starts_at_local'],
            'ends_at_local' => (string) $row['ends_at_local'],
            'timezone' => (string) $row['timezone'],
            'all_day' => (bool) $row['all_day'],
            'rrule' => $row['rrule'] === null ? null : (string) $row['rrule'],
            'series_event_id' => $row['series_event_id'] === null ? null : (int) $row['series_event_id'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
