<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\LocalDay;
use PHPUnit\Framework\TestCase;

final class LocalDayTest extends TestCase
{
    public function test_returns_household_local_date_when_utc_now_would_disagree(): void
    {
        // 2026-07-12 11:59 UTC = 2026-07-12 23:59 Pacific/Auckland (NZST, +12).
        // Both agree on the calendar date at this instant.
        $now = new \DateTimeImmutable('2026-07-12T11:59:00+00:00');
        $tz = new \DateTimeZone('Pacific/Auckland');

        self::assertSame('2026-07-12', LocalDay::today($tz, $now));
    }

    public function test_rolls_over_at_household_local_midnight_not_utc_midnight(): void
    {
        // 2026-07-12 12:30 UTC = 2026-07-13 00:30 Pacific/Auckland — user's
        // calendar has already flipped to the 13th; UTC is still the 12th.
        $now = new \DateTimeImmutable('2026-07-12T12:30:00+00:00');
        $tz = new \DateTimeZone('Pacific/Auckland');

        self::assertSame('2026-07-13', LocalDay::today($tz, $now));
    }

    public function test_format_shape_matches_iso_date(): void
    {
        // No `$now` argument — uses real-now. Just assert the shape.
        $today = LocalDay::today(new \DateTimeZone('Pacific/Auckland'));

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $today);
    }
}
