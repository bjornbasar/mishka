<?php

declare(strict_types=1);

namespace App\Chores;

use Karhu\Db\Connection;

/**
 * Persistence for recurring-chore templates (v0.4.1).
 *
 * Mirrors ChoreRepository: nested-transaction guard on create, IANA timezone
 * validation, WRITABLE_COLUMNS whitelist on update, normaliseRow casting.
 *
 * The rotation cursor is `last_assigned_user_id` — a DURABLE user id, not an
 * index — so ChoreScheduleGenerator can compute the next assignee as a pure
 * function of (last_assigned_user_id, current members) that survives membership
 * churn. `generated_through` is the high-water mark that bounds lazy generation.
 */
final class ChoreScheduleRepository
{
    private const WRITABLE_COLUMNS = [
        'title', 'description', 'points', 'rrule', 'anchor_at_local', 'assignment_mode', 'fixed_user_id',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{
     *     household_id: int,
     *     created_by: int,
     *     title: string,
     *     description?: string,
     *     points?: int,
     *     rrule: string,
     *     anchor_at_local: string,
     *     timezone: string,
     *     assignment_mode?: string,
     *     fixed_user_id?: ?int,
     *     last_assigned_user_id?: ?int,
     * } $data
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
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO chore_schedules
                   (household_id, created_by, title, description, points, rrule,
                    anchor_at_local, timezone, assignment_mode, fixed_user_id, last_assigned_user_id)
                 VALUES
                   (:hid, :uid, :title, :description, :points, :rrule,
                    :anchor, :tz, :mode, :fixed, :last)
                 RETURNING id',
                [
                    'hid' => (int) $data['household_id'],
                    'uid' => (int) $data['created_by'],
                    'title' => (string) $data['title'],
                    'description' => (string) ($data['description'] ?? ''),
                    'points' => (int) ($data['points'] ?? 0),
                    'rrule' => (string) $data['rrule'],
                    'anchor' => (string) $data['anchor_at_local'],
                    'tz' => (string) $data['timezone'],
                    'mode' => (string) ($data['assignment_mode'] ?? 'rotate'),
                    'fixed' => isset($data['fixed_user_id']) ? (int) $data['fixed_user_id'] : null,
                    'last' => isset($data['last_assigned_user_id']) ? (int) $data['last_assigned_user_id'] : null,
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
    public function findById(int $scheduleId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM chore_schedules WHERE id = :id', ['id' => $scheduleId]);
        return $row === null ? null : $this->normaliseRow($row);
    }

    /** @return list<array<string, mixed>> */
    public function listForHousehold(int $householdId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM chore_schedules WHERE household_id = :hid ORDER BY created_at ASC, id ASC',
            ['hid' => $householdId],
        );
        return array_map(fn(array $r): array => $this->normaliseRow($r), $rows);
    }

    /** @param array<string, mixed> $data */
    public function update(int $scheduleId, array $data): void
    {
        $sets = [];
        $params = ['id' => $scheduleId];
        foreach (self::WRITABLE_COLUMNS as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $value = $data[$col];
            if ($col === 'points') {
                $value = (int) $value;
            }
            if ($col === 'fixed_user_id') {
                $value = ($value === null || $value === '') ? null : (int) $value;
            }
            $sets[] = "{$col} = :{$col}";
            $params[$col] = $value;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';

        $this->db->run('UPDATE chore_schedules SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    public function delete(int $scheduleId): void
    {
        $this->db->run('DELETE FROM chore_schedules WHERE id = :id', ['id' => $scheduleId]);
    }

    /** Advance (or reset) the rotation cursor — a durable user id. */
    public function setRotation(int $scheduleId, ?int $lastAssignedUserId): void
    {
        $this->db->run(
            'UPDATE chore_schedules SET last_assigned_user_id = :u, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['u' => $lastAssignedUserId, 'id' => $scheduleId],
        );
    }

    public function setGeneratedThrough(int $scheduleId, ?string $ts): void
    {
        $this->db->run(
            'UPDATE chore_schedules SET generated_through = :t, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['t' => $ts, 'id' => $scheduleId],
        );
    }

    private function validateTimezone(string $tz): void
    {
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException("Invalid timezone: {$tz}");
        }
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
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'points' => (int) $row['points'],
            'rrule' => (string) $row['rrule'],
            'anchor_at_local' => (string) $row['anchor_at_local'],
            'timezone' => (string) $row['timezone'],
            'assignment_mode' => (string) $row['assignment_mode'],
            'fixed_user_id' => $row['fixed_user_id'] === null ? null : (int) $row['fixed_user_id'],
            'last_assigned_user_id' => $row['last_assigned_user_id'] === null ? null : (int) $row['last_assigned_user_id'],
            'generated_through' => $row['generated_through'] === null ? null : (string) $row['generated_through'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
