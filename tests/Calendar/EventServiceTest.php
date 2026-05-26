<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\ConcurrentUpdateException;
use App\Calendar\EventExceptionRepository;
use App\Calendar\EventRepository;
use App\Calendar\EventService;
use App\Calendar\UpdateResult;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EventService — the coordinator that classifies the diff
 * between old and new series state and routes to cascadeShift / dropAll /
 * no-op + the events row update. Confirmation booleans gate the destructive
 * paths so the controller can render a dialog before applying.
 */
final class EventServiceTest extends TestCase
{
    private Connection $db;
    private EventRepository $events;
    private EventExceptionRepository $exceptions;
    private EventService $service;
    private int $hid;
    private int $uid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->events = new EventRepository($this->db);
        $this->exceptions = new EventExceptionRepository($this->db);
        $this->service = new EventService($this->events, $this->exceptions);

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

    public function test_cosmetic_edit_no_confirmation_needed_and_applies_directly(): void
    {
        $eid = $this->createSeries();
        $current = $this->events->findById($eid);

        $result = $this->service->updateSeries(
            $eid,
            ['title' => 'Renamed', 'starts_at_local' => $current['starts_at_local'],
             'ends_at_local' => $current['ends_at_local'], 'rrule' => $current['rrule']],
            $current['updated_at'],
            cascadeConfirmed: false,
            dropConfirmed: false,
            expectedExceptionCount: 0,
        );

        self::assertSame('ok', $result->status);
        self::assertSame('Renamed', $this->events->findById($eid)['title']);
    }

    public function test_time_shift_no_overrides_no_confirmation_needed(): void
    {
        $eid = $this->createSeries();
        $current = $this->events->findById($eid);

        $result = $this->service->updateSeries(
            $eid,
            ['title' => $current['title'], 'starts_at_local' => '2026-07-07 19:00:00',
             'ends_at_local' => '2026-07-07 20:00:00', 'rrule' => $current['rrule']],
            $current['updated_at'],
            cascadeConfirmed: false,
            dropConfirmed: false,
            expectedExceptionCount: 0,
        );

        self::assertSame('ok', $result->status);
        self::assertSame('2026-07-07 19:00:00', $this->events->findById($eid)['starts_at_local']);
    }

    public function test_time_shift_with_overrides_requires_cascade_confirmation(): void
    {
        $eid = $this->createSeries();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $this->exceptions->cancel($eid, new \DateTimeImmutable('2026-07-14 18:00:00', $tz));
        $this->exceptions->cancel($eid, new \DateTimeImmutable('2026-07-21 18:00:00', $tz));
        $current = $this->events->findById($eid);

        $result = $this->service->updateSeries(
            $eid,
            ['title' => $current['title'], 'starts_at_local' => '2026-07-07 19:00:00',
             'ends_at_local' => $current['ends_at_local'], 'rrule' => $current['rrule']],
            $current['updated_at'],
            cascadeConfirmed: false,
            dropConfirmed: false,
            expectedExceptionCount: 2,
        );

        self::assertSame('requires_cascade_confirm', $result->status);
        self::assertSame(2, $result->exceptionCount);
        self::assertCount(2, $result->affected);
        // The event row was NOT updated yet
        self::assertSame('2026-07-07 18:00:00', $this->events->findById($eid)['starts_at_local']);
    }

    public function test_time_shift_with_cascade_confirmed_applies_shift_to_overrides(): void
    {
        $eid = $this->createSeries();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $this->exceptions->cancel($eid, new \DateTimeImmutable('2026-07-14 18:00:00', $tz));
        $current = $this->events->findById($eid);

        $result = $this->service->updateSeries(
            $eid,
            ['title' => $current['title'], 'starts_at_local' => '2026-07-07 19:00:00',
             'ends_at_local' => '2026-07-07 20:00:00', 'rrule' => $current['rrule']],
            $current['updated_at'],
            cascadeConfirmed: true,
            dropConfirmed: false,
            expectedExceptionCount: 1,
        );

        self::assertSame('ok', $result->status);
        // The cancellation shifted from 14 Jul 18:00 → 14 Jul 19:00
        $exception = $this->db->fetchOne('SELECT original_starts_at FROM event_exceptions WHERE event_id = :e', ['e' => $eid]);
        self::assertSame('2026-07-14 19:00:00', $exception['original_starts_at']);
    }

