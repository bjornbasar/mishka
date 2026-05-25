<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\RruleTranslator;
use PHPUnit\Framework\TestCase;

final class RruleTranslatorTest extends TestCase
{
    private RruleTranslator $t;
    private \DateTimeImmutable $startDate;

    protected function setUp(): void
    {
        $this->t = new RruleTranslator();
        $this->startDate = new \DateTimeImmutable('2026-07-14 09:00:00', new \DateTimeZone('Pacific/Auckland'));
    }

    public function test_none_returns_null(): void
    {
        self::assertNull($this->t->fromForm(['preset' => 'none'], $this->startDate));
    }

    public function test_daily(): void
    {
        self::assertSame('FREQ=DAILY', $this->t->fromForm(['preset' => 'daily'], $this->startDate));
    }

    public function test_weekly_byday(): void
    {
        $rrule = $this->t->fromForm(
            ['preset' => 'weekly', 'byday' => ['MO', 'TH']],
            $this->startDate,
        );
        self::assertSame('FREQ=WEEKLY;BYDAY=MO,TH', $rrule);
    }

    public function test_weekly_defaults_to_start_dates_weekday_when_empty(): void
    {
        // 2026-07-14 is a Tuesday — TU should be the default
        $rrule = $this->t->fromForm(['preset' => 'weekly'], $this->startDate);
        self::assertSame('FREQ=WEEKLY;BYDAY=TU', $rrule);
    }

    public function test_weekly_with_interval(): void
    {
        $rrule = $this->t->fromForm(
            ['preset' => 'weekly', 'byday' => ['FR'], 'interval' => 2],
            $this->startDate,
        );
        self::assertSame('FREQ=WEEKLY;INTERVAL=2;BYDAY=FR', $rrule);
    }

    public function test_monthly_with_day(): void
    {
        $rrule = $this->t->fromForm(
            ['preset' => 'monthly', 'monthly_day' => 15],
            $this->startDate,
        );
        self::assertSame('FREQ=MONTHLY;BYMONTHDAY=15', $rrule);
    }

    public function test_monthly_clamps_day_above_28(): void
    {
        $rrule = $this->t->fromForm(
            ['preset' => 'monthly', 'monthly_day' => 31],
            $this->startDate,
        );
        self::assertSame('FREQ=MONTHLY;BYMONTHDAY=28', $rrule);
    }

    public function test_monthly_defaults_to_start_dates_day_clamped(): void
    {
        // Start is the 14th — clamped (no need; under 28)
        $rrule = $this->t->fromForm(['preset' => 'monthly'], $this->startDate);
        self::assertSame('FREQ=MONTHLY;BYMONTHDAY=14', $rrule);
    }

    public function test_yearly_emits_only_freq(): void
    {
        // FREQ=YEARLY alone — recurr derives month/day from DTSTART. Editing
        // start date naturally moves the yearly recurrence with it.
        self::assertSame('FREQ=YEARLY', $this->t->fromForm(['preset' => 'yearly'], $this->startDate));
    }

    public function test_to_form_round_trip_weekly(): void
    {
        $form = $this->t->toForm('FREQ=WEEKLY;BYDAY=MO,WE,FR');
        self::assertSame('weekly', $form['preset']);
        self::assertSame(['MO', 'WE', 'FR'], $form['byday']);
    }

    public function test_to_form_returns_custom_for_unsupported_rrule(): void
    {
        $form = $this->t->toForm('FREQ=WEEKLY;BYSETPOS=-1;BYDAY=MO,TU,WE,TH,FR');
        self::assertSame('custom', $form['preset']);
    }

    public function test_to_form_returns_none_for_null(): void
    {
        self::assertSame('none', $this->t->toForm(null)['preset']);
        self::assertSame('none', $this->t->toForm('')['preset']);
    }
}
