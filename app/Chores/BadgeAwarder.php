<?php

declare(strict_types=1);

namespace App\Chores;

/**
 * v0.6.13 — eager badge-award evaluator.
 *
 * Called from ChoresController::handleDone() AFTER markDone() commits the
 * ledger row. Evaluates all 6 v0.4.3 badge thresholds (3 count-based,
 * 2 points-based, 1 streak) against the user's post-completion state
 * and writes badge_awards rows for any newly-crossed thresholds.
 *
 * Idempotent: each grant uses ON CONFLICT DO NOTHING (PG) / INSERT OR
 * IGNORE (SQLite), so a re-evaluation after a previously-earned badge
 * is a silent no-op.
 *
 * BEST-EFFORT semantics: the caller MUST wrap evaluateAndGrant() in a
 * try/catch that swallows exceptions. A badge-eval failure must NEVER
 * roll back the chore-completion (the user marked it done; that's
 * durable). The `badges:backfill` CLI repairs missed awards.
 *
 * Decision #35 reversal: BadgeAwarder owns the COUNT/POINTS threshold
 * table (canonical for the persistence side). Achievements::computeStreak
 * stays the single source of truth for streak math (called statically
 * here, since v0.6.13 promoted it from private instance to public static).
 * Adding a new badge requires updating BOTH config/badges.php AND
 * BadgeAwarder constants AND the badges:backfill walker.
 */
final class BadgeAwarder
{
    /** @var array<string, int> count-based thresholds (total_completions >= N) */
    private const COUNT_THRESHOLDS = [
        'first_chore'  => 1,
        'ten_chores'   => 10,
        'fifty_chores' => 50,
    ];

    /** @var array<string, int> points-based thresholds (total_points >= N) */
    private const POINTS_THRESHOLDS = [
        'centurion'    => 100,
        'five_hundred' => 500,
    ];

    /** Streak threshold (consecutive household-tz weeks with ≥1 completion). */
    private const STREAK_THRESHOLD = 4;

    /** Look back 52 weeks for streak computation — matches Achievements::compute. */
    private const STREAK_LOOKBACK_WEEKS = 52;

    public function __construct(
        private readonly BadgeAwardRepository $awards,
        private readonly ChoreRepository $chores,
    ) {}

    /**
     * Evaluate all 6 thresholds + grant any newly-crossed. earned_at is
     * pinned to $completedAt (the triggering ledger row's UTC timestamp).
     *
     * Caller MUST NOT wrap in a transaction it intends to commit/rollback
     * based on award success — see class docblock for rationale.
     */
    public function evaluateAndGrant(
        int $householdId,
        int $userId,
        string $completedAt,
        \DateTimeZone $householdTz,
        \DateTimeImmutable $now,
    ): void {
        if ($householdId <= 0 || $userId <= 0) {
            return;
        }

        // 1. Compute week markers via WeekWindow (DST-safe; matches
        //    ChoresController::achievementsBoard's window semantics).
        $weekNow = WeekWindow::weekStartUtc($householdTz, $now);
        $weekPrev = WeekWindow::previousWeekStartUtc($householdTz, $weekNow);
        $lookbackUtc = WeekWindow::lookbackStartUtc($householdTz, self::STREAK_LOOKBACK_WEEKS, $now);

        // 2. Fetch all-time stats. leaderboardForHousehold's total_completions +
        //    total_points are unconditional aggregates; weekStart only filters
        //    week_points. So we can pass any reasonable weekStart and read the
        //    all-time columns for threshold comparison.
        $board = $this->chores->leaderboardForHousehold($householdId, $weekNow);
        $userRow = null;
        foreach ($board as $row) {
            if ((int) $row['user_id'] === $userId) {
                $userRow = $row;
                break;
            }
        }
        if ($userRow === null) {
            return;  // sentinel / anon / departed-member edge — nothing to do
        }

        $completions = (int) $userRow['total_completions'];
        $points = (int) $userRow['total_points'];

        // 3. Count + points thresholds — single-statement grants, ON CONFLICT skips.
        foreach (self::COUNT_THRESHOLDS as $code => $threshold) {
            if ($completions >= $threshold) {
                $this->awards->grant($householdId, $userId, $code, $completedAt);
            }
        }
        foreach (self::POINTS_THRESHOLDS as $code => $threshold) {
            if ($points >= $threshold) {
                $this->awards->grant($householdId, $userId, $code, $completedAt);
            }
        }

        // 4. Four-week streak — delegate to Achievements::computeStreak (the
        //    DST-safe single source of truth for streak math).
        $recent = $this->chores->recentCompletionsForHousehold($householdId, $lookbackUtc);
        $userCompletions = $recent[$userId] ?? [];
        $streak = Achievements::computeStreak($userCompletions, $householdTz, $weekNow, $weekPrev);
        if ($streak >= self::STREAK_THRESHOLD) {
            $this->awards->grant($householdId, $userId, 'four_week_streak', $completedAt);
        }
    }
}
