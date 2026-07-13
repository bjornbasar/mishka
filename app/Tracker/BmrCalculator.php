<?php

declare(strict_types=1);

namespace App\Tracker;

/**
 * v0.8.2 — Mifflin-St Jeor BMR + total-expenditure aggregation.
 *
 * Static-only, no state. Split into two entry points:
 *   - calculate(sex, birthYear, heightCm, weightKg): BMR, or NULL when
 *     any input is missing OR age is implausible (< 5 years).
 *   - expenditure(bmr, baseActivity, exerciseKcalToday): BMR ×
 *     baseActivity + exercise. NULL if BMR is NULL.
 *
 * Formula: BMR = 10·kg + 6.25·cm − 5·age + (male +5 / female −161).
 *
 * Age is `currentYear - birthYear` — TRACKER-PLAN.md §7 chose birth_year
 * INTEGER over full DOB (privacy). The pre-birthday overestimate is < 1%
 * BMR error — accepted trade-off.
 *
 * Double-count trap: baseActivity MUST represent "daily life EXCLUDING
 * deliberate workouts" (~1.2 sedentary → 1.725 very active). The
 * controller's UI teaches this; this calculator just does math.
 *
 * See DOCS.md #72.
 */
final class BmrCalculator
{
    public static function calculate(
        ?string $sex,
        ?int $birthYear,
        ?float $heightCm,
        ?float $weightKg,
        ?int $nowYear = null,
    ): ?int {
        if ($sex === null || $birthYear === null || $heightCm === null || $weightKg === null) {
            return null;
        }
        $nowYear ??= (int) date('Y');
        $age = $nowYear - $birthYear;
        if ($age < 5) {
            // Defence-in-depth: catches fat-fingered current-year birth_year
            // (a 65 kg / 175 cm male with birth_year==currentYear would
            // yield BMR=1748 — legit typo footgun).
            return null;
        }
        $offset = $sex === 'male' ? 5 : -161;
        return (int) round(10 * $weightKg + 6.25 * $heightCm - 5 * $age + $offset);
    }

    public static function expenditure(?int $bmr, float $baseActivity, int $exerciseKcalToday): ?int
    {
        if ($bmr === null) {
            return null;
        }
        return (int) round($bmr * $baseActivity + $exerciseKcalToday);
    }
}
