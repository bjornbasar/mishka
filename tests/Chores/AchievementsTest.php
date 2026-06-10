<?php

declare(strict_types=1);

namespace App\Tests\Chores;

use App\Chores\Achievements;
use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP unit tests — no DB. Streaks and badges are derived from the per-member
 * stats + ledger timestamps that the controller passes in.
 *
 * `$now` is injected for deterministic week-math; we anchor it in NZ time so the
 * DST tests live alongside the streak-walk logic they regression-guard (per
 * B1 in the v0.4.3 plan).
 */
final class AchievementsTest extends TestCase
{
    private const TZ = 'Pacific/Auckland';

    public function test_empty_board_returns_empty(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $out = (new Achievements())->compute([], [], $tz, $this->now('2026-06-15 09:00'));
        self::assertSame([], $out);
    }

    public function test_zero_completions_returns_no_badges_or_streak(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 0, completions: 0)];

        $out = (new Achievements())->compute($board, [], $tz, $this->now('2026-06-15 09:00'));

        self::assertSame(['badges' => [], 'streak' => 0, 'daily_streak' => 0], $out[1]);
    }

    public function test_one_completion_this_week_earns_first_chore_and_streak_one(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 5, completions: 1)];
        // One completion this week, in NZ wall-clock.
        $recent = [1 => [$this->utc('2026-06-17 09:00')]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 09:00'));

        self::assertSame(['first_chore'], $out[1]['badges']);
        self::assertSame(1, $out[1]['streak']);
    }

    public function test_this_week_plus_last_week_is_streak_two(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 10, completions: 2)];
        $recent = [1 => [$this->utc('2026-06-17 09:00'), $this->utc('2026-06-10 09:00')]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 09:00'));

        self::assertSame(2, $out[1]['streak']);
    }

    public function test_gap_breaks_the_streak_at_the_gap(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 15, completions: 3)];
        // this week, last week, then a missed week, then activity 3 weeks ago.
        $recent = [1 => [
            $this->utc('2026-06-17 09:00'),  // this week
            $this->utc('2026-06-10 09:00'),  // last week
            // missed 2026-06-03 week
            $this->utc('2026-05-27 09:00'),  // 3 weeks ago
        ]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 09:00'));

        self::assertSame(2, $out[1]['streak']);  // broken before the missed week
    }

    public function test_consecutive_four_weeks_earns_on_fire(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 20, completions: 4)];
        $recent = [1 => [
            $this->utc('2026-06-17 09:00'),
            $this->utc('2026-06-10 09:00'),
            $this->utc('2026-06-03 09:00'),
            $this->utc('2026-05-27 09:00'),
        ]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 09:00'));

        self::assertSame(4, $out[1]['streak']);
        self::assertContains('four_week_streak', $out[1]['badges']);
    }

    public function test_points_thresholds_earn_the_right_tier(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $now = $this->now('2026-06-18 09:00');

        $under = (new Achievements())->compute([$this->boardRow(1, 99, 10)], [], $tz, $now)[1];
        $hundred = (new Achievements())->compute([$this->boardRow(1, 100, 10)], [], $tz, $now)[1];
        $five = (new Achievements())->compute([$this->boardRow(1, 500, 10)], [], $tz, $now)[1];

        self::assertNotContains('centurion', $under['badges']);
        self::assertContains('centurion', $hundred['badges']);
        self::assertContains('five_hundred', $five['badges']);
    }

    public function test_completion_count_tiers(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $now = $this->now('2026-06-18 09:00');

        $nine = (new Achievements())->compute([$this->boardRow(1, 0, 9)], [], $tz, $now)[1];
        $ten = (new Achievements())->compute([$this->boardRow(1, 0, 10)], [], $tz, $now)[1];
        $fifty = (new Achievements())->compute([$this->boardRow(1, 0, 50)], [], $tz, $now)[1];

        self::assertNotContains('ten_chores', $nine['badges']);
        self::assertContains('ten_chores', $ten['badges']);
        self::assertContains('fifty_chores', $fifty['badges']);
    }

    public function test_last_activity_3_weeks_ago_streak_is_zero(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 5, completions: 1)];
        $recent = [1 => [$this->utc('2026-05-27 09:00')]];  // 3 weeks before $now

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 09:00'));

        self::assertSame(0, $out[1]['streak']);
    }

    public function test_dst_end_four_weeks_across_the_flip_streak_is_four(): void
    {
        // The B1 regression guard: NZ end-of-DST 2026-04-05. Without the
        // household-tz step, the streak walk would break at the boundary.
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 20, completions: 4)];
        // Four NZ-weeks bracketing the flip:
        //   Mon 2026-04-13 (NZST), Mon 2026-04-06 (NZST),
        //   Mon 2026-03-30 (NZDT), Mon 2026-03-23 (NZDT)
        $recent = [1 => [
            $this->utc('2026-04-13 09:00', self::TZ),  // NZST
            $this->utc('2026-04-06 09:00', self::TZ),  // NZST, first week after the flip
            $this->utc('2026-03-30 09:00', self::TZ),  // NZDT, last week before the flip
            $this->utc('2026-03-23 09:00', self::TZ),  // NZDT
        ]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-04-14 09:00'));

        self::assertSame(4, $out[1]['streak']);
    }

    public function test_dst_start_four_weeks_across_the_flip_streak_is_four(): void
    {
        // Symmetric: NZ start-of-DST 2026-09-27 (NZST→NZDT).
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 20, completions: 4)];
        $recent = [1 => [
            $this->utc('2026-10-05 09:00', self::TZ),  // NZDT
            $this->utc('2026-09-28 09:00', self::TZ),  // NZDT, first week after
            $this->utc('2026-09-21 09:00', self::TZ),  // NZST, last week before
            $this->utc('2026-09-14 09:00', self::TZ),  // NZST
        ]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-10-06 09:00'));

        self::assertSame(4, $out[1]['streak']);
    }

    public function test_non_dst_winter_control_streak_four(): void
    {
        // Regression guard that the DST fix doesn't break the common case.
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 20, completions: 4)];
        $recent = [1 => [
            $this->utc('2026-07-13 09:00', self::TZ),
            $this->utc('2026-07-06 09:00', self::TZ),
            $this->utc('2026-06-29 09:00', self::TZ),
            $this->utc('2026-06-22 09:00', self::TZ),
        ]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-07-14 09:00'));

        self::assertSame(4, $out[1]['streak']);
    }

    public function test_stray_user_in_recent_completions_but_not_in_board_is_ignored(): void
    {
        // R4 defensive: compute() iterates $board, so a stray key in
        // $recentCompletionsByUser doesn't smuggle in a phantom member.
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, 0, 0)];
        $recent = [
            1 => [],
            999 => [$this->utc('2026-06-17 09:00')],  // not in board
        ];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 09:00'));

        self::assertSame([1], array_keys($out));
    }

    // ============================================================
    // v0.6.14 — daily-streak coverage
    // ============================================================

    public function test_today_only_is_daily_streak_one(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 5, completions: 1)];
        $recent = [1 => [$this->utc('2026-06-18 09:00')]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 12:00'));

        self::assertSame(1, $out[1]['daily_streak']);
    }

    public function test_today_plus_yesterday_is_daily_streak_two(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 10, completions: 2)];
        $recent = [1 => [
            $this->utc('2026-06-18 09:00'),   // today
            $this->utc('2026-06-17 09:00'),   // yesterday
        ]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 12:00'));

        self::assertSame(2, $out[1]['daily_streak']);
    }

    public function test_consecutive_seven_days_is_daily_streak_seven(): void
    {
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 35, completions: 7)];
        $recent = [1 => [
            $this->utc('2026-06-18 09:00'),
            $this->utc('2026-06-17 09:00'),
            $this->utc('2026-06-16 09:00'),
            $this->utc('2026-06-15 09:00'),
            $this->utc('2026-06-14 09:00'),
            $this->utc('2026-06-13 09:00'),
            $this->utc('2026-06-12 09:00'),
        ]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 12:00'));

        self::assertSame(7, $out[1]['daily_streak']);
    }

    public function test_gap_breaks_daily_streak_at_the_gap(): void
    {
        // today + yesterday + skip-a-day + 3-days-ago. Streak = 2 (today +
        // yesterday only; the gap breaks the walk).
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 15, completions: 3)];
        $recent = [1 => [
            $this->utc('2026-06-18 09:00'),
            $this->utc('2026-06-17 09:00'),
            // 2026-06-16 skipped
            $this->utc('2026-06-15 09:00'),
        ]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 12:00'));

        self::assertSame(2, $out[1]['daily_streak']);
    }

    public function test_last_activity_2_days_ago_daily_streak_is_zero(): void
    {
        // Latest completion was 2 days ago; today + yesterday both empty
        // → streak broken (latest < yesterday-marker).
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 5, completions: 1)];
        $recent = [1 => [$this->utc('2026-06-16 09:00')]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-06-18 12:00'));

        self::assertSame(0, $out[1]['daily_streak']);
    }

    public function test_dst_end_consecutive_days_across_the_flip_daily_streak_correct(): void
    {
        // 5 NZ days bracketing the 2026-04-05 NZDT→NZST flip. Naive UTC arithmetic
        // would drift across the transition; DayWindow handles it. Walking days
        // Sat Apr 4 (NZDT) → Sun Apr 5 (NZDT/NZST transition) → Mon Apr 6 (NZST)
        // → Tue Apr 7 (NZST) → Wed Apr 8 (NZST), all distinct day-start markers.
        $tz = new \DateTimeZone(self::TZ);
        $board = [$this->boardRow(1, points: 25, completions: 5)];
        $recent = [1 => [
            $this->utc('2026-04-08 09:00'),   // Wed (NZST)
            $this->utc('2026-04-07 09:00'),   // Tue (NZST)
            $this->utc('2026-04-06 09:00'),   // Mon (NZST) — first day of NZST
            $this->utc('2026-04-05 09:00'),   // Sun (NZDT→NZST transition day)
            $this->utc('2026-04-04 09:00'),   // Sat (NZDT)
        ]];

        $out = (new Achievements())->compute($board, $recent, $tz, $this->now('2026-04-08 12:00'));

        self::assertSame(5, $out[1]['daily_streak']);
    }

    // --- helpers ---

    /** @return array<string, int> */
    private function boardRow(int $userId, int $points, int $completions): array
    {
        return [
            'user_id' => $userId,
            'total_points' => $points,
            'total_completions' => $completions,
            'week_points' => 0,
        ];
    }

    private function now(string $localNz): \DateTimeImmutable
    {
        return new \DateTimeImmutable($localNz, new \DateTimeZone(self::TZ));
    }

    /** Format a NZ wall-clock as a UTC instant string (matches how the ledger stores it). */
    private function utc(string $localNz, string $tz = self::TZ): string
    {
        return (new \DateTimeImmutable($localNz, new \DateTimeZone($tz)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
