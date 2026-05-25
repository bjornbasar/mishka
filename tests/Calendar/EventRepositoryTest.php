<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\ConcurrentUpdateException;
use App\Calendar\EventRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests against the in-memory SQLite test DB. Outer transaction wraps
 * each test for isolation (matches the v0.2 repo-test pattern).
 *
 * Test fixtures use the helper insertHousehold/insertUser/insertEvent so each
 * test focuses on the assertion under test, not its scaffolding.
 */
final class EventRepositoryTest extends TestCase
{
    private Connection $db;
    private EventRepository $repo;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new EventRepository($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_persists_event_with_household_timezone(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');

        $id = $this->repo->create([
            'household_id' => $hid,
            'created_by' => $userId,
            'title' => 'School pickup',
            'description' => 'Afternoon',
            'location' => 'School gate',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 15:30:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);

        self::assertGreaterThan(0, $id);

        $row = $this->db->fetchOne('SELECT * FROM events WHERE id = :id', ['id' => $id]);
        self::assertSame('School pickup', $row['title']);
        self::assertSame('Pacific/Auckland', $row['timezone']);
        self::assertSame('2026-07-14 15:00:00', $row['starts_at_local']);
    }

    public function test_create_works_under_outer_transaction(): void
    {
        // The harness already wraps this test in a transaction; create() must
        // not blindly call beginTransaction. If this assertion runs at all,
        // the nested-txn guard works.
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');

        $id = $this->repo->create($this->minimalEventData($hid, $userId));
        self::assertGreaterThan(0, $id);
    }

    public function test_create_rejects_invalid_timezone(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');

        $this->expectException(\InvalidArgumentException::class);
        $data = $this->minimalEventData($hid, $userId);
        $data['timezone'] = 'America/NotARealZone';
        $this->repo->create($data);
    }

    public function test_create_truncates_seconds_for_minute_precision(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');

        $data = $this->minimalEventData($hid, $userId);
        $data['starts_at_local'] = '2026-07-14 15:00:42';
        $data['ends_at_local'] = '2026-07-14 15:30:17';
        $id = $this->repo->create($data);

        $row = $this->db->fetchOne('SELECT starts_at_local, ends_at_local FROM events WHERE id = :id', ['id' => $id]);
        self::assertSame('2026-07-14 15:00:00', $row['starts_at_local']);
        self::assertSame('2026-07-14 15:30:00', $row['ends_at_local']);
    }

    public function test_find_by_id_returns_event_or_null(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');
        $id = $this->repo->create($this->minimalEventData($hid, $userId));

        $found = $this->repo->findById($id);
        self::assertNotNull($found);
        self::assertSame($id, $found['id']);
        self::assertNull($this->repo->findById(99999));
    }

    public function test_find_in_range_includes_event_within_window(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');
        $id = $this->repo->create($this->minimalEventData($hid, $userId, '2026-07-14 15:00:00', '2026-07-14 16:00:00'));

        $tz = new \DateTimeZone('Pacific/Auckland');
        $rows = $this->repo->findInRangeForHousehold(
            $hid,
            new \DateTimeImmutable('2026-07-01 00:00:00', $tz),
            new \DateTimeImmutable('2026-07-31 23:59:59', $tz),
        );

        self::assertCount(1, $rows);
        self::assertSame($id, $rows[0]['id']);
    }

    public function test_find_in_range_excludes_override_events_via_defensive_filter(): void
    {
        // v0.3.0 doesn't yet write series_event_id, but the column ships now.
        // Verify the defensive WHERE series_event_id IS NULL filter so v0.3.1
        // overrides never accidentally double-render via this method.
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');
        $seriesId = $this->repo->create($this->minimalEventData($hid, $userId, '2026-07-14 15:00:00', '2026-07-14 16:00:00'));

        // Insert an override-shaped row directly (bypassing repo input whitelist)
        $this->db->run(
            "INSERT INTO events (household_id, created_by, title, description, location,
                                 starts_at_local, ends_at_local, timezone, all_day, series_event_id)
             VALUES (:hid, :uid, 'Override', '', '', :start, :end, 'Pacific/Auckland', FALSE, :series)",
            [
                'hid' => $hid, 'uid' => $userId, 'series' => $seriesId,
                'start' => '2026-07-15 15:00:00', 'end' => '2026-07-15 16:00:00',
            ],
        );

        $tz = new \DateTimeZone('Pacific/Auckland');
        $rows = $this->repo->findInRangeForHousehold(
            $hid,
            new \DateTimeImmutable('2026-07-01 00:00:00', $tz),
            new \DateTimeImmutable('2026-07-31 23:59:59', $tz),
        );

        self::assertCount(1, $rows);
        self::assertSame($seriesId, $rows[0]['id']);
    }

    public function test_update_happy_path_with_matching_expected_updated_at(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');
        $id = $this->repo->create($this->minimalEventData($hid, $userId));

        $current = $this->repo->findById($id);
        $this->repo->update($id, ['title' => 'Updated'], $current['updated_at']);

        self::assertSame('Updated', $this->repo->findById($id)['title']);
    }

    public function test_update_with_stale_expected_updated_at_throws(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');
        $id = $this->repo->create($this->minimalEventData($hid, $userId));

        $this->expectException(ConcurrentUpdateException::class);
        $this->repo->update($id, ['title' => 'X'], '1999-01-01 00:00:00');
    }

    public function test_household_delete_cascades_to_events(): void
    {
        // v0.3.0 only has the events table — full chain (with event_exceptions)
        // gets tested in v0.3.1 once that table exists. Here we verify the
        // events.household_id FK CASCADE fires + series_event_id CASCADE fires
        // for the override-shaped row we inserted directly.
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');
        $seriesId = $this->repo->create($this->minimalEventData($hid, $userId));

        $overrideId = (int) $this->db->fetchScalar(
            "INSERT INTO events (household_id, created_by, title, starts_at_local, ends_at_local, timezone, series_event_id)
             VALUES (:hid, :uid, 'Override', :s, :e, 'Pacific/Auckland', :series)
             RETURNING id",
            ['hid' => $hid, 'uid' => $userId, 'series' => $seriesId, 's' => '2026-07-15 15:00:00', 'e' => '2026-07-15 16:00:00'],
        );

        $this->db->run('DELETE FROM households WHERE id = :hid', ['hid' => $hid]);

        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM events WHERE household_id = :hid', ['hid' => $hid]));
        self::assertNull($this->repo->findById($seriesId));
        self::assertNull($this->repo->findById($overrideId));
    }

    public function test_delete_removes_event(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Test Den');
        $id = $this->repo->create($this->minimalEventData($hid, $userId));

        $this->repo->delete($id);

        self::assertNull($this->repo->findById($id));
    }

    private function insertUser(string $email): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name) VALUES (:email, :hash, :name) RETURNING id',
            ['email' => $email, 'hash' => 'unused', 'name' => 'Test'],
        );
    }

    private function insertHousehold(string $name): int
    {
        return (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES (:name, :code, 'Pacific/Auckland') RETURNING id",
            ['name' => $name, 'code' => substr(bin2hex(random_bytes(4)), 0, 8)],
        );
    }

    private function minimalEventData(
        int $hid,
        int $userId,
        string $start = '2026-07-14 15:00:00',
        string $end = '2026-07-14 16:00:00',
    ): array {
        return [
            'household_id' => $hid,
            'created_by' => $userId,
            'title' => 'Test event',
            'description' => '',
            'location' => '',
            'starts_at_local' => $start,
            'ends_at_local' => $end,
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ];
    }
}
