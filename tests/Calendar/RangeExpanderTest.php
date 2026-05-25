<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\EventExceptionRepository;
use App\Calendar\EventRepository;
use App\Calendar\RangeExpander;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class RangeExpanderTest extends TestCase
{
    private Connection $db;
    private RangeExpander $expander;
    private EventRepository $events;
    private EventExceptionRepository $exceptions;
    private int $hid;
    private int $uid;
    private string $tz = 'Pacific/Auckland';

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->events = new EventRepository($this->db);
        $this->exceptions = new EventExceptionRepository($this->db);
        $this->expander = new RangeExpander($this->events, $this->exceptions);

        $this->uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'T') RETURNING id",
            ['e' => 'u-' . bin2hex(random_bytes(3)) . '@example.com'],
        );
        $this->hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('H', :c, 'Pacific/Auckland') RETURNING id",
            ['c' => substr(bin2hex(random_bytes(4)), 0, 8)],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_non_recurring_event_emits_once_in_range(): void
    {
        $this->createEvent(['starts_at_local' => '2026-07-14 18:00:00', 'ends_at_local' => '2026-07-14 19:00:00']);

        $occurrences = $this->expander->expandForHousehold(
            $this->hid,
            $this->dt('2026-07-01 00:00:00'),
            $this->dt('2026-07-31 23:59:59'),
        );

        self::assertCount(1, $occurrences);
        self::assertFalse($occurrences[0]['is_override']);
    }

    public function test_weekly_recurrence_expands_within_range(): void
    {
        $this->createEvent([
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]);

        $occurrences = $this->expander->expandForHousehold(
            $this->hid,
            $this->dt('2026-07-01 00:00:00'),
            $this->dt('2026-07-31 23:59:59'),
        );

        // Tuesdays in July 2026: 7, 14, 21, 28
        self::assertCount(4, $occurrences);
        $dates = array_map(
            fn(array $o): string => $o['occurrence']->format('Y-m-d'),
            $occurrences,
        );
        self::assertSame(['2026-07-07', '2026-07-14', '2026-07-21', '2026-07-28'], $dates);
    }

    public function test_byday_with_multiple_days(): void
    {
        $this->createEvent([
            'starts_at_local' => '2026-07-06 08:00:00',  // Mon
            'ends_at_local' => '2026-07-06 09:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
        ]);

        $occurrences = $this->expander->expandForHousehold(
            $this->hid,
            $this->dt('2026-07-06 00:00:00'),
            $this->dt('2026-07-12 23:59:59'),
        );

        // Mon 6, Wed 8, Fri 10
        $dates = array_map(fn(array $o): string => $o['occurrence']->format('Y-m-d'), $occurrences);
        self::assertSame(['2026-07-06', '2026-07-08', '2026-07-10'], $dates);
    }

    public function test_interval_every_two_weeks(): void
    {
        $this->createEvent([
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'rrule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU',
        ]);

        $occurrences = $this->expander->expandForHousehold(
            $this->hid,
            $this->dt('2026-07-01 00:00:00'),
            $this->dt('2026-07-31 23:59:59'),
        );

        // Every-other-Tue starting 7 Jul: 7, 21
        self::assertCount(2, $occurrences);
        self::assertSame('2026-07-07', $occurrences[0]['occurrence']->format('Y-m-d'));
        self::assertSame('2026-07-21', $occurrences[1]['occurrence']->format('Y-m-d'));
    }

    public function test_cancellation_excludes_occurrence(): void
    {
        $eid = $this->createEvent([
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]);

        $this->exceptions->cancel($eid, $this->dt('2026-07-14 18:00:00'));

        $occurrences = $this->expander->expandForHousehold(
            $this->hid,
            $this->dt('2026-07-01 00:00:00'),
            $this->dt('2026-07-31 23:59:59'),
        );

        $dates = array_map(fn(array $o): string => $o['occurrence']->format('Y-m-d'), $occurrences);
        // Tue 14 is cancelled
        self::assertSame(['2026-07-07', '2026-07-21', '2026-07-28'], $dates);
    }

    public function test_override_substitutes_for_series_occurrence(): void
    {
        $eid = $this->createEvent([
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]);

        $this->exceptions->addOverride($eid, $this->dt('2026-07-14 18:00:00'), [
            'title' => 'Moved to 7pm',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 19:00:00',
            'ends_at_local' => '2026-07-14 20:00:00',
            'timezone' => $this->tz,
            'all_day' => false,
        ]);

        $occurrences = $this->expander->expandForHousehold(
            $this->hid,
            $this->dt('2026-07-12 00:00:00'),
            $this->dt('2026-07-18 23:59:59'),
        );

        self::assertCount(1, $occurrences);
        self::assertTrue($occurrences[0]['is_override']);
        self::assertSame('Moved to 7pm', $occurrences[0]['event']['title']);
        // The occurrence-time slot is the override's new time
        self::assertSame('2026-07-14 19:00:00', $occurrences[0]['occurrence']->format('Y-m-d H:i:s'));
    }

    public function test_override_does_not_double_render(): void
    {
        // BLOCKING-bug-fix from round-3 review: override events have
        // series_event_id != NULL; the non-recurring branch of the query must
        // filter `WHERE series_event_id IS NULL` so the override doesn't ALSO
        // emit through that branch.
        $eid = $this->createEvent([
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]);
        $this->exceptions->addOverride($eid, $this->dt('2026-07-14 18:00:00'), [
            'title' => 'Override',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 19:00:00',
            'ends_at_local' => '2026-07-14 20:00:00',
            'timezone' => $this->tz,
            'all_day' => false,
        ]);

        $occurrences = $this->expander->expandForHousehold(
            $this->hid,
            $this->dt('2026-07-12 00:00:00'),
            $this->dt('2026-07-18 23:59:59'),
        );

        // Exactly one pill for the Tue-14 slot — NOT two (the series's original
        // 6pm AND the override's 7pm)
        self::assertCount(1, $occurrences);
        self::assertTrue($occurrences[0]['is_override']);
    }

    public function test_results_sorted_by_occurrence_ascending(): void
    {
        // Create two events; expander should still return them in time order.
        $this->createEvent([
            'starts_at_local' => '2026-07-21 09:00:00',
            'ends_at_local' => '2026-07-21 10:00:00',
            'title' => 'Later',
        ]);
        $this->createEvent([
            'starts_at_local' => '2026-07-14 09:00:00',
            'ends_at_local' => '2026-07-14 10:00:00',
            'title' => 'Earlier',
        ]);

        $occurrences = $this->expander->expandForHousehold(
            $this->hid,
            $this->dt('2026-07-01 00:00:00'),
            $this->dt('2026-07-31 23:59:59'),
        );

        self::assertSame('Earlier', $occurrences[0]['event']['title']);
        self::assertSame('Later', $occurrences[1]['event']['title']);
    }

    public function test_range_clamps_emit_only_occurrences_within_window(): void
    {
        $this->createEvent([
            'starts_at_local' => '2026-01-06 18:00:00',
            'ends_at_local' => '2026-01-06 19:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]);

        // Ask for only July; January occurrences should NOT appear
        $occurrences = $this->expander->expandForHousehold(
            $this->hid,
            $this->dt('2026-07-01 00:00:00'),
            $this->dt('2026-07-31 23:59:59'),
        );

        foreach ($occurrences as $o) {
            self::assertGreaterThanOrEqual('2026-07-01', $o['occurrence']->format('Y-m-d'));
            self::assertLessThanOrEqual('2026-07-31', $o['occurrence']->format('Y-m-d'));
        }
    }

    public function test_dst_recurrence_stays_at_local_wall_clock_across_nzdt_to_nzst(): void
    {
        // NZ DST ends first Sunday of April; "9am every Tuesday" should remain
        // 9am local time across the transition.
        $this->createEvent([
            'starts_at_local' => '2026-03-31 09:00:00',  // last Tue of NZDT
            'ends_at_local' => '2026-03-31 10:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]);

        $occurrences = $this->expander->expandForHousehold(
            $this->hid,
            $this->dt('2026-03-30 00:00:00'),
            $this->dt('2026-04-30 23:59:59'),
        );

        foreach ($occurrences as $o) {
            self::assertSame('09:00:00', $o['occurrence']->format('H:i:s'), 'every occurrence should be at 9am wall-clock');
        }
    }

    /** @param array<string, mixed> $overrides */
    private function createEvent(array $overrides): int
    {
        $base = [
            'household_id' => $this->hid,
            'created_by' => $this->uid,
            'title' => 'Event',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 16:00:00',
            'timezone' => $this->tz,
            'all_day' => false,
        ];

        $rrule = $overrides['rrule'] ?? null;
        unset($overrides['rrule']);
        $id = $this->events->create($overrides + $base);
        if ($rrule !== null) {
            $this->db->run('UPDATE events SET rrule = :r WHERE id = :id', ['r' => $rrule, 'id' => $id]);
        }
        return $id;
    }

    private function dt(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone($this->tz));
    }
}
