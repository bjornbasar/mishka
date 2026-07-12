<?php

declare(strict_types=1);

namespace App\Tracker;

use Karhu\Db\Connection;

/**
 * v0.8.1 — exercise catalog CRUD + case-insensitive live search.
 *
 * Mirrors FoodRepository's shape: household_id NULL = global seed (from
 * db/seed/tracker_exercises.json + bin/karhu tracker:seed-exercises);
 * household_id NOT NULL + source='custom' = household's own exercise.
 * name_lc is repo-owned (mb_strtolower(name), written on every
 * create/update; callers can't override).
 *
 * search() returns type + met + default_rom_m per row — no INNER JOIN
 * (unlike FoodRepository which joined food_servings for default_serving).
 * Every exercise row is self-contained.
 *
 * MET bound to (0, 25] on create AND update — Compendium max is ~23
 * for extreme running; 25 is a safety ceiling for custom user entries.
 * See DOCS.md #71.
 */
final class ExerciseRepository
{
    private const WRITABLE_COLUMNS = ['name', 'type', 'met', 'default_rom_m', 'source'];
    private const MET_MIN = 0.0;
    private const MET_MAX = 25.0;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{
     *     name: string,
     *     type: string,             // 'duration' | 'strength'
     *     met: float,                // (0, 25]
     *     default_rom_m?: ?float,
     *     source?: string,           // defaults to 'custom'
     * } $data
     */
    public function create(?int $householdId, array $data, ?int $createdBy): int
    {
        $name = trim((string) $data['name']);
        if ($name === '') {
            throw new \InvalidArgumentException('exercises.name must be non-empty');
        }
        $type = (string) $data['type'];
        if (!in_array($type, ['duration', 'strength'], true)) {
            throw new \InvalidArgumentException("exercises.type invalid: {$type}");
        }
        $met = (float) $data['met'];
        if ($met <= self::MET_MIN || $met > self::MET_MAX) {
            throw new \InvalidArgumentException("exercises.met out of range (0, 25]: {$met}");
        }
        $source = (string) ($data['source'] ?? 'custom');
        if (!in_array($source, ['compendium', 'custom'], true)) {
            throw new \InvalidArgumentException("exercises.source invalid: {$source}");
        }

        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO exercises
                    (household_id, name, name_lc, type, met, default_rom_m, source, created_by)
                 VALUES
                    (:hid, :name, :name_lc, :type, :met, :rom, :src, :cby)
                 RETURNING id',
                [
                    'hid' => $householdId,
                    'name' => $name,
                    'name_lc' => mb_strtolower($name),
                    'type' => $type,
                    'met' => $met,
                    'rom' => isset($data['default_rom_m']) ? (float) $data['default_rom_m'] : null,
                    'src' => $source,
                    'cby' => $createdBy,
                ],
            );
            if ($started) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($started) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $data whitelist filtered */
    public function update(int $id, array $data): void
    {
        $filtered = array_intersect_key($data, array_flip(self::WRITABLE_COLUMNS));
        if (isset($filtered['name'])) {
            $filtered['name'] = trim((string) $filtered['name']);
            if ($filtered['name'] === '') {
                throw new \InvalidArgumentException('exercises.name must be non-empty');
            }
            $filtered['name_lc'] = mb_strtolower($filtered['name']);
        }
        if (isset($filtered['type']) && !in_array((string) $filtered['type'], ['duration', 'strength'], true)) {
            throw new \InvalidArgumentException("exercises.type invalid: {$filtered['type']}");
        }
        if (isset($filtered['met'])) {
            $met = (float) $filtered['met'];
            if ($met <= self::MET_MIN || $met > self::MET_MAX) {
                throw new \InvalidArgumentException("exercises.met out of range (0, 25]: {$met}");
            }
            $filtered['met'] = $met;
        }
        if (isset($filtered['source']) && !in_array((string) $filtered['source'], ['compendium', 'custom'], true)) {
            throw new \InvalidArgumentException("exercises.source invalid: {$filtered['source']}");
        }
        if ($filtered === []) {
            return;
        }
        $sets = [];
        foreach (array_keys($filtered) as $col) {
            $sets[] = "{$col} = :{$col}";
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $filtered['id'] = $id;
        $this->db->run(
            'UPDATE exercises SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $filtered,
        );
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM exercises WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array{id: int, household_id: ?int, name: string, type: string, met: string, default_rom_m: ?string, source: string, created_by: ?int, created_at: string, updated_at: string}|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, household_id, name, type, met, default_rom_m, source, created_by, created_at, updated_at
             FROM exercises WHERE id = :id',
            ['id' => $id],
        );
        return $row === null ? null : $this->normaliseRow($row);
    }

    /**
     * Case-insensitive substring search. Household-first ordering, then alphabetical.
     *
     * @return list<array{id: int, name: string, type: string, met: string, default_rom_m: ?string, source: string, household_id: ?int}>
     */
    public function search(?int $householdId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $pattern = '%' . mb_strtolower($escaped) . '%';

        $sql =
            'SELECT id, name, type, met, default_rom_m, source, household_id
             FROM exercises
             WHERE (household_id IS NULL' . ($householdId !== null ? ' OR household_id = :hid' : '') . ')
               AND name_lc LIKE :pattern ESCAPE ' . "'\\'" . '
             ORDER BY (household_id IS NULL) ASC, name ASC
             LIMIT ' . max(1, min($limit, 100));

        $params = ['pattern' => $pattern];
        if ($householdId !== null) {
            $params['hid'] = $householdId;
        }
        $rows = $this->db->fetchAll($sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'type' => (string) $r['type'],
                'met' => (string) $r['met'],
                'default_rom_m' => $r['default_rom_m'] !== null ? (string) $r['default_rom_m'] : null,
                'source' => (string) $r['source'],
                'household_id' => $r['household_id'] !== null ? (int) $r['household_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * @return list<array{id: int, household_id: ?int, name: string, type: string, met: string, default_rom_m: ?string, source: string}>
     */
    public function listForHousehold(?int $householdId, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);
        $sql = 'SELECT id, household_id, name, type, met, default_rom_m, source
                FROM exercises
                WHERE household_id IS NULL' . ($householdId !== null ? ' OR household_id = :hid' : '') . '
                ORDER BY name ASC
                LIMIT ' . $limit . ' OFFSET ' . $offset;
        $params = [];
        if ($householdId !== null) {
            $params['hid'] = $householdId;
        }
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'household_id' => $r['household_id'] !== null ? (int) $r['household_id'] : null,
                'name' => (string) $r['name'],
                'type' => (string) $r['type'],
                'met' => (string) $r['met'],
                'default_rom_m' => $r['default_rom_m'] !== null ? (string) $r['default_rom_m'] : null,
                'source' => (string) $r['source'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row raw DB row
     * @return array{id: int, household_id: ?int, name: string, type: string, met: string, default_rom_m: ?string, source: string, created_by: ?int, created_at: string, updated_at: string}
     */
    private function normaliseRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'household_id' => $row['household_id'] !== null ? (int) $row['household_id'] : null,
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'met' => (string) $row['met'],
            'default_rom_m' => $row['default_rom_m'] !== null ? (string) $row['default_rom_m'] : null,
            'source' => (string) $row['source'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
