<?php

declare(strict_types=1);

namespace App\Tests\Chores;

use App\Chores\DayWindow;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.14 DST regression guard for daily-streak arithmetic.
 *
 * Mirrors WeekWindowTest's posture (decision #34) for day granularity. Key fact:
 * midnight 00:00 NZDT (UTC+13) and midnight 00:00 NZST (UTC+12) are NOT 24 UTC-
 * hours apart; adjacent NZ days across a DST transition are 25 hours (end of
 * DST, autumn) or 23 hours (start of DST, spring). A naive "-86400 seconds on
 * a UTC string" walk therefore breaks at every transition. DayWindow does the
 * -1 day step IN the household tz and converts back to UTC, which always lands
 * on the correct midnight-NZ marker.
 */
final class DayWindowTest extends TestCase
{
    public function test_day_start_utc_collapses_a_whole_day_to_one_marker(): void
    {
        $tz = new \DateTimeZone('Pacific/Auckland');
        $morning = new \DateTimeImmutable('2026-06-08 09:00', $tz);
        $noon = new \DateTimeImmutable('2026-06-08 12:00', $tz);
        $latenight = new \DateTimeImmutable('2026-06-08 22:00', $tz);

        self::assertSame(
            DayWindow::dayStartUtc($tz, $morning),
            DayWindow::dayStartUtc($tz, $noon),
        );
        self::assertSame(
            DayWindow::dayStartUtc($tz, $morning),
            DayWindow::dayStartUtc($tz, $latenight),
        );
    }

    public function test_previous_day_across_nz_dst_end_is_25_utc_hours_earlier(): void
    {
        // NZ DST ends 2026-04-05 (Sun, 03:00 NZDT → 02:00 NZST).
        // Mon 2026-04-06 NZST 00:00 → 2026-04-05 12:00:00 UTC.
        // Previous day (Sun 2026-04-05 NZDT 00:00) → 2026-04-04 11:00:00 UTC.
        // UTC delta is 25 hours, NOT 24 — a `-86400 seconds` on the UTC string
        // would land on 2026-04-04 12:00:00 (one hour late) and miss the
        // previous-day marker entirely.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $current = '2026-04-05 12:00:00';   // Mon 2026-04-06 NZST 00:00

        $previous = DayWindow::previousDayStartUtc($tz, $current);

        self::assertSame('2026-04-04 11:00:00', $previous);
    }

    public function test_previous_day_across_nz_dst_start_is_23_utc_hours_earlier(): void
    {
        // NZ DST starts 2026-09-27 (Sun, 02:00 NZST → 03:00 NZDT).
        // Mon 2026-09-28 NZDT 00:00 → 2026-09-27 11:00:00 UTC.
        // Previous day (Sun 2026-09-27 NZST 00:00) → 2026-09-26 12:00:00 UTC.
        // Delta is 23 hours — symmetric to the end-of-DST case.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $current = '2026-09-27 11:00:00';   // Mon 2026-09-28 NZDT 00:00

        $previous = DayWindow::previousDayStartUtc($tz, $current);

        self::assertSame('2026-09-26 12:00:00', $previous);
    }

    public function test_previous_day_in_a_normal_winter_span_is_24_utc_hours_earlier(): void
    {
        // No DST in the span: NZST throughout. Sanity that the fix doesn't
        // break the common case.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $current = '2026-07-12 12:00:00';   // Mon 2026-07-13 NZST 00:00

        $previous = DayWindow::previousDayStartUtc($tz, $current);

        self::assertSame('2026-07-11 12:00:00', $previous);   // 24h earlier
    }

    public function test_lookback_start_handles_dst_in_the_span(): void
    {
        // From a "now" of Mon 2026-04-20 10:00 NZST, look back 30 days. The
        // span crosses the 2026-04-05 NZDT→NZST flip; UTC arithmetic alone
        // would drift by an hour. Expected: 2026-03-21 NZDT 00:00 →
        // 2026-03-20 11:00 UTC.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $now = new \DateTimeImmutable('2026-04-20 10:00', $tz);

        self::assertSame('2026-03-20 11:00:00', DayWindow::lookbackStartUtc($tz, 30, $now));
    }

    // v0.8.3 — local-DATE siblings for `exercise_log.logged_on` axis.

    public function test_day_start_local_returns_household_local_date(): void
    {
        $tz = new \DateTimeZone('Pacific/Auckland');
        $morning = new \DateTimeImmutable('2026-07-14 09:00', $tz);
        self::assertSame('2026-07-14', DayWindow::dayStartLocal($tz, $morning));
    }

    public function test_day_start_local_uses_household_tz_for_boundary(): void
    {
        // A UTC "2026-07-14 12:00" is 2026-07-15 00:00 NZST — the local DATE
        // must be 2026-07-15, not 2026-07-14.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $atUtcNoon = new \DateTimeImmutable('2026-07-14 12:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-07-15', DayWindow::dayStartLocal($tz, $atUtcNoon));
    }

    public function test_previous_day_local_across_nz_dst_end_stays_wall_clock(): void
    {
        // 2026-04-05 (Sun) was the DST-end day (25h wall clock). Yesterday of
        // Monday 2026-04-06 in NZ is 2026-04-05 — trivially by name.
        $tz = new \DateTimeZone('Pacific/Auckland');
        self::assertSame('2026-04-05', DayWindow::previousDayStartLocal($tz, '2026-04-06'));
    }

    public function test_lookback_start_local_gives_nz_calendar_date_thirty_days_ago(): void
    {
        $tz = new \DateTimeZone('Pacific/Auckland');
        $now = new \DateTimeImmutable('2026-04-20 10:00', $tz);
        self::assertSame('2026-03-21', DayWindow::lookbackStartLocal($tz, 30, $now));
    }

    public function test_previous_day_local_rejects_malformed_input(): void
    {
        $tz = new \DateTimeZone('Pacific/Auckland');
        $this->expectException(\InvalidArgumentException::class);
        DayWindow::previousDayStartLocal($tz, 'nope');
    }
}
