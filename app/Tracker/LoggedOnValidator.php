<?php

declare(strict_types=1);

namespace App\Tracker;

/**
 * v0.8.4 — shared validator for user-suppliable `logged_on` (or equivalent
 * `measured_on`) DATE fields on the tracker's three POST controllers
 * (FoodLogController, ExerciseLogController, WeightController).
 *
 * Why it exists: v0.8.4's offline-logging IIFE stamps `logged_on` at
 * queue-time on the client so that "the workout I logged Tuesday appears
 * on Tuesday" even after a multi-hour overnight offline gap. The server
 * MUST bound the accepted range — else a broken client clock or a stale
 * replay from months ago would land arbitrary dates.
 *
 * Behaviour:
 *  - Blank / null → delegates to LocalDay::today (unchanged behaviour when
 *    the client omits the field; matches every controller's pre-v0.8.4
 *    fallback semantic).
 *  - Non-blank must match `^\d{4}-\d{2}-\d{2}$` shape.
 *  - Must parse to a real calendar date in the household TZ (rejects
 *    e.g. `2026-02-30`).
 *  - Must be <= household-local today (future-reject).
 *  - Must be >= household-local (today - 7 days), INCLUSIVE. Exactly 7
 *    days ago is accepted; 8 days ago is rejected.
 *
 * DST safety: `->modify('-7 days')` on a DateTimeImmutable anchored in a
 * TZ steps calendar days, NOT 7·86400 seconds. Auckland's NZDT/NZST
 * transitions in April/September are inside the 7-day window sometimes;
 * the calendar-day step keeps the boundary correct at midnight. Do NOT
 * refactor to `sub(new DateInterval('PT168H'))` — that regresses on DST
 * days by an hour and silently drifts the accept-cutoff.
 *
 * Design lock: 7-day cutoff is arbitrary but reasonable for the offline-
 * replay use case (a family device offline for >1 week + still holding
 * the queue is a genuine outlier; probably better to hard-fail those
 * writes at replay time and prompt the user).
 */
final class LoggedOnValidator
{
    /** @throws \InvalidArgumentException on shape / range failure */
    public static function parse(
        ?string $raw,
        \DateTimeZone $tz,
        ?\DateTimeImmutable $now = null,
    ): string {
        $trimmed = $raw !== null ? trim($raw) : '';
        if ($trimmed === '') {
            return LocalDay::today($tz, $now);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) !== 1) {
            throw new \InvalidArgumentException("logged_on must be Y-m-d, got: {$trimmed}");
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed, $tz);
        if ($parsed === false || $parsed->format('Y-m-d') !== $trimmed) {
            // The `format-back-equals-input` check rejects e.g. `2026-02-30`
            // which createFromFormat silently coerces to `2026-03-02`.
            throw new \InvalidArgumentException("logged_on not a real calendar date: {$trimmed}");
        }
        $todayLocal = LocalDay::today($tz, $now);
        if ($trimmed > $todayLocal) {
            throw new \InvalidArgumentException("logged_on cannot be in the future: {$trimmed} > {$todayLocal}");
        }
        $todayDt = \DateTimeImmutable::createFromFormat('!Y-m-d', $todayLocal, $tz);
        if ($todayDt === false) {
            throw new \RuntimeException("LocalDay::today returned unparseable Y-m-d: {$todayLocal}");
        }
        $sevenDaysAgoLocal = $todayDt->modify('-7 days')->format('Y-m-d');
        if ($trimmed < $sevenDaysAgoLocal) {
            throw new \InvalidArgumentException("logged_on more than 7 days old: {$trimmed} < {$sevenDaysAgoLocal}");
        }
        return $trimmed;
    }
}
