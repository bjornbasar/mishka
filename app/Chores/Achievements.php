<?php

declare(strict_types=1);

namespace App\Chores;

/**
 * Per-member badges + weekly streak, derived from the v0.4.2 ledger.
 *
 * Pure-PHP, no DB. The controller supplies the leaderboard rows + the recent
 * completion timestamps (via ChoreRepository). All weekly arithmetic routes
 * through WeekWindow so DST transitions don't drift the streak walk.
 *
 * Stateless: badges and streaks are re-derived per render. The badge registry
 * is returned from a method (not a `const`) because arrow functions are
 * runtime Closure objects and can't appear in compile-time constant expressions.
 */
final class Achievements
{
    /**
     * @param list<array{user_id: int, total_points: int, week_points: int, total_completions: int}> $board
     * @param array<int, list<string>> $recentCompletionsByUser  user_id → UTC completed_at strings (DESC)
     * @return array<int, array{badges: list<string>, streak: int}>  keyed by user_id
     */
    public function compute(
        array $board,
        array $recentCompletionsByUser,
        \DateTimeZone $householdTz,
        \DateTimeImmutable $now,
    ): array {
        $weekNow = WeekWindow::weekStartUtc($householdTz, $now);
        $weekPrev = WeekWindow::previousWeekStartUtc($householdTz, $weekNow);

        $out = [];
        // Iterate $board (NOT $recentCompletionsByUser) so a stray key in the
        // latter can't smuggle in a member who isn't on the current board.
        foreach ($board as $row) {
            $userId = (int) $row['user_id'];
            $streak = $this->computeStreak(
                $recentCompletionsByUser[$userId] ?? [],
                $householdTz,
                $weekNow,
                $weekPrev,
            );
            $stats = [
                'total_points' => (int) $row['total_points'],
                'total_completions' => (int) $row['total_completions'],
                'week_points' => (int) $row['week_points'],
                'streak' => $streak,
            ];
            $out[$userId] = [
                'badges' => $this->badgesFor($stats),
                'streak' => $streak,
            ];
        }
        return $out;
    }

    /**
     * Streak = consecutive weeks (Monday in $tz) with ≥1 completion, walked
     * back from the most recent activity week. Broken if the latest activity
     * is older than last week — i.e. a full missed week.
     *
     * @param list<string> $completedAtsUtc
     */
    private function computeStreak(
        array $completedAtsUtc,
        \DateTimeZone $tz,
        string $weekNow,
        string $weekPrev,
    ): int {
        if ($completedAtsUtc === []) {
            return 0;
        }

        // Distinct UTC week-start markers (each computed in $tz, per B1).
        $utc = new \DateTimeZone('UTC');
        $set = [];
        foreach ($completedAtsUtc as $ts) {
            $instant = new \DateTimeImmutable($ts, $utc);
            $weekStart = $instant->setTimezone($tz)
                ->modify('monday this week')
                ->setTime(0, 0, 0)
                ->setTimezone($utc)
                ->format('Y-m-d H:i:s');
            $set[$weekStart] = true;
        }
        $activeWeeks = array_keys($set);
        rsort($activeWeeks);  // DESC

        // Streak is broken if the latest activity week is older than last week.
        if ($activeWeeks[0] < $weekPrev) {
            return 0;
        }

        $streak = 1;
        $cursor = $activeWeeks[0];
        for ($i = 1, $n = count($activeWeeks); $i < $n; $i++) {
            $expected = WeekWindow::previousWeekStartUtc($tz, $cursor);
            if ($activeWeeks[$i] === $expected) {
                $streak++;
                $cursor = $activeWeeks[$i];
            } else {
                break;
            }
        }
        return $streak;
    }

    /**
     * @param array<string, int> $stats
     * @return list<string>  badge codes in canonical definition order
     */
    private function badgesFor(array $stats): array
    {
        $out = [];
        foreach (self::badges() as $badge) {
            if (($badge['criterion'])($stats)) {
                $out[] = $badge['code'];
            }
        }
        return $out;
    }

    /**
     * Badge registry. Returned from a method (not a `const`) because arrow
     * functions are runtime closures and can't live in constant expressions.
     *
     * @return list<array{code: string, criterion: callable(array<string, int>): bool}>
     */
    private static function badges(): array
    {
        return [
            ['code' => 'first_chore',      'criterion' => fn(array $s): bool => $s['total_completions'] >= 1],
            ['code' => 'ten_chores',       'criterion' => fn(array $s): bool => $s['total_completions'] >= 10],
            ['code' => 'fifty_chores',     'criterion' => fn(array $s): bool => $s['total_completions'] >= 50],
            ['code' => 'centurion',        'criterion' => fn(array $s): bool => $s['total_points'] >= 100],
            ['code' => 'five_hundred',     'criterion' => fn(array $s): bool => $s['total_points'] >= 500],
            ['code' => 'four_week_streak', 'criterion' => fn(array $s): bool => $s['streak'] >= 4],
        ];
    }
}
