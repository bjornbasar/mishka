<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Coordinator for series edits. Classifies the diff between old and new
 * series state and routes to cascadeShift / dropAll / no-op alongside the
 * events-row UPDATE. Controllers delegate here; EventService never builds
 * HTTP responses.
 *
 * Three diff classifications:
 *   - cosmetic     — title/description/location/all_day-stays-the-same only.
 *                    Always applies. No dialog.
 *   - time-shift   — starts_at_local moved AND rrule + all_day unchanged.
 *                    With overrides: needs cascadeConfirmed=true to apply
 *                    (the dialog calls cascadeShift on confirm).
 *   - structural   — rrule changed OR all_day flipped. With overrides: needs
 *                    dropConfirmed=true to apply (dropAllForEvent on confirm).
 *
 * Two-step optimistic concurrency:
 *   - $expectedUpdatedAt against events.updated_at — throws
 *     ConcurrentUpdateException on mismatch (single-row-edit race)
 *   - $expectedExceptionCount against current count(*) of event_exceptions —
 *     returns UpdateResult::staleData on mismatch (multi-row-cascade race:
 *     someone added/removed an exception while the dialog was open)
 */
final class EventService
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventExceptionRepository $exceptions,
    ) {}

    /**
     * @param array{title?: string, description?: string, location?: string,
     *              starts_at_local: string, ends_at_local: string,
     *              all_day?: bool, rrule?: ?string, location?: string} $newData
     */
    public function updateSeries(
        int $eventId,
        array $newData,
        string $expectedUpdatedAt,
        bool $cascadeConfirmed,
        bool $dropConfirmed,
        int $expectedExceptionCount,
    ): UpdateResult {
        $old = $this->events->findById($eventId);
        if ($old === null) {
            throw new \RuntimeException("Event {$eventId} not found");
        }

        // Optimistic-concurrency check 1: series row hasn't been touched.
        if ((string) $old['updated_at'] !== $expectedUpdatedAt) {
            throw new ConcurrentUpdateException();
        }

        // Optimistic-concurrency check 2: exception count hasn't changed since
        // the form/dialog was rendered.
        $currentExceptions = $this->exceptions->listForEvent($eventId);
        $currentCount = count($currentExceptions);
        if ($currentCount !== $expectedExceptionCount) {
            return new UpdateResult('stale_data');
        }

        // Classify the diff.
        $newRrule = array_key_exists('rrule', $newData)
            ? ($newData['rrule'] === '' ? null : $newData['rrule'])
            : $old['rrule'];
        $newAllDay = array_key_exists('all_day', $newData)
            ? (bool) $newData['all_day']
            : (bool) $old['all_day'];
        $newStart = (string) $newData['starts_at_local'];

        $isStructural = $newRrule !== $old['rrule'] || $newAllDay !== (bool) $old['all_day'];
        $isTimeShift = !$isStructural && $newStart !== $old['starts_at_local'];

        // Gate destructive paths when there are overrides AND the controller
        // hasn't confirmed via the dialog yet.
        if ($currentCount > 0) {
            if ($isStructural && !$dropConfirmed) {
                return new UpdateResult(
                    'requires_drop_confirm',
                    $currentCount,
                    $this->summariseAffected($currentExceptions),
                );
            }
            if ($isTimeShift && !$cascadeConfirmed) {
                return new UpdateResult(
                    'requires_cascade_confirm',
                    $currentCount,
                    $this->summariseAffected($currentExceptions),
                );
            }
        }

        // Apply: cascade / drop first (in pre-update order so the exceptions
        // see the OLD state), then update the series row.
        if ($currentCount > 0) {
            if ($isStructural && $dropConfirmed) {
                $this->exceptions->dropAllForEvent($eventId);
            } elseif ($isTimeShift && $cascadeConfirmed) {
                $oldStart = new \DateTimeImmutable($old['starts_at_local']);
                $newStartDt = new \DateTimeImmutable($newStart);
                // Use diff() so the delta carries sign through PHP's date math
                // (negative deltas work via the invert flag on DateInterval).
                $delta = $oldStart->diff($newStartDt);
                $this->exceptions->cascadeShift($eventId, $delta);
            }
        }

        $this->events->update($eventId, $newData, $expectedUpdatedAt);

        return new UpdateResult('ok');
    }

    /**
     * @param list<array{original_starts_at: string, override_event_id: ?int, override_event?: ?array<string, mixed>}> $exceptions
     * @return list<array{date: string, summary: string}>
     */
    private function summariseAffected(array $exceptions): array
    {
        $out = [];
        foreach ($exceptions as $ex) {
            if ($ex['override_event_id'] === null) {
                $summary = 'cancelled';
            } else {
                $title = $ex['override_event']['title'] ?? 'override';
                $time = $ex['override_event']['starts_at_local'] ?? '';
                $summary = trim("moved → " . substr($time, 11, 5) . " “{$title}”");
            }
            $out[] = [
                'date' => $ex['original_starts_at'],
                'summary' => $summary,
            ];
        }
        return $out;
    }
}
