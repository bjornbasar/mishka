<?php

declare(strict_types=1);

namespace App\Tests\Chores;

use App\Chores\WeekWindow;
use PHPUnit\Framework\TestCase;

/**
 * The DST regression guard. Every week-arithmetic step in v0.4.3 (streak walks,
 * leaderboard bounds, lookback floor) routes through WeekWindow — the static
 * helper exists so the DST fix lives in exactly one place.
 *
 * Key fact: Monday 00:00 NZDT (UTC+13) and Monday 00:00 NZST (UTC+12) are NOT
 * 168 UTC-hours apart; adjacent weeks across a DST transition are 169 hours
 * (end of DST) or 167 hours (start of DST). A naive "−7d on a UTC string" walk
 * therefore breaks at every transition. WeekWindow does the −1 week step IN
 * the household tz and converts back to UTC, which always lands on the correct
 * Monday-NZ-midnight marker.
 */
final class WeekWindowTest extends TestCase
{
    public function test_week_start_utc_collapses_a_whole_week_to_one_marker(): void
    {
        $tz = new \DateTimeZone('Pacific/Auckland');
        $monday = new \DateTimeImmutable('2026-06-08 09:00', $tz);     // Mon
        $wednesday = new \DateTimeImmutable('2026-06-10 09:00', $tz);  // Wed same week
        $sunday = new \DateTimeImmutable('2026-06-14 22:00', $tz);     // Sun same week

        self::assertSame(
            WeekWindow::weekStartUtc($tz, $monday),
            WeekWindow::weekStartUtc($tz, $wednesday),
        );
        self::assertSame(
            WeekWindow::weekStartUtc($tz, $monday),
            WeekWindow::weekStartUtc($tz, $sunday),
        );
    }

    public function test_previous_week_across_nz_dst_end_is_169_utc_hours_earlier(): void
    {
        // NZ DST ends 2026-04-05 (Sun, 03:00 NZDT → 02:00 NZST).
        // Mon 2026-04-06 NZST 00:00 → 2026-04-05 12:00:00 UTC.
        // Previous Monday (Mon 2026-03-30 NZDT 00:00) → 2026-03-29 11:00:00 UTC.
        // The UTC delta is 169 hours, NOT 168 — a `−7d` on the UTC string would land on
        // 2026-03-29 12:00:00 (one hour late) and miss the previous-week marker entirely.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $current = '2026-04-05 12:00:00';

        $previous = WeekWindow::previousWeekStartUtc($tz, $current);

        self::assertSame('2026-03-29 11:00:00', $previous);
    }

    public function test_previous_week_across_nz_dst_start_is_167_utc_hours_earlier(): void
    {
        // NZ DST starts 2026-09-27 (Sun, 02:00 NZST → 03:00 NZDT).
        // Mon 2026-09-28 NZDT 00:00 → 2026-09-27 11:00:00 UTC.
        // Previous Monday (Mon 2026-09-21 NZST 00:00) → 2026-09-20 12:00:00 UTC.
        // Delta is 167 hours — symmetric to the end-of-DST case.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $current = '2026-09-27 11:00:00';

        $previous = WeekWindow::previousWeekStartUtc($tz, $current);

        self::assertSame('2026-09-20 12:00:00', $previous);
    }

    public function test_previous_week_in_a_normal_winter_span_is_168_utc_hours_earlier(): void
    {
        // No DST in the span: NZST throughout (winter weeks). Sanity that the fix
        // doesn't break the common case.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $current = '2026-07-12 12:00:00';  // Mon 2026-07-13 NZST 00:00 → 2026-07-12 12:00 UTC

        $previous = WeekWindow::previousWeekStartUtc($tz, $current);

        self::assertSame('2026-07-05 12:00:00', $previous);  // 168h earlier
    }

    public function test_lookback_start_handles_dst_in_the_span(): void
    {
        // From a Mon-2026-04-20 NZST "now", look back 4 NZ-weeks. The span crosses
        // the 2026-04-05 NZDT→NZST flip; UTC arithmetic alone would drift by an hour.
        // Expected: Mon 2026-03-23 NZDT 00:00 → 2026-03-22 11:00 UTC.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $now = new \DateTimeImmutable('2026-04-20 10:00', $tz);

        self::assertSame('2026-03-22 11:00:00', WeekWindow::lookbackStartUtc($tz, 4, $now));
    }

    // v0.8.3 — local-DATE siblings (Y-m-d strings for `WHERE logged_on >= :ws AND logged_on < :we`).

    public function test_week_start_local_returns_household_local_monday_date(): void
    {
        $tz = new \DateTimeZone('Pacific/Auckland');
        $wednesday = new \DateTimeImmutable('2026-07-15 14:30', $tz);
        self::assertSame('2026-07-13', WeekWindow::weekStartLocal($tz, $wednesday));
    }

    public function test_week_start_local_collapses_a_whole_week_to_one_date(): void
    {
        $tz = new \DateTimeZone('Pacific/Auckland');
        $mon = new \DateTimeImmutable('2026-07-13 09:00', $tz);
        $sun = new \DateTimeImmutable('2026-07-19 22:00', $tz);
        self::assertSame(
            WeekWindow::weekStartLocal($tz, $mon),
            WeekWindow::weekStartLocal($tz, $sun),
        );
    }

    public function test_week_end_local_is_exactly_seven_days_forward_across_dst_end(): void
    {
        // Week straddles NZ DST end (2026-04-05, NZDT→NZST). Wall-clock +1 week
        // must still land on the following Monday's local date regardless of the
        // 25-hour Sunday inside the interval.
        $tz = new \DateTimeZone('Pacific/Auckland');
        self::assertSame('2026-04-06', WeekWindow::weekEndLocal($tz, '2026-03-30'));
    }

    public function test_lookback_start_local_matches_lookback_start_utc_semantics(): void
    {
        // 4-week lookback from Mon 2026-04-20 NZST → Mon 2026-03-23 NZDT.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $now = new \DateTimeImmutable('2026-04-20 10:00', $tz);
        self::assertSame('2026-03-23', WeekWindow::lookbackStartLocal($tz, 4, $now));
    }

    public function test_week_end_local_rejects_malformed_input(): void
    {
        $tz = new \DateTimeZone('Pacific/Auckland');
        $this->expectException(\InvalidArgumentException::class);
        WeekWindow::weekEndLocal($tz, 'nope');
    }
}
