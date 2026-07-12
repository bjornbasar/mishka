<?php

declare(strict_types=1);

namespace App\Tracker;

use Karhu\Db\Connection;

/**
 * v0.8.0 — food_servings CRUD + at-most-one-default invariant.
 *
 * A food may have many servings; exactly one carries is_default = TRUE
 * (partial UNIQUE index idx_food_servings_default_unique enforces
 * at-most-one; the repo's demote-then-promote transaction ensures
 * exactly-one when a caller sets is_default via create/update).
 *
 * Servings CASCADE-delete with their parent food. The plan invariant
 * "at least one serving per food" is enforced at the CONTROLLER layer
 * (FoodLibraryController rejects zero-servings on create/update); this
 * repo can express it (delete of the sole default is allowed at repo
 * level, controller MUST prevent) — the search endpoint's INNER JOIN
 * hides default-less dishes so the failure mode is "dish disappears
 * from search" rather than "app crashes".
 */
final class FoodServingRepository
{
    private const WRITABLE_COLUMNS = ['label', 'grams', 'kcal', 'protein_g', 'carb_g', 'fat_g', 'is_default'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, mixed> $data
     *   Expected keys: label (string), grams (numeric), kcal (int), plus optional
     *   protein_g/carb_g/fat_g (numeric-or-null) and is_default (bool).
     */
    public function create(int $foodId, array $data): int
    {
        $label = trim((string) $data['label']);
        if ($label === '') {
            throw new \InvalidArgumentException('food_servings.label must be non-empty');
        }
        $grams = (float) ($data['grams'] ?? 0);
        if ($grams <= 0) {
            throw new \InvalidArgumentException('food_servings.grams must be positive');
        }
        $kcal = (int) ($data['kcal'] ?? 0);
        if ($kcal < 0) {
            throw new \InvalidArgumentException('food_servings.kcal must be >= 0');
        }
        $isDefault = (bool) ($data['is_default'] ?? false);

        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            if ($isDefault) {
                $this->demoteCurrentDefault($foodId);
            }
            // is_default is a BOOLEAN column — PG rejects integer→boolean
            // implicit cast, and PHP-bool→PDO binding is driver-inconsistent.
            // Emit TRUE/FALSE as a SQL literal (portable per PG + SQLite 3.23+).
            $defaultLiteral = $isDefault ? 'TRUE' : 'FALSE';
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO food_servings
                    (food_id, label, grams, kcal, protein_g, carb_g, fat_g, is_default)
                 VALUES
                    (:fid, :label, :grams, :kcal, :pg, :cg, :fg, ' . $defaultLiteral . ')
                 RETURNING id',
                [
                    'fid' => $foodId,
                    'label' => $label,
                    'grams' => $grams,
                    'kcal' => $kcal,
                    'pg' => isset($data['protein_g']) ? (float) $data['protein_g'] : null,
                    'cg' => isset($data['carb_g']) ? (float) $data['carb_g'] : null,
                    'fg' => isset($data['fat_g']) ? (float) $data['fat_g'] : null,
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
     * @param array<string, mixed> $data whitelist filtered
     */
    public function update(int $id, array $data): void
    {
        $filtered = array_intersect_key($data, array_flip(self::WRITABLE_COLUMNS));
        if ($filtered === []) {
            return;
        }
        $promotingToDefault = isset($filtered['is_default']) && (bool) $filtered['is_default'];

        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            if ($promotingToDefault) {
                // Find the food_id, demote all others, then let the UPDATE
                // below promote this row.
                $foodId = (int) $this->db->fetchScalar(
                    'SELECT food_id FROM food_servings WHERE id = :id',
                    ['id' => $id],
                );
                if ($foodId > 0) {
                    $this->demoteCurrentDefault($foodId, exceptId: $id);
                }
            }
            // is_default gets emitted as a SQL literal (TRUE/FALSE) rather
            // than parameterised — PG BOOLEAN column rejects integer coercion
            // and PHP-bool→PDO binding is driver-inconsistent. Pop it out of
            // the param bag and add it to the SET list directly.
            $isDefaultLiteral = null;
            if (array_key_exists('is_default', $filtered)) {
                $isDefaultLiteral = (bool) $filtered['is_default'] ? 'TRUE' : 'FALSE';
                unset($filtered['is_default']);
            }
            $sets = [];
            foreach (array_keys($filtered) as $col) {
                $sets[] = "{$col} = :{$col}";
            }
            if ($isDefaultLiteral !== null) {
                $sets[] = 'is_default = ' . $isDefaultLiteral;
            }
            if ($sets === []) {
                if ($started) {
                    $pdo->commit();
                }
                return;
            }
            $filtered['id'] = $id;
            $this->db->run(
                'UPDATE food_servings SET ' . implode(', ', $sets) . ' WHERE id = :id',
                $filtered,
            );
            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM food_servings WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return list<array{id: int, food_id: int, label: string, grams: string, kcal: int, protein_g: ?string, carb_g: ?string, fat_g: ?string, is_default: bool}>
     */
    public function listForFood(int $foodId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, food_id, label, grams, kcal, protein_g, carb_g, fat_g, is_default
             FROM food_servings WHERE food_id = :fid
             ORDER BY is_default DESC, id ASC',
            ['fid' => $foodId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->normaliseRow($r);
        }
        return $out;
    }

    /**
     * @return array{id: int, food_id: int, label: string, grams: string, kcal: int, protein_g: ?string, carb_g: ?string, fat_g: ?string, is_default: bool}|null
     */
    public function defaultForFood(int $foodId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, food_id, label, grams, kcal, protein_g, carb_g, fat_g, is_default
             FROM food_servings WHERE food_id = :fid AND is_default = TRUE',
            ['fid' => $foodId],
        );
        return $row === null ? null : $this->normaliseRow($row);
    }

    /**
     * @return array{id: int, food_id: int, label: string, grams: string, kcal: int, protein_g: ?string, carb_g: ?string, fat_g: ?string, is_default: bool}|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, food_id, label, grams, kcal, protein_g, carb_g, fat_g, is_default
             FROM food_servings WHERE id = :id',
            ['id' => $id],
        );
        return $row === null ? null : $this->normaliseRow($row);
    }

    /**
     * Demotes the current default serving on $foodId (if any), optionally
     * except for row id $exceptId (used by the update path where we're
     * about to promote $exceptId itself).
     */
    private function demoteCurrentDefault(int $foodId, ?int $exceptId = null): void
    {
        $sql = 'UPDATE food_servings SET is_default = FALSE
                WHERE food_id = :fid AND is_default = TRUE';
        $params = ['fid' => $foodId];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :exc';
            $params['exc'] = $exceptId;
        }
        $this->db->run($sql, $params);
    }

    /**
     * @param array<string, mixed> $row raw DB row
     * @return array{id: int, food_id: int, label: string, grams: string, kcal: int, protein_g: ?string, carb_g: ?string, fat_g: ?string, is_default: bool}
     */
    private function normaliseRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'food_id' => (int) $row['food_id'],
            'label' => (string) $row['label'],
            'grams' => (string) $row['grams'],
            'kcal' => (int) $row['kcal'],
            'protein_g' => $row['protein_g'] !== null ? (string) $row['protein_g'] : null,
            'carb_g' => $row['carb_g'] !== null ? (string) $row['carb_g'] : null,
            'fat_g' => $row['fat_g'] !== null ? (string) $row['fat_g'] : null,
            'is_default' => (bool) (int) $row['is_default'],
        ];
    }
}
