<?php

declare(strict_types=1);

namespace App\Tracker;

/**
 * v0.8.0 — household-local calendar-date helper.
 *
 * Sibling to App\Chores\WeekWindow and App\Chores\DayWindow (which
 * return UTC ISO strings for week-start / day-start boundaries).
 * LocalDay returns the CURRENT household-local Y-m-d, NOT a boundary
 * timestamp — used exclusively as the `logged_on` value for the
 * tracker's food_log / (future) exercise_log / weight_log rows.
 *
 * Rationale (see DOCS.md #70): `logged_on` is a calendar date, not a
 * timestamp, so the household's IANA timezone drives the boundary.
 * Two families in different timezones eat "lunch on 2026-07-12"
 * differently; storing UTC would rewrite the label at some arbitrary
 * hour offset. Compute in PHP, never in SQL (`CURRENT_DATE`/`NOW()`
 * consult the DB session TZ which is wrong).
 *
 * Callers access the household TZ via
 * `HouseholdRepository::findById($hid)['timezone']` — there is no
 * `::timezone()` method.
 */
final class LocalDay
{
    /**
     * Household-local calendar date at `$now` (default: real "now").
     *
     * @param \DateTimeZone            $tz   household's IANA timezone
     * @param \DateTimeImmutable|null  $now  test-injectable clock; defaults to real now
     * @return string                        Y-m-d in the household's local calendar
     */
    public static function today(\DateTimeZone $tz, ?\DateTimeImmutable $now = null): string
    {
        return ($now ?? new \DateTimeImmutable('now', $tz))
            ->setTimezone($tz)
            ->format('Y-m-d');
    }
}
