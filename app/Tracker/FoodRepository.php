<?php

declare(strict_types=1);

namespace App\Tracker;

use Karhu\Db\Connection;

/**
 * v0.8.0 — dish library CRUD + case-insensitive live search.
 *
 * `foods` rows are either GLOBAL (household_id IS NULL, seeded via
 * `bin/karhu tracker:seed-foods`) or HOUSEHOLD-SCOPED (household_id NOT
 * NULL, source='custom', added via the library UI). search() + list()
 * return the union scoped to the caller's household.
 *
 * `name_lc` is repo-owned: every create/update writes
 * mb_strtolower($data['name']) alongside `name`. Callers MUST NOT set
 * $data['name_lc'] — the repo silently overwrites it. Search hits the
 * idx_foods_name_lc index and uses `LIKE :pattern ESCAPE '\'`, so
 * `%`/`_`/`\` in user input must be pre-escaped in PHP (search() handles
 * this). See DOCS #70.
 */
final class FoodRepository
{
    private const WRITABLE_COLUMNS = ['name', 'aliases', 'cuisine_tag', 'source'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{
     *     name: string,
     *     aliases?: ?string,
     *     cuisine_tag?: ?string,
     *     source?: string,     // defaults to 'custom' when omitted
     * } $data
     */
    public function create(?int $householdId, array $data, ?int $createdBy): int
    {
        $name = trim((string) $data['name']);
        if ($name === '') {
            throw new \InvalidArgumentException('foods.name must be non-empty');
        }
        $source = (string) ($data['source'] ?? 'custom');
        if (!in_array($source, ['philfct', 'nzfcd', 'usda', 'custom'], true)) {
            throw new \InvalidArgumentException("foods.source invalid: {$source}");
        }

        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO foods
                    (household_id, name, name_lc, aliases, cuisine_tag, source, created_by)
                 VALUES
                    (:hid, :name, :name_lc, :aliases, :ctag, :src, :cby)
                 RETURNING id',
                [
                    'hid' => $householdId,
                    'name' => $name,
                    // Repo-owned: overrides any caller-supplied name_lc.
                    'name_lc' => mb_strtolower($name),
                    'aliases' => isset($data['aliases']) && $data['aliases'] !== '' ? (string) $data['aliases'] : null,
                    'ctag' => isset($data['cuisine_tag']) && $data['cuisine_tag'] !== '' ? (string) $data['cuisine_tag'] : null,
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

    /**
     * @param array<string, mixed> $data Whitelisted subset of WRITABLE_COLUMNS.
     */
    public function update(int $id, array $data): void
    {
        // Filter to writable-columns; also inject repo-owned name_lc if `name` is present.
        $filtered = array_intersect_key($data, array_flip(self::WRITABLE_COLUMNS));
        if (isset($filtered['name'])) {
            $filtered['name'] = trim((string) $filtered['name']);
            if ($filtered['name'] === '') {
                throw new \InvalidArgumentException('foods.name must be non-empty');
            }
            $filtered['name_lc'] = mb_strtolower($filtered['name']);
        }
        if (isset($filtered['source']) && !in_array((string) $filtered['source'], ['philfct', 'nzfcd', 'usda', 'custom'], true)) {
            throw new \InvalidArgumentException("foods.source invalid: {$filtered['source']}");
        }
        if ($filtered === []) {
            return;
        }
        // Always bump updated_at. Matches ChoreRepository:187.
        $sets = [];
        foreach (array_keys($filtered) as $col) {
            $sets[] = "{$col} = :{$col}";
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $filtered['id'] = $id;
        $this->db->run(
            'UPDATE foods SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $filtered,
        );
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM foods WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array{id: int, household_id: ?int, name: string, aliases: ?string, cuisine_tag: ?string, source: string, created_by: ?int, created_at: string, updated_at: string}|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, household_id, name, aliases, cuisine_tag, source, created_by, created_at, updated_at
             FROM foods WHERE id = :id',
            ['id' => $id],
        );
        return $row === null ? null : $this->normaliseRow($row);
    }

    /**
     * Case-insensitive substring search over `name_lc`. Returns UP TO $limit dishes
     * whose default serving exists (INNER JOIN — dishes without a default are hidden
     * because they're un-loggable via the fast path). Ordered household-first,
     * then alphabetical.
     *
     * @return list<array{id: int, name: string, cuisine_tag: ?string, source: string, default_serving_id: int, default_serving_label: string, default_serving_kcal: int, default_serving_grams: string}>
     */
    public function search(?int $householdId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        // Escape LIKE special chars in user input before pattern-wrapping.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $pattern = '%' . mb_strtolower($escaped) . '%';

        $sql =
            'SELECT f.id, f.name, f.cuisine_tag, f.source, f.household_id,
                    s.id AS default_serving_id, s.label AS default_serving_label,
                    s.kcal AS default_serving_kcal, s.grams AS default_serving_grams
             FROM foods f
             INNER JOIN food_servings s ON s.food_id = f.id AND s.is_default = ' . $this->trueLiteral() . '
             WHERE (f.household_id IS NULL' . ($householdId !== null ? ' OR f.household_id = :hid' : '') . ')
               AND f.name_lc LIKE :pattern ESCAPE ' . "'\\'" . '
             ORDER BY (f.household_id IS NULL) ASC, f.name ASC
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
                'cuisine_tag' => $r['cuisine_tag'] !== null ? (string) $r['cuisine_tag'] : null,
                'source' => (string) $r['source'],
                'default_serving_id' => (int) $r['default_serving_id'],
                'default_serving_label' => (string) $r['default_serving_label'],
                'default_serving_kcal' => (int) $r['default_serving_kcal'],
                'default_serving_grams' => (string) $r['default_serving_grams'],
            ];
        }
        return $out;
    }

    /**
     * Library browse — global seed rows + household-added rows, ordered alphabetically.
     *
     * @return list<array{id: int, household_id: ?int, name: string, cuisine_tag: ?string, source: string}>
     */
    public function listForHousehold(?int $householdId, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);
        $sql = 'SELECT id, household_id, name, cuisine_tag, source
                FROM foods
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
                'cuisine_tag' => $r['cuisine_tag'] !== null ? (string) $r['cuisine_tag'] : null,
                'source' => (string) $r['source'],
            ];
        }
        return $out;
    }

    /**
     * SQLite stores booleans as 0/1 integers; PG has TRUE/FALSE literals. Both
     * accept `1` — using it uniformly avoids driver-branching in the query
     * string.
     */
    private function trueLiteral(): string
    {
        return '1';
    }

    /**
     * @param array<string, mixed> $row raw DB row
     * @return array{id: int, household_id: ?int, name: string, aliases: ?string, cuisine_tag: ?string, source: string, created_by: ?int, created_at: string, updated_at: string}
     */
    private function normaliseRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'household_id' => $row['household_id'] !== null ? (int) $row['household_id'] : null,
            'name' => (string) $row['name'],
            'aliases' => $row['aliases'] !== null ? (string) $row['aliases'] : null,
            'cuisine_tag' => $row['cuisine_tag'] !== null ? (string) $row['cuisine_tag'] : null,
            'source' => (string) $row['source'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
