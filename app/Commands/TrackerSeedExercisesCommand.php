<?php

declare(strict_types=1);

namespace App\Commands;

use Karhu\Attributes\Command;
use Karhu\Db\Connection;

/**
 * v0.8.1 — tracker:seed-exercises
 *
 * Imports the bundled global exercise catalog from
 * `db/seed/tracker_exercises.json` into the `exercises` table.
 * household_id = NULL on every row — these are seeds shared across all
 * households (mishka is single-family in practice; the "share" is future-
 * proofing).
 *
 * Idempotent: driver-detected `INSERT OR IGNORE` (SQLite) /
 * `ON CONFLICT (name, source) WHERE household_id IS NULL DO NOTHING` (PG)
 * against the partial UNIQUE index `idx_exercises_seed_unique`. Rerun-safe.
 *
 * JSON schema: `{"version": 1, "exercises": [{name, type, met,
 * default_rom_m?, source}]}`. Unknown version → exit 1.
 *
 * Ctor takes ONLY `Connection` — exercises have no children analogous to
 * food_servings (foods+servings needed FoodServingRepository in v0.8.0's
 * seed cmd; exercises don't).
 *
 * See DOCS.md #71.
 */
final class TrackerSeedExercisesCommand
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /** @param array<string, string|true> $args */
    #[Command('tracker:seed-exercises', 'Import global tracker exercise catalog from db/seed/tracker_exercises.json')]
    public function handle(array $args): int
    {
        $cwd = getcwd() ?: __DIR__ . '/../..';
        $path = is_string($args['file'] ?? null) ? (string) $args['file'] : $cwd . '/db/seed/tracker_exercises.json';

        if (!is_file($path)) {
            fwrite(\STDERR, "tracker:seed-exercises: seed file not found: {$path}\n");
            return 1;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            fwrite(\STDERR, "tracker:seed-exercises: could not read seed file: {$path}\n");
            return 1;
        }
        try {
            /** @var array{version?: int, exercises?: list<array<string, mixed>>} $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            fwrite(\STDERR, "tracker:seed-exercises: seed JSON parse failed: {$e->getMessage()}\n");
            return 1;
        }
        if (($data['version'] ?? null) !== 1) {
            fwrite(\STDERR, "tracker:seed-exercises: unknown seed schema version (expected version=1)\n");
            return 1;
        }
        /** @var list<array<string, mixed>> $list */
        $list = $data['exercises'] ?? [];
        if ($list === []) {
            fwrite(\STDOUT, "tracker:seed-exercises: seeded 0 new exercises, skipped 0 existing\n");
            return 0;
        }

        $driver = strtolower((string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $seeded = 0;
        $skipped = 0;

        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            foreach ($list as $ex) {
                $name = trim((string) ($ex['name'] ?? ''));
                $source = (string) ($ex['source'] ?? 'custom');
                $type = (string) ($ex['type'] ?? '');
                if ($name === ''
                    || !in_array($type, ['duration', 'strength'], true)
                    || !in_array($source, ['compendium', 'custom'], true)) {
                    fwrite(\STDERR, "tracker:seed-exercises: skipping invalid entry (name='{$name}', type='{$type}', source='{$source}')\n");
                    continue;
                }
                if ($this->idempotentInsert($driver, $name, $type, $source, $ex)) {
                    $seeded++;
                } else {
                    $skipped++;
                }
            }
            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started) {
                $pdo->rollBack();
            }
            fwrite(\STDERR, "tracker:seed-exercises: DB error: {$e->getMessage()}\n");
            return 1;
        }

        fwrite(\STDOUT, "tracker:seed-exercises: seeded {$seeded} new exercises, skipped {$skipped} existing\n");
        return 0;
    }

    /**
     * Driver-portable idempotent insert. Returns true when a row was
     * inserted, false when the partial UNIQUE index skipped it.
     *
     * @param array<string, mixed> $ex
     */
    private function idempotentInsert(string $driver, string $name, string $type, string $source, array $ex): bool
    {
        $met = (float) ($ex['met'] ?? 0);
        $rom = array_key_exists('default_rom_m', $ex) && $ex['default_rom_m'] !== null ? (float) $ex['default_rom_m'] : null;

        $params = [
            'name' => $name,
            'name_lc' => mb_strtolower($name),
            'type' => $type,
            'met' => $met,
            'rom' => $rom,
            'src' => $source,
        ];

        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO exercises (household_id, name, name_lc, type, met, default_rom_m, source)
                    VALUES (NULL, :name, :name_lc, :type, :met, :rom, :src)
                    ON CONFLICT (name, source) WHERE household_id IS NULL DO NOTHING
                    RETURNING id';
            $id = $this->db->fetchScalar($sql, $params);
            return $id !== false && $id !== null;
        }

        // SQLite: rowcount-gated (INSERT OR IGNORE leaves lastInsertId at
        // the PREVIOUS id when the row was skipped — v0.8.0's #7 pattern).
        $rows = $this->db->run(
            'INSERT OR IGNORE INTO exercises (household_id, name, name_lc, type, met, default_rom_m, source)
             VALUES (NULL, :name, :name_lc, :type, :met, :rom, :src)',
            $params,
        );
        return $rows === 1;
    }
}
