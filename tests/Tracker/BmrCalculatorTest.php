<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\BmrCalculator;
use PHPUnit\Framework\TestCase;

final class BmrCalculatorTest extends TestCase
{
    public function test_male_worked_example(): void
    {
        // 70 kg, 175 cm, age 40 (birth 1985, now 2025): 10·70 + 6.25·175 − 5·40 + 5 = 700 + 1093.75 − 200 + 5 = 1598.75 → 1599
        self::assertSame(1599, BmrCalculator::calculate('male', 1985, 175.0, 70.0, 2025));
    }

    public function test_female_worked_example(): void
    {
        // 60 kg, 165 cm, age 35 (birth 1990, now 2025): 10·60 + 6.25·165 − 5·35 + (−161) = 600 + 1031.25 − 175 − 161 = 1295.25 → 1295
        self::assertSame(1295, BmrCalculator::calculate('female', 1990, 165.0, 60.0, 2025));
    }

    public function test_null_input_returns_null(): void
    {
        self::assertNull(BmrCalculator::calculate(null, 1985, 175.0, 70.0));
        self::assertNull(BmrCalculator::calculate('male', null, 175.0, 70.0));
        self::assertNull(BmrCalculator::calculate('male', 1985, null, 70.0));
        self::assertNull(BmrCalculator::calculate('male', 1985, 175.0, null));
    }

    public function test_implausible_age_returns_null(): void
    {
        // birth_year == currentYear → age 0 → guard fires → null
        self::assertNull(BmrCalculator::calculate('male', 2025, 175.0, 70.0, 2025));
        // birth_year currentYear-1 → age 1 → still guarded
        self::assertNull(BmrCalculator::calculate('female', 2024, 165.0, 60.0, 2025));
        // birth_year currentYear-5 → age 5 → NOT guarded (boundary)
        self::assertNotNull(BmrCalculator::calculate('female', 2020, 100.0, 20.0, 2025));
    }

    public function test_now_year_defaults_to_current_year(): void
    {
        // Without explicit nowYear, uses date('Y'). Just assert it returns SOMETHING (not null).
        self::assertIsInt(BmrCalculator::calculate('male', 1985, 175.0, 70.0));
    }

    public function test_expenditure_is_bmr_times_activity_plus_exercise(): void
    {
        // BMR 1500 × 1.2 + 300 exercise = 1800 + 300 = 2100
        self::assertSame(2100, BmrCalculator::expenditure(1500, 1.2, 300));
    }

    public function test_expenditure_null_when_bmr_null(): void
    {
        self::assertNull(BmrCalculator::expenditure(null, 1.2, 300));
    }
}
