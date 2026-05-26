<?php

declare(strict_types=1);

namespace App\Chores;

use Karhu\Db\Connection;

/**
 * Persistence for household chores (v0.4.0 — one-off; recurrence in v0.4.1).
 *
 * Mirrors App\Calendar\EventRepository: nested-transaction guard on create,
 * IANA timezone validation, minute-precision truncation on due_at_local, and a
 * WRITABLE_COLUMNS whitelist on update. Wall-clock + timezone time model (NOT
 * UTC) so v0.4.1 recurrence expands DST-correctly.
 *
 * `completed_at` is the sole done-indicator (NULL = open). The points tally is
 * a LIVE aggregate over completed chores, crediting COALESCE(completed_by,
 * assigned_to) — the doer — and driven off the current household_members so the
 * board only ever lists current members.
 */
final class ChoreRepository
{
    private const WRITABLE_COLUMNS = ['title', 'description', 'points', 'due_at_local', 'assigned_to'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{
     *     household_id: int,
     *     created_by: int,
     *     title: string,
     *     description?: string,
     *     points?: int,
     *     due_at_local?: ?string,    // 'Y-m-d H:i:s' local; seconds truncated; null = no due date
     *     assigned_to?: ?int,
     *     timezone: string,          // IANA name
     * } $data
     */
    public function create(array $data): int
    {
        $this->validateTimezone((string) $data['timezone']);

        $due = $data['due_at_local'] ?? null;
        $due = ($due === null || $due === '') ? null : $this->truncateSeconds((string) $due);
        // isset() is false for a null value, so this maps both "absent" and
        // "explicitly null" to null, and any present id to int.
        $assignedTo = isset($data['assigned_to']) ? (int) $data['assigned_to'] : null;

        $pdo = $this->db->pdo();
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO chores
                   (household_id, created_by, assigned_to, title, description, points, due_at_local, timezone)
                 VALUES
                   (:hid, :uid, :assigned, :title, :description, :points, :due, :tz)
                 RETURNING id',
                [
                    'hid' => (int) $data['household_id'],
                    'uid' => (int) $data['created_by'],
                    'assigned' => $assignedTo,
                    'title' => (string) $data['title'],
                    'description' => (string) ($data['description'] ?? ''),
                    'points' => (int) ($data['points'] ?? 0),
                    'due' => $due,
                    'tz' => (string) $data['timezone'],
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

    /** @return array<string, mixed>|null */
    public function findById(int $choreId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM chores WHERE id = :id', ['id' => $choreId]);
        return $row === null ? null : $this->normaliseRow($row);
    }

    /**
     * All chores for a household. Open chores first (by due, NULL-due last),
     * then completed. The controller partitions into the open list + a
     * completed_at-DESC "Done" section. No defensive schedule_id filter — v0.4.1
     * generated instances are first-class list items (templates live in a
     * separate table, so there's no double-render risk like the calendar had).
     *
     * @return list<array<string, mixed>>
     */
    public function listForHousehold(int $householdId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM chores
             WHERE household_id = :hid
             ORDER BY (completed_at IS NOT NULL) ASC,
                      (due_at_local IS NULL) ASC,
                      due_at_local ASC,
                      id ASC',
            ['hid' => $householdId],
        );
        return array_map(fn(array $r): array => $this->normaliseRow($r), $rows);
    }

    /** @param array<string, mixed> $data */
    public function update(int $choreId, array $data): void
    {
        $sets = [];
        $params = ['id' => $choreId];
        foreach (self::WRITABLE_COLUMNS as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $value = $data[$col];
            if ($col === 'due_at_local') {
                $value = ($value === null || $value === '') ? null : $this->truncateSeconds((string) $value);
            }
            if ($col === 'assigned_to') {
                $value = ($value === null || $value === '') ? null : (int) $value;
            }
            if ($col === 'points') {
                $value = (int) $value;
            }
            $sets[] = "{$col} = :{$col}";
            $params[$col] = $value;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';

        $this->db->run('UPDATE chores SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    public function delete(int $choreId): void
    {
        $this->db->run('DELETE FROM chores WHERE id = :id', ['id' => $choreId]);
    }

    /** Idempotent: only sets completion fields when the chore is currently open. */
    public function markDone(int $choreId, int $byUserId): void
    {
        $this->db->run(
            'UPDATE chores
                SET completed_at = CURRENT_TIMESTAMP, completed_by = :uid, updated_at = CURRENT_TIMESTAMP
              WHERE id = :id AND completed_at IS NULL',
            ['uid' => $byUserId, 'id' => $choreId],
        );
    }

    public function reopen(int $choreId): void
    {
        $this->db->run(
            'UPDATE chores
                SET completed_at = NULL, completed_by = NULL, updated_at = CURRENT_TIMESTAMP
              WHERE id = :id',
            ['id' => $choreId],
        );
    }

    /**
     * Per-member all-time points: SUM of completed chores credited to the doer
     * (COALESCE(completed_by, assigned_to)). Driven off household_members so
     * every current member appears (0 if none earned) and a departed member
     * silently drops off. ORDER BY MIN(joined_at) so PostgreSQL's GROUP BY rule
     * is satisfied (SQLite is permissive; PG rejects a bare non-grouped column).
     *
     * @return list<array{user_id: int, display_name: string, email: string, total_points: int}>
     */
    public function pointsTallyForHousehold(int $householdId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT u.id AS user_id, u.display_name, u.email,
                    COALESCE(SUM(c.points), 0) AS total_points
             FROM household_members m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN chores c
                    ON c.household_id = m.household_id
                   AND c.completed_at IS NOT NULL
                   AND COALESCE(c.completed_by, c.assigned_to) = u.id
             WHERE m.household_id = :hid AND u.id > 0
             GROUP BY u.id, u.display_name, u.email
             ORDER BY MIN(m.joined_at) ASC',
            ['hid' => $householdId],
        );

        return array_map(
            fn(array $r): array => [
                'user_id' => (int) $r['user_id'],
                'display_name' => (string) $r['display_name'],
                'email' => (string) $r['email'],
                'total_points' => (int) $r['total_points'],
            ],
            $rows,
        );
    }

    private function validateTimezone(string $tz): void
    {
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException("Invalid timezone: {$tz}");
        }
    }

    /** Round a 'Y-m-d H:i:s' string DOWN to the minute (matches EventRepository). */
    private function truncateSeconds(string $local): string
    {
        return preg_replace('/:\d{2}$/', ':00', $local) ?? $local;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'household_id' => (int) $row['household_id'],
            'created_by' => (int) $row['created_by'],
            'assigned_to' => $row['assigned_to'] === null ? null : (int) $row['assigned_to'],
            'completed_by' => $row['completed_by'] === null ? null : (int) $row['completed_by'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'points' => (int) $row['points'],
            'due_at_local' => $row['due_at_local'] === null ? null : (string) $row['due_at_local'],
            'timezone' => (string) $row['timezone'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            'schedule_id' => $row['schedule_id'] === null ? null : (int) $row['schedule_id'],
            'occurrence_date' => $row['occurrence_date'] === null ? null : (string) $row['occurrence_date'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'is_done' => $row['completed_at'] !== null,
        ];
    }
}
