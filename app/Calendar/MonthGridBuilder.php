<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Build a 6×7 month grid for the calendar UI. The output is a pure data
 * structure consumed by `templates/calendar/month.twig` — keeping slot
 * assignment + day computation in PHP (not Twig) makes the algorithm
 * unit-testable independently of HTML.
 *
 * Slot assignment: a multi-day event sits on the same horizontal "stripe"
 * (slot index) across its cells so the connected-pill illusion holds. The
 * algorithm is greedy first-fit per occurrence, ordered by (start ASC,
 * duration DESC) so longer events claim lower slots first.
 *
 * Overflow: cells with >2 events render 2 real pills + a "+N more" pill
 * with `overflow_count = N`. Click target lives in the template.
 *
 * Always emits 6 rows so the layout is stable across months (some months
 * span 5 weeks, some 6 — padding to 6 dodges the layout-jitter that the
 * 5-week months would otherwise cause).
 *
 * Week-start is Monday (NZ convention).
 */
final class MonthGridBuilder
{
    private const ROWS = 6;
    private const COLS = 7;
    private const MAX_PILLS_PER_CELL = 2;

    /**
     * @param list<array{
     *     event: array<string, mixed>,
     *     occurrence: \DateTimeImmutable,
     *     occurrence_end: \DateTimeImmutable,
     * }> $occurrences
     * @return list<list<array{
     *     date: \DateTimeImmutable,
     *     in_month: bool,
     *     is_today: bool,
     *     is_weekend: bool,
     *     pills: list<array<string, mixed>>,
     * }>>
     */
    public function build(int $year, int $month, string $timezone, array $occurrences): array
    {
        $tz = new \DateTimeZone($timezone);
        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
        $today = new \DateTimeImmutable('now', $tz);
        $todayYmd = $today->format('Y-m-d');

        // ISO 8601 day-of-week: Monday=1 … Sunday=7. Subtract 1 to get the
        // count of "other-month" cells before the 1st in a Monday-start grid.
        $leadingOther = ((int) $firstOfMonth->format('N')) - 1;
        $gridStart = $firstOfMonth->modify("-{$leadingOther} days");

        // Pre-assign slots to occurrences. For v0.3.0 every occurrence is a
        // single-day event (we render occurrence + occurrence_end on adjacent
        // cells), but the algorithm already supports the multi-day path so
        // it slots in cleanly for v0.3.1+.
        $slots = $this->assignSlots($occurrences, $tz);

        $grid = [];
        for ($row = 0; $row < self::ROWS; $row++) {
            $cells = [];
            for ($col = 0; $col < self::COLS; $col++) {
                $offset = $row * self::COLS + $col;
                $date = $gridStart->modify("+{$offset} days");
                $cells[] = $this->buildCell($date, $month, $todayYmd, $occurrences, $slots);
            }
            $grid[] = $cells;
        }

        return $grid;
    }

    /**
     * Greedy first-fit slot assignment. Returns a map keyed by occurrence
     * index → assigned slot integer. Ordered traversal: start ASC, duration DESC.
     *
     * @param list<array> $occurrences
     * @return array<int, int>
     */
    private function assignSlots(array $occurrences, \DateTimeZone $tz): array
    {
        // Build an index of (start_ts, duration_seconds) per occurrence
        $order = [];
        foreach ($occurrences as $i => $occ) {
            $startTs = $occ['occurrence']->getTimestamp();
            $endTs = $occ['occurrence_end']->getTimestamp();
            $order[] = ['i' => $i, 'start' => $startTs, 'duration' => $endTs - $startTs];
        }
        usort($order, fn(array $a, array $b): int => $a['start'] <=> $b['start']
            ?: $b['duration'] <=> $a['duration']);

        $assigned = [];
        /** @var array<int, int> $slotEnd  slot index → ts of last claimed end */
        $slotEnd = [];

        foreach ($order as $row) {
            $i = $row['i'];
            $startTs = $row['start'];
            $endTs = $startTs + $row['duration'];

            // First-fit: lowest slot whose previous occupant ended before this starts
            $slot = 0;
            while (isset($slotEnd[$slot]) && $slotEnd[$slot] > $startTs) {
                $slot++;
            }
            $assigned[$i] = $slot;
            $slotEnd[$slot] = $endTs;
        }

        return $assigned;
    }

    /**
     * Build a single cell record + its pills.
     *
     * @param list<array> $occurrences
     * @param array<int, int> $slots
     * @return array{
     *     date: \DateTimeImmutable,
     *     in_month: bool,
     *     is_today: bool,
     *     is_weekend: bool,
     *     pills: list<array>,
     * }
     */
    private function buildCell(
        \DateTimeImmutable $date,
        int $monthBeingRendered,
        string $todayYmd,
        array $occurrences,
        array $slots,
    ): array {
        $inMonth = ((int) $date->format('n')) === $monthBeingRendered;
        $dow = (int) $date->format('N');  // 1=Mon … 7=Sun

        // Collect pills for this cell: occurrences whose [start, end] window
        // intersects this calendar day. Mark role per cell (single / start / mid / end).
        $cellYmd = $date->format('Y-m-d');
        $pills = [];
        foreach ($occurrences as $i => $occ) {
            $startYmd = $occ['occurrence']->format('Y-m-d');
            $endYmd = $occ['occurrence_end']->format('Y-m-d');
            if ($cellYmd < $startYmd || $cellYmd > $endYmd) {
                continue;
            }
            $role = match (true) {
                $startYmd === $endYmd => 'single',
                $cellYmd === $startYmd => 'start',
                $cellYmd === $endYmd => 'end',
                default => 'mid',
            };
            $pills[] = [
                'event_id' => (int) ($occ['event']['id'] ?? 0),
                'title' => (string) ($occ['event']['title'] ?? ''),
                'role' => $role,
                'slot' => $slots[$i] ?? 0,
                'start_ts' => $occ['occurrence']->getTimestamp(),
            ];
        }
        // Sort by slot ascending so the stripe order is stable across cells
        usort($pills, fn(array $a, array $b): int => $a['slot'] <=> $b['slot']
            ?: $a['start_ts'] <=> $b['start_ts']);

        // Overflow: clip to MAX_PILLS_PER_CELL real pills + 1 "+N more" pill
        if (count($pills) > self::MAX_PILLS_PER_CELL) {
            $extra = count($pills) - self::MAX_PILLS_PER_CELL;
            $pills = array_slice($pills, 0, self::MAX_PILLS_PER_CELL);
            $pills[] = [
                'event_id' => 0,
                'title' => "+{$extra} more",
                'role' => 'overflow',
                'slot' => self::MAX_PILLS_PER_CELL,
                'overflow_count' => $extra,
            ];
        }

        // Strip the internal sort key before returning
        foreach ($pills as &$p) {
            unset($p['start_ts']);
        }
        unset($p);

        return [
            'date' => $date,
            'in_month' => $inMonth,
            'is_today' => $cellYmd === $todayYmd,
            'is_weekend' => $dow >= 6,
            'pills' => $pills,
        ];
    }
}
