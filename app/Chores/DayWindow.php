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
 */
final class DayWindow
{
    private const UTC = 'UTC';

    /** Midnight 00:00 of TODAY in $tz, formatted as a UTC instant. */
    public static function dayStartUtc(\DateTimeZone $tz, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        return self::format($now->setTime(0, 0, 0));
    }

    /**
     * Given a UTC day-start marker, return the marker for the day BEFORE it,
     * computed in $tz (so DST transitions don't drift the result).
     */
    public static function previousDayStartUtc(\DateTimeZone $tz, string $dayStartUtc): string
    {
        $current = new \DateTimeImmutable($dayStartUtc, new \DateTimeZone(self::UTC));
        $previous = $current->setTimezone($tz)->modify('-1 day')->setTime(0, 0, 0);
        return self::format($previous);
    }

    /** N days back from today's start, computed in $tz, formatted as UTC. */
    public static function lookbackStartUtc(\DateTimeZone $tz, int $days, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        $back = $now->setTime(0, 0, 0)->modify('-' . $days . ' days');
        return self::format($back);
    }

    private static function format(\DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new \DateTimeZone(self::UTC))->format('Y-m-d H:i:s');
    }
}
