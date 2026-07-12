<?php

declare(strict_types=1);

namespace App\Tracker;

/**
 * v0.8.1 — kcal + met-minutes formulas for exercise_log.
 *
 * Architecturally split by branch. No set-rep → minutes conversion
 * (user-locked at plan-time): exercises are either duration OR strength;
 * their storage AND computation stay distinct.
 *
 * Duration branch:
 *   met_minutes = MET × minutes                  (weight-independent — v0.8.3 leaderboard currency)
 *   kcal        = MET × 3.5 × weight_kg ÷ 200 × minutes    (weight-dependent — NULL if weight unknown)
 *
 * Strength branch:
 *   met_minutes = NULL                           (not derived from sets/reps)
 *   kcal        = 0.011723 × load_kg × ROM_m × reps        (weight-independent; NULL if ROM unknown)
 *
 * Formulas per TRACKER-PLAN.md §4. See DOCS.md #71.
 */
final class ExerciseKcalCalculator
{
    /** MET × minutes — weight-independent effort. Duration branch only. */
    public static function metMinutes(float $met, float $minutes): float
    {
        return $met * $minutes;
    }

    /**
     * Duration branch kcal. Returns null when weight is unknown so the
     * historical row honestly reflects "we didn't know weight at write
     * time" rather than guessing. Later weight entries do NOT retro-
     * populate historical NULLs (snapshot semantics — DOCS #31).
     */
    public static function durationKcal(float $met, float $minutes, ?float $weightKg): ?int
    {
        if ($weightKg === null) {
            return null;
        }
        return (int) round($met * 3.5 * $weightKg / 200.0 * $minutes);
    }

    /**
     * Strength branch mechanical-work kcal. Returns null when the
     * exercise catalog has no default_rom_m (Compendium doesn't
     * document ROM for every activity — bodyweight exercises, etc.).
     * Weight-independent (no user weight in the formula).
     */
    public static function mechanicalWorkKcal(float $loadKg, ?float $romM, int $reps): ?int
    {
        if ($romM === null || $reps <= 0 || $loadKg <= 0) {
            return null;
        }
        return (int) round(0.011723 * $loadKg * $romM * $reps);
    }
}
