<?php

declare(strict_types=1);

namespace App\Chores;

/**
 * DST-safe daily-window arithmetic (v0.6.14).
 *
 * Mirrors WeekWindow's posture (decision #34) for day granularity: the daily-
 * streak walk + the 30-day-streak BadgeAwarder threshold both need "midnight
 * 00:00 in the household timezone, formatted as a UTC instant string." NZ
 * shifts UTC+13↔UTC+12 twice a year, so adjacent midnight-NZ markers are
 * 25 UTC-hours apart at the end of DST and 23 UTC-hours apart at the start —
 * naive `-86400 seconds` on a UTC string drifts by ±1 hour across every
 * transition and would silently break the streak walk on the transition day.
 *
 * Every step here does its arithmetic IN the household tz
 * (`->modify('-1 day')`, `->setTime(0, 0, 0)`) and only converts to UTC
 * for the final string representation, so the result always lands on the
 * correct midnight-household-day marker regardless of DST.
 *
 * Output is a `Y-m-d H:i:s` UTC string — directly comparable to v0.4.2's
 * `chore_points_ledger.completed_at` on both PostgreSQL (TIMESTAMPTZ) and
 * SQLite (TEXT) via lexicographic compare.
 *
 * v0.8.3 — local-DATE siblings (`dayStartLocal / previousDayStartLocal /
 * lookbackStartLocal`) return household-local `Y-m-d` strings for use in
 * `WHERE logged_on = :day` predicates. Tracker's `exercise_log.logged_on`
 * is a household-local DATE (via `LocalDay::today($tz)`); use the local
 * siblings there and the UTC family for TIMESTAMPTZ instants like
 * `chore_points_ledger.completed_at`.
 */
final class DayWindow
{
    private const UTC = 'UTC';

    /** Midnight 00:00 of TODAY in $tz, formatted as a UTC instant. */
    public static function dayStartUtc(\DateTimeZone $tz, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        return self::formatUtc($now->setTime(0, 0, 0));
    }

    /**
     * Given a UTC day-start marker, return the marker for the day BEFORE it,
     * computed in $tz (so DST transitions don't drift the result).
     */
    public static function previousDayStartUtc(\DateTimeZone $tz, string $dayStartUtc): string
    {
        $current = new \DateTimeImmutable($dayStartUtc, new \DateTimeZone(self::UTC));
        $previous = $current->setTimezone($tz)->modify('-1 day')->setTime(0, 0, 0);
        return self::formatUtc($previous);
    }

    /** N days back from today's start, computed in $tz, formatted as UTC. */
    public static function lookbackStartUtc(\DateTimeZone $tz, int $days, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        $back = $now->setTime(0, 0, 0)->modify('-' . $days . ' days');
        return self::formatUtc($back);
    }

    /**
     * v0.8.3 — today's household-local `Y-m-d` DATE string. Matches how
     * `App\Tracker\LocalDay::today()` derives `exercise_log.logged_on` at
     * write-time — use this at read-time for axis consistency.
     */
    public static function dayStartLocal(\DateTimeZone $tz, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        return $now->format('Y-m-d');
    }

    /**
     * v0.8.3 — the day BEFORE `$dayStartLocal`, computed in $tz. DST-safe
     * (arithmetic done on wall-clock midnight in $tz).
     */
    public static function previousDayStartLocal(\DateTimeZone $tz, string $dayStartLocal): string
    {
        $current = \DateTimeImmutable::createFromFormat('!Y-m-d', $dayStartLocal, $tz);
        if ($current === false) {
            throw new \InvalidArgumentException("dayStartLocal not Y-m-d parseable: {$dayStartLocal}");
        }
        return $current->modify('-1 day')->format('Y-m-d');
    }

    /**
     * v0.8.3 — N days back from today, formatted as household-local `Y-m-d`.
     * Sister of {@see lookbackStartUtc}.
     */
    public static function lookbackStartLocal(\DateTimeZone $tz, int $days, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        return $now->modify('-' . $days . ' days')->format('Y-m-d');
    }

    private static function formatUtc(\DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new \DateTimeZone(self::UTC))->format('Y-m-d H:i:s');
    }
}