    public function test_structural_change_with_overrides_requires_drop_confirmation(): void
    {
        $eid = $this->createSeries();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $this->exceptions->addOverride(
            $eid,
            new \DateTimeImmutable('2026-07-14 18:00:00', $tz),
            ['title' => 'Override', 'description' => '', 'location' => '',
             'starts_at_local' => '2026-07-14 19:00:00', 'ends_at_local' => '2026-07-14 20:00:00',
             'timezone' => 'Pacific/Auckland', 'all_day' => false],
        );
        $current = $this->events->findById($eid);

        $result = $this->service->updateSeries(
            $eid,
            ['title' => $current['title'], 'starts_at_local' => $current['starts_at_local'],
             'ends_at_local' => $current['ends_at_local'], 'rrule' => 'FREQ=WEEKLY;BYDAY=WE'],
            $current['updated_at'],
            cascadeConfirmed: false,
            dropConfirmed: false,
            expectedExceptionCount: 1,
        );

        self::assertSame('requires_drop_confirm', $result->status);
        self::assertCount(1, $result->affected);
        // Event row + override row + exception row all still present
        self::assertNotNull($this->events->findById($eid));
        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM event_exceptions WHERE event_id = :e', ['e' => $eid]));
    }

    public function test_structural_change_with_drop_confirmed_wipes_overrides_and_updates(): void
    {
        $eid = $this->createSeries();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $overrideId = $this->exceptions->addOverride(
            $eid,
            new \DateTimeImmutable('2026-07-14 18:00:00', $tz),
            ['title' => 'Override', 'description' => '', 'location' => '',
             'starts_at_local' => '2026-07-14 19:00:00', 'ends_at_local' => '2026-07-14 20:00:00',
             'timezone' => 'Pacific/Auckland', 'all_day' => false],
        );
        $current = $this->events->findById($eid);

        $result = $this->service->updateSeries(
            $eid,
            ['title' => $current['title'], 'starts_at_local' => $current['starts_at_local'],
             'ends_at_local' => $current['ends_at_local'], 'rrule' => 'FREQ=WEEKLY;BYDAY=WE'],
            $current['updated_at'],
            cascadeConfirmed: false,
            dropConfirmed: true,
            expectedExceptionCount: 1,
        );

        self::assertSame('ok', $result->status);
        // Override Event row gone AND exception row gone (regression for the
        // round-3 BLOCKING-bug-fix two-step DELETE)
        self::assertNull($this->events->findById($overrideId));
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM event_exceptions WHERE event_id = :e', ['e' => $eid]));
        // Series row updated to new rrule
        self::assertSame('FREQ=WEEKLY;BYDAY=WE', $this->events->findById($eid)['rrule']);
    }

    public function test_stale_expected_updated_at_throws(): void
    {
        $eid = $this->createSeries();
        $current = $this->events->findById($eid);

        $this->expectException(ConcurrentUpdateException::class);
        $this->service->updateSeries(
            $eid,
            ['title' => 'Updated', 'starts_at_local' => $current['starts_at_local'],
             'ends_at_local' => $current['ends_at_local'], 'rrule' => $current['rrule']],
            '1999-01-01 00:00:00',  // stale
            cascadeConfirmed: false,
            dropConfirmed: false,
            expectedExceptionCount: 0,
        );
    }

    public function test_stale_expected_exception_count_returns_stale_data(): void
    {
        $eid = $this->createSeries();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $this->exceptions->cancel($eid, new \DateTimeImmutable('2026-07-14 18:00:00', $tz));
        $current = $this->events->findById($eid);

        $result = $this->service->updateSeries(
            $eid,
            ['title' => $current['title'], 'starts_at_local' => '2026-07-07 19:00:00',
             'ends_at_local' => $current['ends_at_local'], 'rrule' => $current['rrule']],
            $current['updated_at'],
            cascadeConfirmed: true,
            dropConfirmed: false,
            expectedExceptionCount: 0,  // dialog rendered when 0; someone added the cancellation since
        );

        self::assertSame('stale_data', $result->status);
        // Event row + exception row both untouched
        self::assertSame('2026-07-07 18:00:00', $this->events->findById($eid)['starts_at_local']);
        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM event_exceptions WHERE event_id = :e', ['e' => $eid]));
    }

    private function createSeries(): int
    {
        $eid = $this->events->create([
            'household_id' => $this->hid,
            'created_by' => $this->uid,
            'title' => 'Soccer',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);
        $this->db->run("UPDATE events SET rrule = 'FREQ=WEEKLY;BYDAY=TU' WHERE id = :id", ['id' => $eid]);
        return $eid;
    }
}
