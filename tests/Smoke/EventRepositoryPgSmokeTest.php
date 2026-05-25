<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Calendar\EventRepository;
use App\Household\HouseholdRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * PG-only smoke tests for v0.3.0's `events` table.
 *
 * Exercises PG-specific behaviour the SQLite test pass can't verify:
 *   - SERIAL sequences + TIMESTAMPTZ defaults
 *   - Partial index `idx_events_series_event_id WHERE series_event_id IS NOT NULL`
 *     created with predicate (queryable via pg_indexes)
 *   - Multi-step FK CASCADE (events.household_id → households + events.series_event_id → events)
 *
 * SKIPS unless DB_DSN points at a pgsql:// connection. CI's pg-smoke job spins
 * up postgres:16 and runs migrate before these tests.
 */
final class EventRepositoryPgSmokeTest extends TestCase
{
    private Connection $db;
    private EventRepository $events;
    private HouseholdRepository $households;

    protected function setUp(): void
    {
        $dsn = getenv('DB_DSN') ?: ($_ENV['DB_DSN'] ?? '');
        if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) {
            self::markTestSkipped('PG smoke tests require DB_DSN=pgsql:...');
        }

        $this->db = new Connection(
            $dsn,
            (string) (getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? '')),
            (string) (getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '')),
        );

        $this->db->pdo()->beginTransaction();
        $this->events = new EventRepository($this->db);
        $this->households = new HouseholdRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_uses_serial_sequence_and_timestamptz_defaults(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->households->createForOwner('Test', $userId);

        $id = $this->events->create([
            'household_id' => $hid,
            'created_by' => $userId,
            'title' => 'School pickup',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 16:00:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);

        self::assertGreaterThan(0, $id);

        $created = $this->db->fetchScalar('SELECT created_at FROM events WHERE id = :id', ['id' => $id]);
        $age = abs(time() - strtotime((string) $created));
        self::assertLessThan(60, $age, 'created_at should be NOW()-ish');
    }

    public function test_partial_index_idx_events_series_event_id_exists_with_predicate(): void
    {
        // pg_indexes view exposes the index definition; verify the partial-index
        // WHERE clause survives migration.
        $defn = (string) $this->db->fetchScalar(
            "SELECT indexdef FROM pg_indexes WHERE indexname = 'idx_events_series_event_id'",
        );
        self::assertNotEmpty($defn, 'idx_events_series_event_id missing');
        self::assertStringContainsString('WHERE', $defn);
        self::assertStringContainsString('series_event_id IS NOT NULL', $defn);
    }

    public function test_household_delete_cascades_to_events(): void
    {
        $userId = $this->insertUser('b@example.com');
        $hid = $this->households->createForOwner('Cascade Test', $userId);
        $eid = $this->events->create([
            'household_id' => $hid,
            'created_by' => $userId,
            'title' => 'Doomed',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 16:00:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);

        self::assertNotNull($this->events->findById($eid));

        $this->db->run('DELETE FROM households WHERE id = :hid', ['hid' => $hid]);

        self::assertNull($this->events->findById($eid));
    }

    private function insertUser(string $email): int
    {
        $suffix = bin2hex(random_bytes(4));
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name)
             VALUES (:email, :hash, :name) RETURNING id',
            [
                'email' => "smoke-{$suffix}-{$email}",
                'hash' => 'unused',
                'name' => 'Smoke',
            ],
        );
    }
}
