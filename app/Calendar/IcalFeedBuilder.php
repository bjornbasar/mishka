<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Household\HouseholdRepository;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;

/**
 * Build a VCALENDAR document for a user's iCal feed subscription.
 *
 * Range cap (locked round-3): include events whose first occurrence falls
 * within [-1 month, +1 year] of "now", OR events with an open-ended RRULE
 * (no UNTIL/COUNT — phone calendars expand client-side, so emitting the
 * raw RRULE keeps the schedule complete without us pre-expanding it).
 *
 * VTIMEZONE: a minimal block per unique event timezone (TZID + STANDARD
 * subcomponent with the current standard-time offset). Modern clients
 * (Apple Calendar, Google Calendar, Outlook 2016+, Thunderbird) resolve
 * the TZID against their own tzdb if the embedded VTIMEZONE is sparse,
 * so this is the pragmatic v0.3.2 first cut — proper DST-transition
 * tables can land later if any client misbehaves.
 *
 * Override events: emitted as separate VEVENT with matching UID + a
 * RECURRENCE-ID property pointing at the original occurrence's date+time
 * (TZID-qualified). RFC 5545 compliant; clients render this in place of
 * the series occurrence rather than as a duplicate. eluceo/ical 2.x can't
 * emit RECURRENCE-ID — that's why we use sabre/vobject.
 *
 * Cancellations: surfaced as EXDATE values on the series VEVENT.
 */
final class IcalFeedBuilder
{
    private const RANGE_PAST_DAYS = 31;
    private const RANGE_FUTURE_DAYS = 366;

    public function __construct(
        private readonly EventRepository $events,
        private readonly EventExceptionRepository $exceptions,
        private readonly HouseholdRepository $households,
    ) {}

    /** Build + serialise. Convenience wrapper for the controller. */
    public function renderForUser(int $userId, ?int $scopeHouseholdId = null): string
    {
        return $this->buildForUser($userId, $scopeHouseholdId)->serialize();
    }

    public function buildForUser(int $userId, ?int $scopeHouseholdId = null): VCalendar
    {
        $vcal = new VCalendar();
        $vcal->add('PRODID', '-//bjornbasar//mishka 0.3.2//EN');
        $vcal->add('VERSION', '2.0');

        $memberships = $this->households->listForUser($userId);
        if ($memberships === []) {
            return $vcal;
        }

        $now = new \DateTimeImmutable('now');
        $rangeStart = $now->modify('-' . self::RANGE_PAST_DAYS . ' days');
        $rangeEnd = $now->modify('+' . self::RANGE_FUTURE_DAYS . ' days');

        $usedTimezones = [];

        foreach ($memberships as $membership) {
            $hid = (int) $membership['id'];
            if ($scopeHouseholdId !== null && $hid !== $scopeHouseholdId) {
                continue;
            }

            $oneOffs = $this->events->findInRangeForHousehold($hid, $rangeStart, $rangeEnd);
            $recurring = $this->events->findRecurringForHousehold($hid);

            foreach ($oneOffs as $event) {
                $this->emitOneOff($vcal, $event);
                $usedTimezones[$event['timezone']] = true;
            }

            foreach ($recurring as $series) {
                $this->emitSeries($vcal, $series);
                $usedTimezones[$series['timezone']] = true;
            }
        }

        // Embed a VTIMEZONE block per unique zone. Inserted AFTER the events
        // so we don't iterate them twice, but sabre/vobject's serialiser
        // emits VTIMEZONE components before non-VTIMEZONE per Component.php
        // line 287-289 — so the output order is RFC-correct regardless.
        foreach (array_keys($usedTimezones) as $tzName) {
            $this->emitVtimezone($vcal, (string) $tzName);
        }

        return $vcal;
    }

    /** @param array<string, mixed> $event */
    private function emitOneOff(VCalendar $vcal, array $event): void
    {
        $tz = new \DateTimeZone((string) $event['timezone']);
        $start = new \DateTimeImmutable((string) $event['starts_at_local'], $tz);
        $end = new \DateTimeImmutable((string) $event['ends_at_local'], $tz);

        $vevent = $vcal->add('VEVENT', [
            'UID' => $this->uidFor((int) $event['id']),
            'SUMMARY' => (string) $event['title'],
            'DTSTART' => $start,
            'DTEND' => $end,
        ]);
        assert($vevent instanceof Component);

        $description = (string) ($event['description'] ?? '');
        if ($description !== '') {
            $vevent->add('DESCRIPTION', $description);
        }
        $location = (string) ($event['location'] ?? '');
        if ($location !== '') {
            $vevent->add('LOCATION', $location);
        }
    }

