<?php

declare(strict_types=1);

namespace App\Calendar;

use Karhu\Db\Connection;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;

/**
 * Expand the household's events into a list of (event, occurrence) pairs for
 * a date range, applying cancellations and overrides.
 *
 * Algorithm:
 *   1. SELECT all events for the household that EITHER have an rrule (recurring
 *      series) OR are one-off rows in range AND series_event_id IS NULL.
 *      The series_event_id IS NULL filter is the BLOCKING-bug-fix from round-3
 *      review: override Event rows have series_event_id != NULL, so they'd
 *      otherwise match the one-off branch AND get emitted twice (once as
 *      themselves, once as substitutes from the override-substitution path).
 *
 *   2. For each recurring series:
 *        a. Expand with simshaun/recurr in the event's timezone (preserves wall-
 *           clock time across DST).
 *        b. Clip to [rangeStart, rangeEnd].
 *        c. Apply cancellations: drop occurrences whose local time matches an
 *           event_exceptions row with override_event_id IS NULL.
 *        d. Apply overrides: for occurrences whose local time matches an
 *           event_exceptions row with override_event_id IS NOT NULL, substitute
 *           the override Event row + its starts_at_local as the occurrence time.
 *
 *   3. For each one-off event whose [starts_at_local, ends_at_local] intersects
 *      the range, emit as-is.
 *
 *   4. Sort by occurrence ascending.
 *
 * Virtual limit: max(31, range_days + 31) — sized to the query rather than a
 * fixed 366. Caps runaway DAILY rules without over-expanding the typical
 * month-grid window.
 */
final class RangeExpander
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventExceptionRepository $exceptions,
    ) {}

    /**
     * @return list<array{
     *     event: array<string, mixed>,
     *     occurrence: \DateTimeImmutable,
     *     occurrence_end: \DateTimeImmutable,
     *     is_override: bool,
     * }>
     */
    public function expandForHousehold(
        int $householdId,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): array {
        // Pull every series (rrule IS NOT NULL) for the household + every one-off
        // event whose window intersects the range. Override events are excluded
        // by `series_event_id IS NULL` so they only enter via the substitution path.
        $rows = $this->fetchRelevantEvents($householdId, $rangeStart, $rangeEnd);

        $out = [];
        $virtualLimit = max(31, (int) $rangeStart->diff($rangeEnd)->days + 31);

        foreach ($rows as $row) {
            if ($row['rrule'] !== null && $row['rrule'] !== '') {
                $exceptions = $this->exceptions->listForEvent((int) $row['id']);
                $cancellations = $this->indexCancellations($exceptions);
                $overrides = $this->indexOverrides($exceptions);

                foreach ($this->expandSeries($row, $virtualLimit) as $occ) {
                    if ($occ < $rangeStart || $occ > $rangeEnd) {
                        continue;
                    }
                    $key = $occ->format('Y-m-d H:i:s');
                    if (isset($cancellations[$key])) {
                        continue;
                    }
                    if (isset($overrides[$key])) {
                        $overrideEvent = $this->events->findById($overrides[$key]);
                        if ($overrideEvent !== null) {
                            $tz = new \DateTimeZone((string) $overrideEvent['timezone']);
                            $out[] = [
                                'event' => $overrideEvent,
                                'occurrence' => new \DateTimeImmutable((string) $overrideEvent['starts_at_local'], $tz),
                                'occurrence_end' => new \DateTimeImmutable((string) $overrideEvent['ends_at_local'], $tz),
                                'is_override' => true,
                            ];
                        }
                        continue;
                    }
                    $duration = $this->durationOf($row);
                    $out[] = [
                        'event' => $row,
                        'occurrence' => $occ,
                        'occurrence_end' => $occ->add($duration),
                        'is_override' => false,
                    ];
                }
                continue;
            }

            // One-off event — emit as-is.
            $tz = new \DateTimeZone((string) $row['timezone']);
            $out[] = [
                'event' => $row,
                'occurrence' => new \DateTimeImmutable((string) $row['starts_at_local'], $tz),
                'occurrence_end' => new \DateTimeImmutable((string) $row['ends_at_local'], $tz),
                'is_override' => false,
            ];
        }

        usort(
            $out,
            fn(array $a, array $b): int => $a['occurrence']->getTimestamp() <=> $b['occurrence']->getTimestamp(),
        );

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRelevantEvents(
        int $householdId,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): array {
        // Get the underlying Connection from EventRepository's $db. Cleanest path:
        // expose findInRangeForHousehold for the one-off branch, then a separate
        // findRecurringForHousehold for the rrule branch. Tighter coupling than I
        // like; keeps the SQL in EventRepository where it belongs.
        $oneOff = $this->events->findInRangeForHousehold($householdId, $rangeStart, $rangeEnd);
        $recurring = $this->events->findRecurringForHousehold($householdId);

        return array_merge($oneOff, $recurring);
    }

    /**
     * Run a single series through simshaun/recurr.
     *
     * @param array<string, mixed> $event
     * @return list<\DateTimeImmutable>
     */
    private function expandSeries(array $event, int $virtualLimit): array
    {
        $tz = new \DateTimeZone((string) $event['timezone']);
        $start = new \DateTimeImmutable((string) $event['starts_at_local'], $tz);

        $rule = new Rule((string) $event['rrule'], $start);

        $config = new ArrayTransformerConfig();
        $config->setVirtualLimit($virtualLimit);

        $transformer = new ArrayTransformer($config);
        $recurrences = $transformer->transform($rule);

        $out = [];
        foreach ($recurrences as $r) {
            $rStart = $r->getStart();
            $out[] = $rStart instanceof \DateTimeImmutable
                ? $rStart
                : \DateTimeImmutable::createFromMutable($rStart);
        }
        return $out;
    }

    /**
     * @param list<array{original_starts_at: string, override_event_id: ?int}> $exceptions
     * @return array<string, true>
     */
    private function indexCancellations(array $exceptions): array
    {
        $idx = [];
        foreach ($exceptions as $ex) {
            if ($ex['override_event_id'] === null) {
                $idx[$ex['original_starts_at']] = true;
            }
        }
        return $idx;
    }

    /**
     * @param list<array{original_starts_at: string, override_event_id: ?int}> $exceptions
     * @return array<string, int>
     */
    private function indexOverrides(array $exceptions): array
    {
        $idx = [];
        foreach ($exceptions as $ex) {
            if ($ex['override_event_id'] !== null) {
                $idx[$ex['original_starts_at']] = $ex['override_event_id'];
            }
        }
        return $idx;
    }

    /** @param array<string, mixed> $event */
    private function durationOf(array $event): \DateInterval
    {
        $tz = new \DateTimeZone((string) $event['timezone']);
        $start = new \DateTimeImmutable((string) $event['starts_at_local'], $tz);
        $end = new \DateTimeImmutable((string) $event['ends_at_local'], $tz);
        return $start->diff($end);
    }
}
