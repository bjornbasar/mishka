<?php

declare(strict_types=1);

namespace App\Tracker;

use App\Chores\Achievements;
use App\Chores\BadgeAwardRepository;
use App\Chores\DayWindow;
use App\Chores\WeekWindow;

/**
 * v0.8.3 — eager badge-award evaluator for the Tracker (Health) side.
 *
 * Mirrors {@see \App\Chores\BadgeAwarder} shape as a separate class rather
 * than a subclass — the two domains grant different codes off different
 * SQL feeds. Sharing the base class would couple them, so v0.8.3 keeps
 * SRP + a small cross-domain static call into `Achievements::computeStreak*`
 * for streak math (documented trade-off in DOCS #73).
 *
 * Called from `ExerciseLogController::store` AFTER `ExerciseLogRepository::
 * create` commits the row. Evaluates 9 badge thresholds and writes
 * `badge_awards` rows for any newly-crossed thresholds.
 *
 * Idempotent — every grant hits `BadgeAwardRepository::grant` which uses
 * `ON CONFLICT DO NOTHING` (PG) / `INSERT OR IGNORE` (SQLite). Re-award
 * attempts are silent no-ops.
 *
 * BEST-EFFORT semantics: the caller MUST wrap `evaluateAndGrant` in a
 * try/catch that swallows exceptions. A badge-eval failure must NEVER
 * roll back the exercise-log write. The `bin/karhu tracker:badges-backfill`
 * CLI repairs missed awards.
 *
 * Adding a new badge requires updating:
 *   - config/badges.php (presentation)
 *   - TrackerBadgeAwarder constants + grant call (persistence + threshold)
 *   - Backfill covers count/MET-minute badges automatically (walker calls
 *     this method); streak badges are eager-only.
 */
final class TrackerBadgeAwarder
{
    /** @var array<string, int> count-based thresholds (total_entries >= N) */
    private const COUNT_THRESHOLDS = [
        'first_workout'  => 1,
        'ten_workouts'   => 10,
        'fifty_workouts' => 50,
    ];

    /** @var array<string, int> lifetime MET-minute thresholds */
    private const MET_MINUTE_THRESHOLDS = [
        'five_hundred_met_minutes' => 500,
        'five_thousand_met_minutes' => 5000,
    ];

    /** WHO moderate-activity baseline — the threshold for `active_week`. */
    private const ACTIVE_WEEK_MET_MINUTES = 150;

    /** Consecutive `active_week` weeks required for `four_week_effort_streak`. */
    private const WEEKLY_STREAK_THRESHOLD = 4;

    /** @var array<string, int> daily-activity streak thresholds */
    private const DAILY_STREAK_THRESHOLDS = [
        'seven_day_activity_streak'  => 7,
        'thirty_day_activity_streak' => 30,
    ];

    /** 52-week lookback for both weekly + daily streak feeds. */
    private const STREAK_LOOKBACK_WEEKS = 52;

    public function __construct(
        private readonly BadgeAwardRepository $awards,
        private readonly ExerciseLogRepository $exerciseLog,
    ) {}

    /**
     * Evaluate all 9 thresholds + grant any newly-crossed.
     *
     * `earned_at` is pinned to `$now`'s UTC representation (the moment of
     * evaluation) — matches how chore's `BadgeAwarder` uses the triggering
     * ledger row's timestamp. For backfill, `$now` is the wall-clock at
     * CLI invocation; slight loss of "the badge was actually earned at
     * event T" fidelity is accepted for count/MET-minute badges (streak
     * badges aren't backfilled — eager-only, mirrors chore #54/#55).
     *
     * Caller MUST NOT wrap in a transaction it intends to commit/rollback
     * based on award success — see class docblock for rationale.
     */
    public function evaluateAndGrant(
        int $householdId,
        int $userId,
        \DateTimeZone $householdTz,
        \DateTimeImmutable $now,
    ): void {
        if ($householdId <= 0 || $userId <= 0) {
            return;
        }

        $earnedAt = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        // 1. Cumulative stats — ONE round trip (count + total_met_minutes).
        $stats = $this->exerciseLog->cumulativeStatsForUser($userId, $householdId);
        $count = $stats['count'];
        $totalMetMinutes = $stats['total_met_minutes'];

        foreach (self::COUNT_THRESHOLDS as $code => $threshold) {
            if ($count >= $threshold) {
                $this->awards->grant($householdId, $userId, $code, $earnedAt);
            }
        }
        foreach (self::MET_MINUTE_THRESHOLDS as $code => $threshold) {
            if ($totalMetMinutes >= $threshold) {
                $this->awards->grant($householdId, $userId, $code, $earnedAt);
            }
        }

        // 2. Streaks + active_week — pull a 52-week window of DATE strings
        //    (household-local logged_on). Same feed serves both the daily-
        //    activity streak (via computeDailyStreakLocal) AND the weekly-
        //    MET streak (via dailyMetMinutesForUser + computeWeeklyMetStreak).
        //
        // Half-open [since, tomorrow) so TODAY'S entries are included. Compute
        // tomorrow's DATE from today's DATE via +1-day arithmetic in $tz.
        $sinceLocal = WeekWindow::lookbackStartLocal($householdTz, self::STREAK_LOOKBACK_WEEKS, $now);
        $endLocal = DayWindow::dayStartLocal($householdTz, $now);
        $tomorrowDt = \DateTimeImmutable::createFromFormat('!Y-m-d', $endLocal, $householdTz);
        $tomorrowLocal = ($tomorrowDt !== false ? $tomorrowDt : $now->setTimezone($householdTz))
            ->modify('+1 day')
            ->format('Y-m-d');

        // 3. Weekly-MET streak — count consecutive ISO weeks with SUM(met_minutes) >= 150.
        $daily = $this->exerciseLog->dailyMetMinutesForUser(
            $userId,
            $householdId,
            $sinceLocal,
            $tomorrowLocal,
        );
        $thisWeekIsoKey = self::isoWeekKey($endLocal);
        $weeklyStreak = self::computeWeeklyMetStreak($daily, $thisWeekIsoKey, self::ACTIVE_WEEK_MET_MINUTES);

        // `active_week` badge — earned the first time any single ISO week
        // crosses 150 MET-min. Any weekly bucket meeting the bar (not just
        // the current one) qualifies.
        $weeklySums = self::bucketByIsoWeek($daily);
        foreach ($weeklySums as $sum) {
            if ($sum >= self::ACTIVE_WEEK_MET_MINUTES) {
                $this->awards->grant($householdId, $userId, 'active_week', $earnedAt);
                break;
            }
        }
        if ($weeklyStreak >= self::WEEKLY_STREAK_THRESHOLD) {
            $this->awards->grant($householdId, $userId, 'four_week_effort_streak', $earnedAt);
        }

        // 4. Daily-activity streaks — reuse the DATE feed via a distinct query
        //    (dailyMetMinutesForUser drops any day where every entry is strength
        //    branch — met_minutes=NULL → SUM=0 → still an entry, so the day
        //    should count for activity streaks). Use recentLoggedOnsForUser
        //    which enumerates raw rows regardless of MET.
        $recentDays = $this->exerciseLog->recentLoggedOnsForUser(
            $userId,
            $householdId,
            $sinceLocal,
            $tomorrowLocal,
        );
        $dailyStreak = Achievements::computeDailyStreakLocal(
            $recentDays,
            $householdTz,
            $endLocal,
            DayWindow::previousDayStartLocal($householdTz, $endLocal),
        );
        foreach (self::DAILY_STREAK_THRESHOLDS as $code => $threshold) {
            if ($dailyStreak >= $threshold) {
                $this->awards->grant($householdId, $userId, $code, $earnedAt);
            }
        }
    }

