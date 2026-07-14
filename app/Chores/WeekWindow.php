<?php

declare(strict_types=1);

namespace App\Chores;

/**
 * DST-safe weekly-window arithmetic (v0.4.3).
 *
 * The leaderboard, streak walks, and lookback floor all need "Monday 00:00 in the
 * household timezone, formatted as a UTC instant string." NZ shifts UTC+13↔UTC+12
 * twice a year, so adjacent Monday-NZ markers are 169 UTC-hours apart at the end
 * of DST and 167 UTC-hours apart at the start — naive `−7d` on a UTC string drifts
 * by exactly one hour across every transition and silently breaks streaks.
 *
 * Every step here does its arithmetic IN the household tz (`->modify('-1 week')`,
 * `->modify('monday this week')`, `->setTime(0, 0, 0)`) and only converts to UTC
 * for the final string representation, so the result always lands on the
 * correct Monday-household-midnight marker regardless of DST.
 *
 * Output is a `Y-m-d H:i:s` UTC string — directly comparable to v0.4.2's
 * `chore_points_ledger.completed_at` on both PostgreSQL (TIMESTAMPTZ) and
 * SQLite (TEXT) via lexicographic compare.
 *
 * v0.8.3 — local-DATE siblings (`weekStartLocal / weekEndLocal /
 * lookbackStartLocal`) return household-local `Y-m-d` strings for use in
 * `WHERE logged_on >= :start AND logged_on < :end` predicates. Tracker's
 * `exercise_log.logged_on` is a DATE anchored in household-local time
 * (via `LocalDay::today($tz)` at write); the local siblings match that axis.
 * Use the UTC family for TIMESTAMPTZ columns (`chore_points_ledger.completed_at`).
 * Same Monday-start rule, same DST-safe arithmetic — only the output
 * representation differs.
 */
final class WeekWindow
{
    private const UTC = 'UTC';

    /** Monday 00:00 of the current week in $tz, formatted as a UTC instant. */
    public static function weekStartUtc(\DateTimeZone $tz, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        return self::formatUtc(self::mondayThisWeek($now));
    }

    /**
     * Given a UTC week-start marker, return the marker for the week BEFORE it,
     * computed in $tz (so DST transitions don't drift the result).
     */
    public static function previousWeekStartUtc(\DateTimeZone $tz, string $weekStartUtc): string
    {
        $current = new \DateTimeImmutable($weekStartUtc, new \DateTimeZone(self::UTC));
        $previous = $current->setTimezone($tz)->modify('-1 week')->setTime(0, 0, 0);
        return self::formatUtc($previous);
    }

    /** N weeks back from this week's start, computed in $tz, formatted as UTC. */
    public static function lookbackStartUtc(\DateTimeZone $tz, int $weeks, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        $back = self::mondayThisWeek($now)->modify('-' . $weeks . ' weeks');
        return self::formatUtc($back);
    }

    /**
     * v0.8.3 — Monday 00:00 of the current week in $tz, formatted as a
     * household-local `Y-m-d` DATE string. Sister of {@see weekStartUtc}
     * for use with `logged_on` DATE columns (`exercise_log`).
     */
    public static function weekStartLocal(\DateTimeZone $tz, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        return self::mondayThisWeek($now)->format('Y-m-d');
    }

    /**
     * v0.8.3 — exclusive end of the week (weekStart + 7 days) as household-local
     * `Y-m-d` DATE string. Use with `WHERE logged_on >= :ws AND logged_on < :we`.
     * Computed in $tz (DST-safe) — the +7-day arithmetic on a wall-clock
     * midnight lands on the following Monday's wall-clock midnight regardless
     * of a DST transition inside the week.
     */
    public static function weekEndLocal(\DateTimeZone $tz, string $weekStartLocal): string
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $weekStartLocal, $tz);
        if ($start === false) {
            throw new \InvalidArgumentException("weekStartLocal not Y-m-d parseable: {$weekStartLocal}");
        }
        return $start->modify('+1 week')->format('Y-m-d');
    }

    /**
     * v0.8.3 — N weeks back from this week's Monday, formatted as household-local
     * `Y-m-d` DATE string. Sister of {@see lookbackStartUtc}.
     */
    public static function lookbackStartLocal(\DateTimeZone $tz, int $weeks, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        return self::mondayThisWeek($now)->modify('-' . $weeks . ' weeks')->format('Y-m-d');
    }

    /** Anchor at Monday 00:00 in the dt's current timezone. */
    private static function mondayThisWeek(\DateTimeImmutable $dt): \DateTimeImmutable
    {
        return $dt->modify('monday this week')->setTime(0, 0, 0);
    }

    private static function formatUtc(\DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new \DateTimeZone(self::UTC))->format('Y-m-d H:i:s');
    }
}