    /** @param array<string, mixed> $series */
    private function emitSeries(VCalendar $vcal, array $series): void
    {
        $tz = new \DateTimeZone((string) $series['timezone']);
        $start = new \DateTimeImmutable((string) $series['starts_at_local'], $tz);
        $end = new \DateTimeImmutable((string) $series['ends_at_local'], $tz);

        $vevent = $vcal->add('VEVENT', [
            'UID' => $this->uidFor((int) $series['id']),
            'SUMMARY' => (string) $series['title'],
            'DTSTART' => $start,
            'DTEND' => $end,
        ]);
        assert($vevent instanceof Component);
        $vevent->add('RRULE', (string) $series['rrule']);

        $description = (string) ($series['description'] ?? '');
        if ($description !== '') {
            $vevent->add('DESCRIPTION', $description);
        }
        $location = (string) ($series['location'] ?? '');
        if ($location !== '') {
            $vevent->add('LOCATION', $location);
        }

        $exceptions = $this->exceptions->listForEvent((int) $series['id']);
        $exdates = [];
        foreach ($exceptions as $ex) {
            $occLocal = new \DateTimeImmutable($ex['original_starts_at'], $tz);
            if ($ex['override_event_id'] === null) {
                $exdates[] = $occLocal;
            } else {
                // RECURRENCE-ID override emitted as its own VEVENT with the
                // same UID as the series.
                $overrideEvent = $this->events->findById($ex['override_event_id']);
                if ($overrideEvent !== null) {
                    $this->emitOverride($vcal, $series, $overrideEvent, $occLocal, $tz);
                }
            }
        }

        if ($exdates !== []) {
            // sabre/vobject's add() with an array of DateTimeImmutable produces
            // multi-valued EXDATE in one property line (RFC 5545 conformant).
            foreach ($exdates as $exd) {
                $vevent->add('EXDATE', $exd);
            }
        }
    }

    /**
     * @param array<string, mixed> $series
     * @param array<string, mixed> $overrideEvent
     */
    private function emitOverride(
        VCalendar $vcal,
        array $series,
        array $overrideEvent,
        \DateTimeImmutable $originalOcc,
        \DateTimeZone $tz,
    ): void {
        $overrideStart = new \DateTimeImmutable((string) $overrideEvent['starts_at_local'], $tz);
        $overrideEnd = new \DateTimeImmutable((string) $overrideEvent['ends_at_local'], $tz);

        $vevent = $vcal->add('VEVENT', [
            'UID' => $this->uidFor((int) $series['id']),
            'SUMMARY' => (string) $overrideEvent['title'],
            'DTSTART' => $overrideStart,
            'DTEND' => $overrideEnd,
        ]);
        assert($vevent instanceof Component);
        $vevent->add('RECURRENCE-ID', $originalOcc);

        $description = (string) ($overrideEvent['description'] ?? '');
        if ($description !== '') {
            $vevent->add('DESCRIPTION', $description);
        }
        $location = (string) ($overrideEvent['location'] ?? '');
        if ($location !== '') {
            $vevent->add('LOCATION', $location);
        }
    }

    /**
     * Minimal RFC 5545 VTIMEZONE block. Carries the TZID + a single STANDARD
     * subcomponent reflecting the current standard-time offset. Modern clients
     * (Apple Calendar, Google Calendar, Outlook 2016+, Thunderbird) resolve the
     * TZID against their own tzdb if the embedded block doesn't carry the full
     * DST-transition history, so this satisfies the RFC envelope without the
     * cost of full transition generation.
     */
    private function emitVtimezone(VCalendar $vcal, string $tzName): void
    {
        $vtz = $vcal->add('VTIMEZONE', ['TZID' => $tzName]);
        assert($vtz instanceof Component);

        $now = new \DateTimeImmutable('now', new \DateTimeZone($tzName));
        $offsetMinutes = (int) ($now->getOffset() / 60);
        $offsetString = sprintf('%+03d%02d', intdiv($offsetMinutes, 60), abs($offsetMinutes) % 60);

        // STANDARD isn't in VCalendar's componentMap so $vtz->add('STANDARD')
        // would return a Property by default — explicitly create a generic
        // Component subclass instead. This is how sabre/vobject expects
        // sub-components to be created when they don't have a dedicated class.
        $standard = $vcal->createComponent('STANDARD');
        $vtz->add($standard);
        $standard->add('DTSTART', '19700101T000000');
        $standard->add('TZOFFSETFROM', $offsetString);
        $standard->add('TZOFFSETTO', $offsetString);
        $standard->add('TZNAME', $tzName);
    }

    private function uidFor(int $eventId): string
    {
        return "mishka-event-{$eventId}@bjornbasar/mishka";
    }
}
