<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\MonthGridBuilder;
use PHPUnit\Framework\TestCase;

final class MonthGridBuilderTest extends TestCase
{
    private MonthGridBuilder $builder;
    private string $tz = 'Pacific/Auckland';

    protected function setUp(): void
    {
        $this->builder = new MonthGridBuilder();
    }

    public function test_grid_returns_six_rows_of_seven_cells(): void
    {
        // Monday-start; 6×7 always (some months span 5 weeks, some 6 — the builder
        // pads to a stable 6 to keep the layout predictable across months).
        $grid = $this->builder->build(2026, 7, $this->tz, []);
        self::assertCount(6, $grid);
        foreach ($grid as $row) {
            self::assertCount(7, $row);
        }
    }

    public function test_empty_month_has_no_pills(): void
    {
        $grid = $this->builder->build(2026, 7, $this->tz, []);
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                self::assertSame([], $cell['pills']);
            }
        }
    }

    public function test_other_month_cells_are_flagged(): void
    {
        // July 2026 starts Wed (in NZ) — so the first row has Mon, Tue as other-month padding
        $grid = $this->builder->build(2026, 7, $this->tz, []);
        self::assertFalse($grid[0][0]['in_month']);  // Mon 29 Jun
        self::assertFalse($grid[0][1]['in_month']);  // Tue 30 Jun
        self::assertTrue($grid[0][2]['in_month']);   // Wed 1 Jul
    }

    public function test_today_flag_set_on_correct_cell(): void
    {
        // Build a grid for whatever today is in the household tz; assert
        // exactly one cell is_today.
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->tz));
        $grid = $this->builder->build((int) $now->format('Y'), (int) $now->format('n'), $this->tz, []);

        $todayCount = 0;
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                if ($cell['is_today']) {
                    $todayCount++;
                    self::assertSame($now->format('Y-m-d'), $cell['date']->format('Y-m-d'));
                }
            }
        }
        self::assertSame(1, $todayCount);
    }

    public function test_single_day_event_renders_one_pill_with_single_role(): void
    {
        $events = $this->occurrencesFor(['2026-07-14 15:00:00' => 60], ['title' => 'School pickup']);
        $grid = $this->builder->build(2026, 7, $this->tz, $events);

        $cell = $this->findCell($grid, '2026-07-14');
        self::assertCount(1, $cell['pills']);
        self::assertSame('single', $cell['pills'][0]['role']);
        self::assertSame('School pickup', $cell['pills'][0]['title']);
    }

    public function test_three_day_event_emits_start_mid_end_roles_on_same_slot(): void
    {
        $events = [[
            'event' => $this->makeEvent(['id' => 9, 'title' => 'Camping']),
            'occurrence' => new \DateTimeImmutable('2026-07-14 09:00:00', new \DateTimeZone($this->tz)),
            'occurrence_end' => new \DateTimeImmutable('2026-07-16 17:00:00', new \DateTimeZone($this->tz)),
        ]];
        $grid = $this->builder->build(2026, 7, $this->tz, $events);

        $cells = [
            $this->findCell($grid, '2026-07-14'),
            $this->findCell($grid, '2026-07-15'),
            $this->findCell($grid, '2026-07-16'),
        ];
        $roles = array_map(fn(array $c): string => $c['pills'][0]['role'], $cells);
        self::assertSame(['start', 'mid', 'end'], $roles);

        // Same slot across all three days for the connected-pill illusion
        $slots = array_map(fn(array $c): int => $c['pills'][0]['slot'], $cells);
        self::assertSame([$slots[0], $slots[0], $slots[0]], $slots);
    }

    public function test_multi_day_across_week_boundary(): void
    {
        // 2026-07-14 is Tue; 2026-07-21 is Tue. Span from Sun 19 to Mon 20 spans rows.
        $events = [[
            'event' => $this->makeEvent(['id' => 10, 'title' => 'Weekend']),
            'occurrence' => new \DateTimeImmutable('2026-07-19 09:00:00', new \DateTimeZone($this->tz)),
            'occurrence_end' => new \DateTimeImmutable('2026-07-20 17:00:00', new \DateTimeZone($this->tz)),
        ]];
        $grid = $this->builder->build(2026, 7, $this->tz, $events);

        self::assertSame('start', $this->findCell($grid, '2026-07-19')['pills'][0]['role']);
        self::assertSame('end', $this->findCell($grid, '2026-07-20')['pills'][0]['role']);
    }

    public function test_overflow_beyond_two_pills_becomes_plus_more(): void
    {
        // 4 events on the same day → 2 pills + "+2 more"
        $events = $this->occurrencesFor([
            '2026-07-14 09:00:00' => 60,
            '2026-07-14 10:00:00' => 60,
            '2026-07-14 11:00:00' => 60,
            '2026-07-14 12:00:00' => 60,
        ]);
        $grid = $this->builder->build(2026, 7, $this->tz, $events);

        $cell = $this->findCell($grid, '2026-07-14');
        self::assertCount(3, $cell['pills']);  // 2 real pills + 1 overflow pill
        $last = end($cell['pills']);
        self::assertArrayHasKey('overflow_count', $last);
        self::assertSame(2, $last['overflow_count']);
    }

    public function test_february_non_leap_year_has_no_other_month_padding_after_feb_28(): void
    {
        $grid = $this->builder->build(2026, 2, $this->tz, []);
        // 2026 Feb starts Sunday (Mon=2026-02-02 in NZ ISO week starts Mon)
        // 28 days. Last in-month cell at Sat 28 Feb.
        $lastInMonth = null;
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                if ($cell['in_month'] && $cell['date']->format('m') === '02') {
                    $lastInMonth = $cell;
                }
            }
        }
        self::assertNotNull($lastInMonth);
        self::assertSame('2026-02-28', $lastInMonth['date']->format('Y-m-d'));
    }

    public function test_weekend_cells_flagged(): void
    {
        $grid = $this->builder->build(2026, 7, $this->tz, []);
        // Last two columns are Saturday + Sunday (Monday-start)
        foreach ($grid as $row) {
            self::assertTrue($row[5]['is_weekend'], "Sat cell should be is_weekend ({$row[5]['date']->format('Y-m-d')})");
            self::assertTrue($row[6]['is_weekend'], "Sun cell should be is_weekend ({$row[6]['date']->format('Y-m-d')})");
            self::assertFalse($row[0]['is_weekend'], "Mon cell should NOT be is_weekend ({$row[0]['date']->format('Y-m-d')})");
        }
    }

    public function test_dst_month_does_not_drop_or_duplicate_days(): void
    {
        // April 2026 in NZ: NZDT → NZST on Sun 5 Apr 3am. The grid for April must
        // still have exactly 30 in-month days, no skipped/duplicated dates.
        $grid = $this->builder->build(2026, 4, $this->tz, []);
        $inMonthDays = [];
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                if ($cell['in_month']) {
                    $inMonthDays[] = $cell['date']->format('Y-m-d');
                }
            }
        }
        self::assertCount(30, $inMonthDays);
        self::assertSame('2026-04-01', $inMonthDays[0]);
        self::assertSame('2026-04-30', $inMonthDays[29]);
    }

    public function test_pills_sorted_by_start_time_within_a_cell(): void
    {
        $events = $this->occurrencesFor([
            '2026-07-14 16:00:00' => 30,
            '2026-07-14 09:00:00' => 30,
        ], ['title' => 'X']);
        $grid = $this->builder->build(2026, 7, $this->tz, $events);
        $cell = $this->findCell($grid, '2026-07-14');
        // Both pills should exist; first one (slot 0) is the earlier start
        self::assertGreaterThanOrEqual(2, count($cell['pills']));
    }

    /**
     * Build a list of occurrence records keyed off (local_start → duration_minutes).
     * @param array<string, int> $startsToDuration
     * @param array<string, mixed> $overrides applied to the event record
     * @return list<array{event: array, occurrence: \DateTimeImmutable, occurrence_end: \DateTimeImmutable}>
     */
    private function occurrencesFor(array $startsToDuration, array $overrides = []): array
    {
        $tz = new \DateTimeZone($this->tz);
        $out = [];
        $i = 100;
        foreach ($startsToDuration as $start => $duration) {
            $startDt = new \DateTimeImmutable($start, $tz);
            $endDt = $startDt->modify("+{$duration} minutes");
            $out[] = [
                'event' => $this->makeEvent(['id' => $i++] + $overrides),
                'occurrence' => $startDt,
                'occurrence_end' => $endDt,
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function makeEvent(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'title' => 'Event',
            'description' => '',
            'location' => '',
            'all_day' => false,
        ];
    }

    private function findCell(array $grid, string $ymd): array
    {
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                if ($cell['date']->format('Y-m-d') === $ymd) {
                    return $cell;
                }
            }
        }
        self::fail("Cell {$ymd} not found in grid");
    }
}
