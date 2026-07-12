<?php

declare(strict_types=1);

namespace App\Commands;

use App\Tracker\FoodRepository;
use App\Tracker\FoodServingRepository;
use Karhu\Attributes\Command;
use Karhu\Db\Connection;

/**
 * v0.8.0 — tracker:seed-foods
 *
 * Imports the bundled global food library from `db/seed/tracker_foods.json`
 * into the `foods` + `food_servings` tables. household_id = NULL on every
 * inserted row — these are seeds shared by all households (mishka is
 * single-family in practice; the "share" is future-proofing).
 *
 * Idempotent: driver-detected `INSERT OR IGNORE` (SQLite) /
 * `ON CONFLICT (name, source) WHERE household_id IS NULL DO NOTHING` (PG)
 * against the partial UNIQUE index `idx_foods_seed_unique`. Rerun-safe;
 * a second invocation reports `skipped N` and inserts nothing.
 *
 * JSON schema: `{"version": 1, "foods": [{name, cuisine_tag?, source,
 * aliases?, servings: [{label, grams, kcal, is_default}]}]}`. Unknown
 * version → exit 1 with "unknown seed schema version". See DOCS #70.
 *
 * Wired in CI's deploy job to run AFTER migrate.
 */
final class TrackerSeedFoodsCommand
{
    public function __construct(
        private readonly Connection $db,
        private readonly FoodRepository $foods,
        private readonly FoodServingRepository $servings,
    ) {}

    /** @param array<string, string|true> $args */
    #[Command('tracker:seed-foods', 'Import global tracker food library from db/seed/tracker_foods.json')]
    public function handle(array $args): int
    {
        $cwd = getcwd() ?: __DIR__ . '/../..';
        $path = is_string($args['file'] ?? null) ? (string) $args['file'] : $cwd . '/db/seed/tracker_foods.json';

        if (!is_file($path)) {
            fwrite(\STDERR, "tracker:seed-foods: seed file not found: {$path}\n");
            return 1;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            fwrite(\STDERR, "tracker:seed-foods: could not read seed file: {$path}\n");
            return 1;
        }
        try {
            /** @var array{version?: int, foods?: list<array<string, mixed>>} $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            fwrite(\STDERR, "tracker:seed-foods: seed JSON parse failed: {$e->getMessage()}\n");
            return 1;
        }
        if (!is_array($data) || ($data['version'] ?? null) !== 1) {
            fwrite(\STDERR, "tracker:seed-foods: unknown seed schema version (expected version=1)\n");
            return 1;
        }
        /** @var list<array<string, mixed>> $foodsList */
        $foodsList = $data['foods'] ?? [];
        if ($foodsList === []) {
            fwrite(\STDOUT, "tracker:seed-foods: seeded 0 new dishes, skipped 0 existing (0 servings written)\n");
            return 0;
        }

        $driver = strtolower((string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $seededDishes = 0;
        $skippedDishes = 0;
        $writtenServings = 0;

        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            foreach ($foodsList as $food) {
                $name = trim((string) ($food['name'] ?? ''));
                $source = (string) ($food['source'] ?? 'custom');
                if ($name === '' || !in_array($source, ['philfct', 'nzfcd', 'usda', 'custom'], true)) {
                    fwrite(\STDERR, "tracker:seed-foods: skipping invalid entry (name='{$name}', source='{$source}')\n");
                    continue;
                }
                $foodId = $this->idempotentInsertFood($driver, $name, $source, $food);
                if ($foodId === null) {
                    $skippedDishes++;
                    continue;
                }
                $seededDishes++;
                /** @var list<array<string, mixed>> $servings */
                $servings = $food['servings'] ?? [];
                foreach ($servings as $s) {
                    $this->servings->create($foodId, [
                        'label' => (string) ($s['label'] ?? ''),
                        'grams' => (float) ($s['grams'] ?? 0),
                        'kcal' => (int) ($s['kcal'] ?? 0),
                        'protein_g' => isset($s['protein_g']) ? (float) $s['protein_g'] : null,
                        'carb_g' => isset($s['carb_g']) ? (float) $s['carb_g'] : null,
                        'fat_g' => isset($s['fat_g']) ? (float) $s['fat_g'] : null,
                        'is_default' => (bool) ($s['is_default'] ?? false),
                    ]);
                    $writtenServings++;
                }
            }
            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started) {
                $pdo->rollBack();
            }
            fwrite(\STDERR, "tracker:seed-foods: DB error: {$e->getMessage()}\n");
            return 1;
        }

        fwrite(\STDOUT, "tracker:seed-foods: seeded {$seededDishes} new dishes, skipped {$skippedDishes} existing ({$writtenServings} servings written)\n");
        return 0;
    }

    /**
     * Driver-portable idempotent food insert. Returns the new id when the
     * row was inserted, or null when the partial UNIQUE index skipped it.
     *
     * Critical: SQLite `INSERT OR IGNORE` leaves `lastInsertId()` at the
     * PREVIOUS id on skip. Rowcount is the truth-source; only read the id
     * when rowcount === 1.
     *
     * @param array<string, mixed> $food
     */
    private function idempotentInsertFood(string $driver, string $name, string $source, array $food): ?int
    {
        $aliases = isset($food['aliases']) && $food['aliases'] !== '' ? (string) $food['aliases'] : null;
        $ctag = isset($food['cuisine_tag']) && $food['cuisine_tag'] !== '' ? (string) $food['cuisine_tag'] : null;

        $params = [
            'name' => $name,
            'name_lc' => mb_strtolower($name),
            'aliases' => $aliases,
            'ctag' => $ctag,
            'src' => $source,
        ];

        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO foods (household_id, name, name_lc, aliases, cuisine_tag, source)
                    VALUES (NULL, :name, :name_lc, :aliases, :ctag, :src)
                    ON CONFLICT (name, source) WHERE household_id IS NULL DO NOTHING
                    RETURNING id';
            $id = $this->db->fetchScalar($sql, $params);
            return $id === false || $id === null ? null : (int) $id;
        }

        // SQLite path: rowcount-gated. `INSERT OR IGNORE` doesn't need the
        // partial-index predicate — the index catches the conflict itself.
        $rows = $this->db->run(
            'INSERT OR IGNORE INTO foods (household_id, name, name_lc, aliases, cuisine_tag, source)
             VALUES (NULL, :name, :name_lc, :aliases, :ctag, :src)',
            $params,
        );
        if ($rows !== 1) {
            return null;
        }
        return (int) $this->db->pdo()->lastInsertId();
    }
}
