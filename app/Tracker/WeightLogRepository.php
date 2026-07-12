<?php

declare(strict_types=1);

namespace App\Tracker;

use Karhu\Db\Connection;

/**
 * v0.8.1 — user weight time series.
 *
 * Measurements are historical facts, not upserts. Latest row per user
 * (ORDER BY measured_on DESC, id DESC LIMIT 1) drives:
 *   - ExerciseLogController's kcal snapshot (duration branch)
 *   - v0.8.2's BMR/TDEE calc (tracker_profiles)
 *
 * weight_kg bounded to [20.0, 300.0] — safety net against typos (e.g., a
 * user entering their kids' weight, or the 20 kg typo of 70 kg case
 * flagged in Plan-agent review).
 *
 * See DOCS.md #71.
 */
final class WeightLogRepository
{
    private const WEIGHT_MIN_KG = 20.0;
    private const WEIGHT_MAX_KG = 300.0;

    public function __construct(private readonly Connection $db) {}

    public function create(int $userId, float $weightKg, string $measuredOn): int
    {
        if ($weightKg < self::WEIGHT_MIN_KG || $weightKg > self::WEIGHT_MAX_KG) {
            throw new \InvalidArgumentException(
                "weight_log.weight_kg must be in [20.0, 300.0]: {$weightKg}",
            );
        }
        return (int) $this->db->fetchScalar(
            'INSERT INTO weight_log (user_id, weight_kg, measured_on)
             VALUES (:uid, :kg, :day)
             RETURNING id',
            ['uid' => $userId, 'kg' => $weightKg, 'day' => $measuredOn],
        );
    }

    /**
     * Latest weight entry for a user, or null if never recorded.
     * ORDER BY (measured_on DESC, id DESC) — id-tiebreak handles multiple
     * entries on the same day (edge case: rapid successive weighings).
     *
     * @return array{id: int, user_id: int, weight_kg: string, measured_on: string, created_at: string}|null
     */
    public function latestForUser(int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, user_id, weight_kg, measured_on, created_at
             FROM weight_log
             WHERE user_id = :uid
             ORDER BY measured_on DESC, id DESC
             LIMIT 1',
            ['uid' => $userId],
        );
        return $row === null ? null : $this->normaliseRow($row);
    }

    /**
     * Recent history — used by /health/weight UI and (future) v0.8.2 chart.
     *
     * @return list<array{id: int, user_id: int, weight_kg: string, measured_on: string, created_at: string}>
     */
    public function listForUser(int $userId, int $limit = 30): array
    {
        $limit = max(1, min($limit, 500));
        $rows = $this->db->fetchAll(
            'SELECT id, user_id, weight_kg, measured_on, created_at
             FROM weight_log
             WHERE user_id = :uid
             ORDER BY measured_on DESC, id DESC
             LIMIT ' . $limit,
            ['uid' => $userId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->normaliseRow($r);
        }
        return $out;
    }

    /** Owner-scoped delete. Returns affected rowcount (0 = not owned or not found). */
    public function deleteOwnedById(int $id, int $userId): int
    {
        return $this->db->run(
            'DELETE FROM weight_log WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, user_id: int, weight_kg: string, measured_on: string, created_at: string}
     */
    private function normaliseRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'weight_kg' => (string) $row['weight_kg'],
            'measured_on' => (string) $row['measured_on'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