    /**
     * v0.8.3 — pure function: count consecutive ISO-week buckets ending at
     * `$thisWeekIsoKey` where the sum of MET-minutes ≥ `$threshold`.
     *
     * `$daily` keys are `Y-m-d` DATE strings; internally buckets by PHP's
     * `DateTimeImmutable::format('o-\WW')` (ISO year-week — portable +
     * year-boundary-safe, e.g. 2027-01-01 (Fri) → `2026-W53`).
     *
     * @param array<string, float> $daily
     */
    public static function computeWeeklyMetStreak(array $daily, string $thisWeekIsoKey, int $threshold): int
    {
        if ($daily === []) {
            return 0;
        }
        $buckets = self::bucketByIsoWeek($daily);
        if ($buckets === []) {
            return 0;
        }

        // Walk backwards from `thisWeekIsoKey` counting consecutive weeks
        // where the sum ≥ threshold. Break on first miss.
        $streak = 0;
        $cursorKey = $thisWeekIsoKey;
        // Cap iterations at 60 (52-week lookback + slack) — defensive
        // against a runaway loop if the cursor doesn't decrement.
        for ($i = 0; $i < 60; $i++) {
            $sum = $buckets[$cursorKey] ?? 0.0;
            if ($sum >= $threshold) {
                $streak++;
                $cursorKey = self::previousIsoWeekKey($cursorKey);
            } else {
                break;
            }
        }
        return $streak;
    }

    /**
     * Convert a `Y-m-d` DATE (in any locale — pure calendar math) to its
     * ISO year-week key (e.g. `2026-W28`).
     */
    public static function isoWeekKey(string $ymd): string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        if ($dt === false) {
            throw new \InvalidArgumentException("isoWeekKey requires Y-m-d, got: {$ymd}");
        }
        return $dt->format('o-\WW');
    }

    /**
     * Aggregate `Y-m-d → float` sums into `o-Www → float` ISO-week buckets.
     *
     * @param array<string, float> $daily
     * @return array<string, float>
     */
    private static function bucketByIsoWeek(array $daily): array
    {
        $out = [];
        foreach ($daily as $ymd => $sum) {
            $key = self::isoWeekKey($ymd);
            $out[$key] = ($out[$key] ?? 0.0) + $sum;
        }
        return $out;
    }

    /**
     * Given an ISO-week key `YYYY-Www`, return the key for the week BEFORE.
     * `2026-W01` → `2025-W52` (or `2025-W53` depending on ISO calendar) —
     * derived by subtracting 7 days from Monday-of-that-week.
     */
    private static function previousIsoWeekKey(string $isoKey): string
    {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $isoKey, $m)) {
            throw new \InvalidArgumentException("previousIsoWeekKey requires YYYY-Www, got: {$isoKey}");
        }
        // Monday-of-week via '1W' day-of-week token: `Y W N` = ISO year, ISO week, day-of-week (1=Mon).
        $monday = new \DateTimeImmutable(sprintf('%s-W%02d-1', $m[1], (int) $m[2]));
        return $monday->modify('-1 week')->format('o-\WW');
    }
}
