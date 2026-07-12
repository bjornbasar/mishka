<?php

declare(strict_types=1);

namespace App\Tracker;

use Karhu\Db\Connection;

/**
 * v0.8.1 — exercise_log write + read.
 *
 * Discriminated union by exercise_type_snapshot:
 *   duration branch: minutes populated; sets/reps/load_kg NULL
 *   strength branch: sets/reps/load_kg populated; minutes NULL
 *
 * NO derivation between branches (user-locked at plan-time). Storage
 * mirrors what the user entered — no set-rep → minutes conversion.
 *
 * Snapshot columns (exercise_name_snapshot + exercise_type_snapshot)
 * preserve context if the parent exercise is renamed or deleted.
 * Deliberate divergence from food_log's LEFT JOIN + COALESCE pattern —
 * see DOCS.md #71 for rationale.
 *
 * Repo validates branch-required fields (belt-and-braces layer on top
 * of the CONTROLLER's user-facing validation).
 */
final class ExerciseLogRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Create a log entry. Caller determines the branch by which optional
     * fields are non-null. Repo validates:
     *   - $exerciseType ∈ ('duration','strength')
     *   - Duration: $minutes > 0; sets/reps/load_kg must be null.
     *   - Strength: $sets > 0 AND $reps > 0; minutes must be null.
     *   - met_minutes NULL for strength (enforced by caller — repo doesn't
     *     recompute).
     */
    public function create(
        int $householdId,
        int $userId,
        ?int $exerciseId,
        string $exerciseType,
        string $exerciseName,
        ?float $minutes,
        ?int $sets,
        ?int $reps,
        ?float $loadKg,
        ?float $metMinutes,
        ?int $kcalSnapshot,
        string $loggedOn,
    ): int {
        if (!in_array($exerciseType, ['duration', 'strength'], true)) {
            throw new \InvalidArgumentException("exercise_log.exercise_type_snapshot invalid: {$exerciseType}");
        }
        if ($exerciseType === 'duration') {
            if ($minutes === null || $minutes <= 0) {
                throw new \InvalidArgumentException('exercise_log duration branch requires minutes > 0');
            }
            if ($sets !== null || $reps !== null || $loadKg !== null) {
                throw new \InvalidArgumentException('exercise_log duration branch: sets/reps/load_kg must be null');
            }
        } else {
            if ($sets === null || $sets <= 0 || $reps === null || $reps <= 0) {
                throw new \InvalidArgumentException('exercise_log strength branch requires sets > 0 AND reps > 0');
            }
            if ($minutes !== null) {
                throw new \InvalidArgumentException('exercise_log strength branch: minutes must be null');
            }
            if ($metMinutes !== null) {
                throw new \InvalidArgumentException('exercise_log strength branch: met_minutes must be null');
            }
        }
        if (trim($exerciseName) === '') {
            throw new \InvalidArgumentException('exercise_log.exercise_name_snapshot must be non-empty');
        }

        return (int) $this->db->fetchScalar(
            'INSERT INTO exercise_log
                (household_id, user_id, exercise_id,
                 minutes, sets, reps, load_kg,
                 exercise_name_snapshot, exercise_type_snapshot,
                 met_minutes, kcal_snapshot, logged_on)
             VALUES
                (:hid, :uid, :eid,
                 :minutes, :sets, :reps, :load,
                 :ename, :etype,
                 :mm, :kcal, :day)
             RETURNING id',
            [
                'hid' => $householdId,
                'uid' => $userId,
                'eid' => $exerciseId,
                'minutes' => $minutes,
                'sets' => $sets,
                'reps' => $reps,
                'load' => $loadKg,
                'ename' => trim($exerciseName),
                'etype' => $exerciseType,
                'mm' => $metMinutes,
                'kcal' => $kcalSnapshot,
                'day' => $loggedOn,
            ],
        );
    }

    /**
     * Exercise entries for one user in one household on one calendar day.
     * No JOIN needed — snapshots carry name + type. ORDER BY logged_at.
     *
     * @return list<array{
     *     id: int, exercise_id: ?int, exercise_name: string, exercise_type: string,
     *     minutes: ?string, sets: ?int, reps: ?int, load_kg: ?string,
     *     met_minutes: ?string, kcal_snapshot: ?int, logged_at: string
     * }>
     */
    public function listForUserDay(int $userId, int $householdId, string $loggedOn): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, exercise_id,
                    exercise_name_snapshot AS exercise_name,
                    exercise_type_snapshot AS exercise_type,
                    minutes, sets, reps, load_kg,
                    met_minutes, kcal_snapshot, logged_at
             FROM exercise_log
             WHERE user_id = :uid AND household_id = :hid AND logged_on = :day
             ORDER BY logged_at ASC',
            ['uid' => $userId, 'hid' => $householdId, 'day' => $loggedOn],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'exercise_id' => $r['exercise_id'] !== null ? (int) $r['exercise_id'] : null,
                'exercise_name' => (string) $r['exercise_name'],
                'exercise_type' => (string) $r['exercise_type'],
                'minutes' => $r['minutes'] !== null ? (string) $r['minutes'] : null,
                'sets' => $r['sets'] !== null ? (int) $r['sets'] : null,
                'reps' => $r['reps'] !== null ? (int) $r['reps'] : null,
                'load_kg' => $r['load_kg'] !== null ? (string) $r['load_kg'] : null,
                'met_minutes' => $r['met_minutes'] !== null ? (string) $r['met_minutes'] : null,
                'kcal_snapshot' => $r['kcal_snapshot'] !== null ? (int) $r['kcal_snapshot'] : null,
                'logged_at' => (string) $r['logged_at'],
            ];
        }
        return $out;
    }

    /**
     * Aggregate daily totals per user for the household on one day.
     * Fuels v0.8.3 leaderboard. met_minutes is NULL for strength — SUM
     * treats NULLs as zero, so this returns duration-only contribution.
     * Strength participation may need a separate signal in v0.8.3.
     *
     * @return array<int, array{user_id: int, total_met_minutes: string, total_kcal: int, entries: int}>
     */
    public function dailyTotalsForHousehold(int $householdId, string $loggedOn): array
    {
        $rows = $this->db->fetchAll(
            'SELECT user_id,
                    COALESCE(SUM(met_minutes), 0) AS total_met_minutes,
                    COALESCE(SUM(kcal_snapshot), 0) AS total_kcal,
                    COUNT(*) AS entries
             FROM exercise_log
             WHERE household_id = :hid AND logged_on = :day
             GROUP BY user_id',
            ['hid' => $householdId, 'day' => $loggedOn],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = [
                'user_id' => (int) $r['user_id'],
                'total_met_minutes' => (string) $r['total_met_minutes'],
                'total_kcal' => (int) $r['total_kcal'],
                'entries' => (int) $r['entries'],
            ];
        }
        return $out;
    }

    /** Owner-scoped delete. Returns affected rowcount (0 = not owned or not found). */
    public function deleteOwnedById(int $id, int $userId): int
    {
        return $this->db->run(
            'DELETE FROM exercise_log WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId],
        );
    }
}
