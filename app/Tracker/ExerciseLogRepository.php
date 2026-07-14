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
     * v0.8.2 — per-user exercise kcal for the Today energy-balance
     * widget. Strict user + household + day scoping (privacy invariant
     * per TRACKER-PLAN.md §5). kcal_snapshot IS nullable on exercise_log
     * (strength without ROM; duration without user weight) —
     * COALESCE(NULL, 0) treats those as 0 contribution to expenditure.
     */
    public function exerciseKcalForUserDay(int $userId, int $householdId, string $loggedOn): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COALESCE(SUM(kcal_snapshot), 0)
             FROM exercise_log
             WHERE user_id = :uid AND household_id = :hid AND logged_on = :day',
            ['uid' => $userId, 'hid' => $householdId, 'day' => $loggedOn],
        );
    }

    /**
     * Aggregate daily totals per user for the household on one day.
     * Fuels v0.8.3 leaderboard. met_minutes is NULL for strength — SUM
     * treats NULLs as zero, so this returns duration-only contribution.
     * v0.8.2's Today widget uses `exerciseKcalForUserDay` instead
     * (per-user scoping to avoid loading other users' rows).
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

    /**
     * v0.8.3 — weekly effort leaderboard, one row per household member.
     *
     * Mirrors `ChoreRepository::leaderboardForHousehold`'s roster JOIN
     * (household_members → users u; `u.id > 0` sentinel guard). LEFT JOIN
     * onto `exercise_log` with the DATE half-open interval baked into the
     * ON clause — this returns cleaner SQL than SUM(CASE WHEN ...) since
     * we're aggregating ONLY weekly totals (no lifetime column to preserve).
     *
     * met_minutes is NULL for strength — SUM treats NULLs as zero (COALESCE
     * is defensive). Strength contribution surfaces via the separate
     * `week_strength_sessions` COUNT column (session-count sidecar
     * per DOCS #73).
     *
     * `week_entries` is week-filtered (NOT lifetime) — used as tiebreak
     * beneath `week_met_minutes` in ORDER BY.
     *
     * @return list<array{
     *     user_id: int, display_name: string, email: string,
     *     week_met_minutes: string,
     *     week_strength_sessions: int,
     *     week_entries: int,
     * }>
     */
    public function weeklyLeaderboardForHousehold(
        int $householdId,
        string $weekStartLocal,
        string $weekEndLocal,
    ): array {
        $rows = $this->db->fetchAll(
            "SELECT u.id AS user_id, u.display_name, u.email,
                    COALESCE(SUM(e.met_minutes), 0) AS week_met_minutes,
                    COALESCE(SUM(CASE WHEN e.exercise_type_snapshot = 'strength' THEN 1 ELSE 0 END), 0) AS week_strength_sessions,
                    COUNT(e.id) AS week_entries
             FROM household_members m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN exercise_log e
                    ON e.household_id = m.household_id
                   AND e.user_id = u.id
                   AND e.logged_on >= :ws
                   AND e.logged_on < :we
             WHERE m.household_id = :hid AND u.id > 0
             GROUP BY u.id, u.display_name, u.email
             ORDER BY week_met_minutes DESC, week_entries DESC, MIN(m.joined_at) ASC",
            ['hid' => $householdId, 'ws' => $weekStartLocal, 'we' => $weekEndLocal],
        );
        return array_map(
            fn(array $r): array => [
                'user_id' => (int) $r['user_id'],
                'display_name' => (string) $r['display_name'],
                'email' => (string) $r['email'],
                'week_met_minutes' => (string) $r['week_met_minutes'],
                'week_strength_sessions' => (int) $r['week_strength_sessions'],
                'week_entries' => (int) $r['week_entries'],
            ],
            $rows,
        );
    }

    /**
     * v0.8.3 — lifetime cumulative stats for one user in one household. Fuels
     * TrackerBadgeAwarder's count + MET-minute threshold checks. One
     * round-trip covers both figures (Plan-agent finding #13 fold —
     * awarder consumes both from a single SELECT).
     *
     * @return array{count: int, total_met_minutes: int}
     */
    public function cumulativeStatsForUser(int $userId, int $householdId): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS n, COALESCE(SUM(met_minutes), 0) AS m
             FROM exercise_log
             WHERE user_id = :uid AND household_id = :hid',
            ['uid' => $userId, 'hid' => $householdId],
        );
        return [
            'count' => (int) ($row['n'] ?? 0),
            'total_met_minutes' => (int) round((float) ($row['m'] ?? 0)),
        ];
    }

    /**
     * v0.8.3 — batched daily-activity feed for the leaderboard's streak column.
     * Returns each household member's `logged_on` DATE strings over the given
     * half-open interval, keyed by user_id. Mirrors
     * `ChoreRepository::recentCompletionsForHousehold` shape.
     *
     * Departed members drop via the `household_members` JOIN. Empty list not
     * returned for members with zero entries (caller does `?? []` on lookup).
     *
     * @return array<int, list<string>>  user_id → `Y-m-d` strings, DESC
     */
    public function recentLoggedOnsForHousehold(
        int $householdId,
        string $sinceLocal,
        string $endLocal,
    ): array {
        $rows = $this->db->fetchAll(
            'SELECT e.user_id, e.logged_on
             FROM exercise_log e
             JOIN household_members m
               ON m.household_id = e.household_id AND m.user_id = e.user_id
             WHERE e.household_id = :hid AND e.logged_on >= :since AND e.logged_on < :end
             ORDER BY e.logged_on DESC',
            ['hid' => $householdId, 'since' => $sinceLocal, 'end' => $endLocal],
        );
        $out = [];
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            $out[$uid] ??= [];
            $out[$uid][] = (string) $r['logged_on'];
        }
        return $out;
    }

    /**
     * v0.8.3 — per-user variant of `recentLoggedOnsForHousehold`, used by the
     * awarder (single user under evaluation, no household-wide GROUP needed).
     *
     * @return list<string>  `Y-m-d` strings, DESC
     */
    public function recentLoggedOnsForUser(
        int $userId,
        int $householdId,
        string $sinceLocal,
        string $endLocal,
    ): array {
        $rows = $this->db->fetchAll(
            'SELECT logged_on
             FROM exercise_log
             WHERE user_id = :uid AND household_id = :hid
               AND logged_on >= :since AND logged_on < :end
             ORDER BY logged_on DESC',
            ['uid' => $userId, 'hid' => $householdId, 'since' => $sinceLocal, 'end' => $endLocal],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = (string) $r['logged_on'];
        }
        return $out;
    }

    /**
     * v0.8.3 — per-day MET-minute sums for one user over a half-open interval.
     * Feeds `TrackerBadgeAwarder::computeWeeklyMetStreak` which buckets the
     * days into ISO-week keys PHP-side (portable across PG + SQLite —
     * `strftime('%G-W%V')` diverges on the two).
     *
     * @return array<string, float>  `Y-m-d` → SUM(met_minutes)
     */
    public function dailyMetMinutesForUser(
        int $userId,
        int $householdId,
        string $sinceLocal,
        string $endLocal,
    ): array {
        $rows = $this->db->fetchAll(
            'SELECT logged_on, COALESCE(SUM(met_minutes), 0) AS m
             FROM exercise_log
             WHERE user_id = :uid AND household_id = :hid
               AND logged_on >= :since AND logged_on < :end
             GROUP BY logged_on',
            ['uid' => $userId, 'hid' => $householdId, 'since' => $sinceLocal, 'end' => $endLocal],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['logged_on']] = (float) $r['m'];
        }
        return $out;
    }
}
