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

    // ============================================================
    // Monthly positional day-of-week (e.g., "first Friday of the month")
    // ============================================================

    public function test_monthly_dow_first_friday(): void
    {
        $rrule = $this->t->fromForm([
            'preset' => 'monthly',
            'monthly_mode' => 'dow',
            'monthly_dow_position' => 1,
            'monthly_dow_day' => 'FR',
        ], $this->startDate);
        self::assertSame('FREQ=MONTHLY;BYDAY=1FR', $rrule);
    }

    public function test_monthly_dow_last_sunday(): void
    {
        $rrule = $this->t->fromForm([
            'preset' => 'monthly',
            'monthly_mode' => 'dow',
            'monthly_dow_position' => -1,
            'monthly_dow_day' => 'SU',
        ], $this->startDate);
        self::assertSame('FREQ=MONTHLY;BYDAY=-1SU', $rrule);
    }

    public function test_monthly_dow_with_interval(): void
    {
        $rrule = $this->t->fromForm([
            'preset' => 'monthly',
            'monthly_mode' => 'dow',
            'monthly_dow_position' => 2,
            'monthly_dow_day' => 'TU',
            'interval' => 3,
        ], $this->startDate);
        self::assertSame('FREQ=MONTHLY;INTERVAL=3;BYDAY=2TU', $rrule);
    }

    public function test_monthly_dow_out_of_range_position_falls_back_to_first(): void
    {
        // RFC technically allows 5 (5th-of-month) but we excluded it (UX
        // decision: 5th creates unpredictable gaps). Out-of-range coerces to 1.
        $rrule = $this->t->fromForm([
            'preset' => 'monthly',
            'monthly_mode' => 'dow',
            'monthly_dow_position' => 5,
            'monthly_dow_day' => 'WE',
        ], $this->startDate);
        self::assertSame('FREQ=MONTHLY;BYDAY=1WE', $rrule);
    }

    public function test_monthly_dow_garbage_day_falls_back_to_anchor_weekday(): void
    {
        // Anchor is 2026-07-14, a Tuesday → BYDAY day fallback is TU.
        $rrule = $this->t->fromForm([
            'preset' => 'monthly',
            'monthly_mode' => 'dow',
            'monthly_dow_position' => 1,
            'monthly_dow_day' => 'XX',
        ], $this->startDate);
        self::assertSame('FREQ=MONTHLY;BYDAY=1TU', $rrule);
    }

    public function test_monthly_day_mode_still_emits_bymonthday(): void
    {
        // Regression — extending the translator must not break the existing
        // BYMONTHDAY path.
        $rrule = $this->t->fromForm([
            'preset' => 'monthly',
            'monthly_mode' => 'day',
            'monthly_day' => 15,
        ], $this->startDate);
        self::assertSame('FREQ=MONTHLY;BYMONTHDAY=15', $rrule);
    }

    public function test_to_form_round_trip_monthly_dow(): void
    {
        $form = $this->t->toForm('FREQ=MONTHLY;BYDAY=1FR');
        self::assertSame('monthly', $form['preset']);
        self::assertSame('dow', $form['monthly_mode']);
        self::assertSame(1, $form['monthly_dow_position']);
        self::assertSame('FR', $form['monthly_dow_day']);
    }

    public function test_to_form_round_trip_monthly_dow_last_sunday(): void
    {
        $form = $this->t->toForm('FREQ=MONTHLY;BYDAY=-1SU');
        self::assertSame('monthly', $form['preset']);
        self::assertSame('dow', $form['monthly_mode']);
        self::assertSame(-1, $form['monthly_dow_position']);
        self::assertSame('SU', $form['monthly_dow_day']);
    }

    public function test_to_form_round_trip_monthly_day_preserves_mode(): void
    {
        $form = $this->t->toForm('FREQ=MONTHLY;BYMONTHDAY=15');
        self::assertSame('monthly', $form['preset']);
        self::assertSame('day', $form['monthly_mode']);
        self::assertSame(15, $form['monthly_day']);
    }

    public function test_to_form_monthly_multi_day_byday_falls_back_to_custom(): void
    {
        // `BYDAY=MO,WE` under MONTHLY isn't a shape either monthly sub-mode
        // can round-trip (weekly recurrence inside MONTHLY) — bounce to custom.
        $form = $this->t->toForm('FREQ=MONTHLY;BYDAY=MO,WE');
        self::assertSame('custom', $form['preset']);
    }

    public function test_to_form_monthly_with_both_byday_and_bymonthday_falls_back_to_custom(): void
    {
        // Ambiguous combo — not representable cleanly in the form's two-radio
        // model. Mark as custom so the edit form preserves the raw RRULE.
        $form = $this->t->toForm('FREQ=MONTHLY;BYDAY=1FR;BYMONTHDAY=13');
        self::assertSame('custom', $form['preset']);
    }
}
