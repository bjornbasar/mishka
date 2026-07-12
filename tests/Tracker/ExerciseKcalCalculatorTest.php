<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\ExerciseKcalCalculator;
use PHPUnit\Framework\TestCase;

final class ExerciseKcalCalculatorTest extends TestCase
{
    public function test_met_minutes_is_met_times_minutes(): void
    {
        self::assertSame(70.0, ExerciseKcalCalculator::metMinutes(7.0, 10.0));
        self::assertEqualsWithDelta(122.5, ExerciseKcalCalculator::metMinutes(9.8, 12.5), 0.01);
    }

    public function test_duration_kcal_returns_null_when_weight_null(): void
    {
        self::assertNull(ExerciseKcalCalculator::durationKcal(7.0, 30.0, null));
    }

    public function test_duration_kcal_uses_the_standard_formula(): void
    {
        // 7 × 3.5 × 70 / 200 × 30 = 257.25 → round → 257
        self::assertSame(257, ExerciseKcalCalculator::durationKcal(7.0, 30.0, 70.0));
    }

    public function test_mechanical_work_kcal_null_when_rom_null(): void
    {
        self::assertNull(ExerciseKcalCalculator::mechanicalWorkKcal(20.0, null, 30));
    }

    public function test_mechanical_work_kcal_null_when_reps_or_load_non_positive(): void
    {
        self::assertNull(ExerciseKcalCalculator::mechanicalWorkKcal(0.0, 0.5, 30));
        self::assertNull(ExerciseKcalCalculator::mechanicalWorkKcal(20.0, 0.5, 0));
    }

    public function test_mechanical_work_kcal_formula(): void
    {
        // 0.011723 × 20 × 0.5 × 30 = 3.5169 → round → 4
        self::assertSame(4, ExerciseKcalCalculator::mechanicalWorkKcal(20.0, 0.5, 30));
    }
}
