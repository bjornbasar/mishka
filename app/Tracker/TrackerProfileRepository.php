<?php

declare(strict_types=1);

namespace App\Tracker;

use Karhu\Db\Connection;

/**
 * v0.8.2 — per-user body profile for BMR/TDEE calc.
 *
 * One row per user (PK on user_id — upsert via `ON CONFLICT (user_id)
 * DO UPDATE`). Feeds `TrackerController::today`'s energy-balance widget
 * alongside food_log + exercise_log + weight_log.
 *
 * Repo owns all bounds validation (rejects with InvalidArgumentException):
 *   - sex ∈ {male, female}
 *   - birth_year ∈ [1900, currentYear - 5]  (family-scale; catches fat-finger typos)
 *   - height_cm ∈ [50.0, 250.0]
 *   - base_activity ∈ [1.0, 2.5]
 *
 * UPSERT uses ON CONFLICT DO UPDATE — three existing sites use this on
 * both PG and SQLite ≥ 3.24: UserPasswordChangeRepository:61,
 * UserNotificationPrefsRepository:110, PushSubscriptionRepository:44.
 * CI runners ship SQLite 3.37+; no driver-branch needed.
 *
 * INSERT clause OMITS updated_at (schema DEFAULT NOW() covers first
 * write). ON CONFLICT clause explicitly writes CURRENT_TIMESTAMP on the
 * update path. Mirrors UserPasswordChangeRepository::stamp idiom.
 *
 * See DOCS.md #72.
 */
final class TrackerProfileRepository
{
    private const HEIGHT_MIN = 50.0;
    private const HEIGHT_MAX = 250.0;
    private const BASE_ACTIVITY_MIN = 1.0;
    private const BASE_ACTIVITY_MAX = 2.5;
    private const BIRTH_YEAR_MIN = 1900;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{user_id: int, sex: string, birth_year: int, height_cm: string, base_activity: string, created_at: string, updated_at: string}|null
     */
    public function findByUserId(int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT user_id, sex, birth_year, height_cm, base_activity, created_at, updated_at
             FROM tracker_profiles WHERE user_id = :uid',
            ['uid' => $userId],
        );
        return $row === null ? null : $this->normaliseRow($row);
    }

    /**
     * Upsert semantics (single query on PG + SQLite 3.24+).
     *
     * @param array{sex: string, birth_year: int, height_cm: float, base_activity: float} $data
     */
    public function upsert(int $userId, array $data): void
    {
        $sex = (string) $data['sex'];
        if (!in_array($sex, ['male', 'female'], true)) {
            throw new \InvalidArgumentException("tracker_profiles.sex invalid: {$sex}");
        }
        $birthYear = (int) $data['birth_year'];
        $currentYear = (int) date('Y');
        if ($birthYear < self::BIRTH_YEAR_MIN || $birthYear > $currentYear - 5) {
            throw new \InvalidArgumentException(
                "tracker_profiles.birth_year must be in [" . self::BIRTH_YEAR_MIN . ", " . ($currentYear - 5) . "]: {$birthYear}",
            );
        }
        $heightCm = (float) $data['height_cm'];
        if ($heightCm < self::HEIGHT_MIN || $heightCm > self::HEIGHT_MAX) {
            throw new \InvalidArgumentException(
                "tracker_profiles.height_cm must be in [" . self::HEIGHT_MIN . ", " . self::HEIGHT_MAX . "]: {$heightCm}",
            );
        }
        $baseActivity = (float) $data['base_activity'];
        if ($baseActivity < self::BASE_ACTIVITY_MIN || $baseActivity > self::BASE_ACTIVITY_MAX) {
            throw new \InvalidArgumentException(
                "tracker_profiles.base_activity must be in [" . self::BASE_ACTIVITY_MIN . ", " . self::BASE_ACTIVITY_MAX . "]: {$baseActivity}",
            );
        }

        $this->db->run(
            'INSERT INTO tracker_profiles (user_id, sex, birth_year, height_cm, base_activity)
             VALUES (:uid, :sex, :by, :h, :ba)
             ON CONFLICT (user_id) DO UPDATE SET
                sex = EXCLUDED.sex,
                birth_year = EXCLUDED.birth_year,
                height_cm = EXCLUDED.height_cm,
                base_activity = EXCLUDED.base_activity,
                updated_at = CURRENT_TIMESTAMP',
            [
                'uid' => $userId,
                'sex' => $sex,
                'by' => $birthYear,
                'h' => $heightCm,
                'ba' => $baseActivity,
            ],
        );
    }

    /** Explicit delete method for symmetry — CASCADE via user delete already handles this at DB level. */
    public function delete(int $userId): void
    {
        $this->db->run('DELETE FROM tracker_profiles WHERE user_id = :uid', ['uid' => $userId]);
    }

    /**
     * @param array<string, mixed> $row raw DB row
     * @return array{user_id: int, sex: string, birth_year: int, height_cm: string, base_activity: string, created_at: string, updated_at: string}
     */
    private function normaliseRow(array $row): array
    {
        return [
            'user_id' => (int) $row['user_id'],
            'sex' => (string) $row['sex'],
            'birth_year' => (int) $row['birth_year'],
            'height_cm' => (string) $row['height_cm'],
            'base_activity' => (string) $row['base_activity'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
