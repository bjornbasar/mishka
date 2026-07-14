<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\LoggedOnValidator;
use PHPUnit\Framework\TestCase;

final class LoggedOnValidatorTest extends TestCase
{
    private const TZ = 'Pacific/Auckland';

    private function tz(): \DateTimeZone
    {
        return new \DateTimeZone(self::TZ);
    }

    private function now(string $localWallClock): \DateTimeImmutable
    {
        return new \DateTimeImmutable($localWallClock, $this->tz());
    }

    public function test_blank_returns_todays_household_local_date(): void
    {
        $now = $this->now('2026-07-15 14:30');
        self::assertSame('2026-07-15', LoggedOnValidator::parse('', $this->tz(), $now));
        self::assertSame('2026-07-15', LoggedOnValidator::parse(null, $this->tz(), $now));
    }

    public function test_valid_date_today_accepted(): void
    {
        $now = $this->now('2026-07-15 09:00');
        self::assertSame('2026-07-15', LoggedOnValidator::parse('2026-07-15', $this->tz(), $now));
    }

    public function test_valid_date_yesterday_accepted(): void
    {
        $now = $this->now('2026-07-15 09:00');
        self::assertSame('2026-07-14', LoggedOnValidator::parse('2026-07-14', $this->tz(), $now));
    }

    public function test_boundary_exactly_seven_days_ago_accepted_inclusive(): void
    {
        $now = $this->now('2026-07-15 09:00');
        // today - 7 days = 2026-07-08. Must be accepted (inclusive).
        self::assertSame('2026-07-08', LoggedOnValidator::parse('2026-07-08', $this->tz(), $now));
    }

    public function test_boundary_eight_days_ago_rejected(): void
    {
        $now = $this->now('2026-07-15 09:00');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('7 days old');
        LoggedOnValidator::parse('2026-07-07', $this->tz(), $now);
    }

    public function test_future_date_rejected(): void
    {
        $now = $this->now('2026-07-15 09:00');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be in the future');
        LoggedOnValidator::parse('2026-07-16', $this->tz(), $now);
    }

    public function test_malformed_shape_rejected(): void
    {
        $now = $this->now('2026-07-15 09:00');
        $this->expectException(\InvalidArgumentException::class);
        LoggedOnValidator::parse('nope', $this->tz(), $now);
    }

    public function test_non_existent_calendar_date_rejected(): void
    {
        // 2026-02-30 is not a real date. createFromFormat silently coerces
        // to 2026-03-02; the validator's format-back-equals-input check
        // must catch this.
        $now = $this->now('2026-07-15 09:00');
        $this->expectException(\InvalidArgumentException::class);
        LoggedOnValidator::parse('2026-02-30', $this->tz(), $now);
    }

    public function test_dst_crossing_boundary_stays_calendar_correct(): void
    {
        // Pacific/Auckland DST ends 2026-04-05 (NZDT → NZST). If "now" is
        // 2026-04-10 09:00 NZST, then 7 days ago is 2026-04-03 NZDT — a
        // wall-clock midnight step, NOT 7*86400 seconds. Verify the 7-day
        // boundary respects calendar days across the transition.
        $now = $this->now('2026-04-10 09:00');
        // today - 7d = 2026-04-03 — accepted.
        self::assertSame('2026-04-03', LoggedOnValidator::parse('2026-04-03', $this->tz(), $now));
        // today - 8d = 2026-04-02 — rejected.
        try {
            LoggedOnValidator::parse('2026-04-02', $this->tz(), $now);
            self::fail('expected InvalidArgumentException for 2026-04-02 (8 days ago)');
        } catch (\InvalidArgumentException) {
            // expected
        }
    }

    public function test_whitespace_around_valid_date_stripped_and_accepted(): void
    {
        $now = $this->now('2026-07-15 09:00');
        self::assertSame('2026-07-14', LoggedOnValidator::parse('  2026-07-14  ', $this->tz(), $now));
    }
}
