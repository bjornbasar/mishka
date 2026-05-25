<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\EventExceptionRepository;
use App\Calendar\EventRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class EventExceptionRepositoryTest extends TestCase
{
    private Connection $db;
    private EventExceptionRepository $repo;
    private EventRepository $events;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->events = new EventRepository($this->db);
        $this->repo = new EventExceptionRepository($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_cancel_inserts_exception_row(): void
    {
        $eid = $this->createSeries();
        $occ = new \DateTimeImmutable('2026-07-14 18:00:00', new \DateTimeZone('Pacific/Auckland'));

        $this->repo->cancel($eid, $occ);

        $row = $this->db->fetchOne(
            'SELECT override_event_id FROM event_exceptions WHERE event_id = :e',
            ['e' => $eid],
        );
        self::assertNotNull($row);
        self::assertNull($row['override_event_id']);
    }

    public function test_cancel_is_idempotent(): void
    {
        $eid = $this->createSeries();
        $occ = new \DateTimeImmutable('2026-07-14 18:00:00', new \DateTimeZone('Pacific/Auckland'));

        $this->repo->cancel($eid, $occ);
        $this->repo->cancel($eid, $occ);  // second call: no exception, no duplicate row

        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM event_exceptions WHERE event_id = :e',
            ['e' => $eid],
        );
        self::assertSame(1, $count);
    }

    public function test_add_override_atomically_creates_override_event_and_exception_row(): void
    {
        $eid = $this->createSeries();
        $occ = new \DateTimeImmutable('2026-07-14 18:00:00', new \DateTimeZone('Pacific/Auckland'));

        $overrideId = $this->repo->addOverride($eid, $occ, [
            'title' => 'Moved to 7pm',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 19:00:00',
            'ends_at_local' => '2026-07-14 20:00:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);

        // Override event row exists with series_event_id back-ref
        $override = $this->events->findById($overrideId);
        self::assertNotNull($override);
        self::assertSame($eid, $override['series_event_id']);
        self::assertSame('Moved to 7pm', $override['title']);

        // Exception row points at it
        $exception = $this->db->fetchOne(
            'SELECT override_event_id FROM event_exceptions WHERE event_id = :e',
            ['e' => $eid],
        );
        self::assertSame($overrideId, (int) $exception['override_event_id']);
    }

    public function test_add_override_rejects_duplicate_for_same_occurrence(): void
    {
        $eid = $this->createSeries();
        $occ = new \DateTimeImmutable('2026-07-14 18:00:00', new \DateTimeZone('Pacific/Auckland'));

        $this->repo->addOverride($eid, $occ, $this->minimalOverrideData());

        $this->expectException(\Throwable::class);  // UNIQUE violation
        $this->repo->addOverride($eid, $occ, $this->minimalOverrideData());
    }

    public function test_list_for_event_returns_cancellations_and_overrides(): void
    {
        $eid = $this->createSeries();
        $cancelOcc = new \DateTimeImmutable('2026-07-21 18:00:00', new \DateTimeZone('Pacific/Auckland'));
        $overrideOcc = new \DateTimeImmutable('2026-07-14 18:00:00', new \DateTimeZone('Pacific/Auckland'));

        $this->repo->cancel($eid, $cancelOcc);
        $overrideId = $this->repo->addOverride($eid, $overrideOcc, $this->minimalOverrideData());

        $rows = $this->repo->listForEvent($eid);
        self::assertCount(2, $rows);

        $byOriginal = [];
        foreach ($rows as $r) {
            $byOriginal[$r['original_starts_at']] = $r;
        }
        self::assertNull($byOriginal['2026-07-21 18:00:00']['override_event_id']);
        self::assertSame($overrideId, $byOriginal['2026-07-14 18:00:00']['override_event_id']);
        self::assertNotNull($byOriginal['2026-07-14 18:00:00']['override_event']);
    }

    public function test_cascade_shift_moves_all_original_starts_at_by_delta(): void
    {
        $eid = $this->createSeries();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $this->repo->cancel($eid, new \DateTimeImmutable('2026-07-14 18:00:00', $tz));
        $this->repo->cancel($eid, new \DateTimeImmutable('2026-07-21 18:00:00', $tz));

        $delta = new \DateInterval('PT1H');
        $shifted = $this->repo->cascadeShift($eid, $delta);

        self::assertSame(2, $shifted);

        $rows = $this->db->fetchAll(
            'SELECT original_starts_at FROM event_exceptions WHERE event_id = :e ORDER BY original_starts_at',
            ['e' => $eid],
        );
        self::assertSame('2026-07-14 19:00:00', $rows[0]['original_starts_at']);
        self::assertSame('2026-07-21 19:00:00', $rows[1]['original_starts_at']);
    }

    public function test_drop_all_for_event_removes_overrides_and_cancellations(): void
    {
        // The BLOCKING-bug-fix path from round-3 review: ON DELETE CASCADE on
        // event_exceptions → events points the wrong way for deleting overrides
        // via just the exception row. dropAllForEvent must do the two-step delete.
        $eid = $this->createSeries();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $overrideId = $this->repo->addOverride(
            $eid,
            new \DateTimeImmutable('2026-07-14 18:00:00', $tz),
            $this->minimalOverrideData(),
        );
        $this->repo->cancel($eid, new \DateTimeImmutable('2026-07-21 18:00:00', $tz));

        $dropped = $this->repo->dropAllForEvent($eid);

        self::assertSame(2, $dropped);
        // BOTH the override Event AND the exception rows must be gone
        self::assertNull($this->events->findById($overrideId));
        self::assertSame(
            0,
            (int) $this->db->fetchScalar('SELECT COUNT(*) FROM event_exceptions WHERE event_id = :e', ['e' => $eid]),
        );
    }

    public function test_drop_all_leaves_series_event_untouched(): void
    {
        $eid = $this->createSeries();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $this->repo->cancel($eid, new \DateTimeImmutable('2026-07-21 18:00:00', $tz));

        $this->repo->dropAllForEvent($eid);

        // The series itself stays alive
        self::assertNotNull($this->events->findById($eid));
    }

    public function test_series_delete_cascades_exception_rows_via_event_id_fk(): void
    {
        $eid = $this->createSeries();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $this->repo->cancel($eid, new \DateTimeImmutable('2026-07-14 18:00:00', $tz));
        $overrideId = $this->repo->addOverride(
            $eid,
            new \DateTimeImmutable('2026-07-21 18:00:00', $tz),
            $this->minimalOverrideData(),
        );

        // Delete the series itself: events.series_event_id ON DELETE CASCADE
        // takes out the override; event_exceptions.event_id ON DELETE CASCADE
        // takes out the exception rows.
        $this->events->delete($eid);

        self::assertNull($this->events->findById($overrideId));
        self::assertSame(
            0,
            (int) $this->db->fetchScalar('SELECT COUNT(*) FROM event_exceptions WHERE event_id = :e', ['e' => $eid]),
        );
    }

    private function createSeries(): int
    {
        $uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'T') RETURNING id",
            ['e' => 'u-' . bin2hex(random_bytes(3)) . '@example.com'],
        );
        $hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('H', :c, 'Pacific/Auckland') RETURNING id",
            ['c' => substr(bin2hex(random_bytes(4)), 0, 8)],
        );

        return $this->events->create([
            'household_id' => $hid,
            'created_by' => $uid,
            'title' => 'Soccer',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function minimalOverrideData(): array
    {
        return [
            'title' => 'Override',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 19:00:00',
            'ends_at_local' => '2026-07-14 20:00:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ];
    }
}
