<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Chores\ChoreRepository;
use App\Chores\ChoreScheduleRepository;
use App\Household\HouseholdRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * PG-only smoke for v0.4.1. Exercises behaviour the SQLite pass can't verify:
 *   - chore_schedules SERIAL/TIMESTAMPTZ defaults + the assignment_mode CHECK enum
 *   - the partial UNIQUE(schedule_id, occurrence_date) WHERE schedule_id IS NOT NULL
 *     index actually blocks a duplicate generated insert under PG (PG partial-index
 *     semantics differ from SQLite — the one place a regression hides)
 *   - household-delete CASCADE to chore_schedules
 *
 * SKIPS unless DB_DSN points at a pgsql:// connection.
 */
final class ChoreScheduleRepositoryPgSmokeTest extends TestCase
{
    private Connection $db;
    private ChoreScheduleRepository $schedules;
    private ChoreRepository $chores;
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
        $this->schedules = new ChoreScheduleRepository($this->db);
        $this->chores = new ChoreRepository($this->db);
        $this->households = new HouseholdRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_uses_serial_and_timestamptz_defaults(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->households->createForOwner('Test', $uid);

        $id = $this->schedules->create($this->scheduleData($hid, $uid));
        self::assertGreaterThan(0, $id);

        $created = $this->db->fetchScalar('SELECT created_at FROM chore_schedules WHERE id = :id', ['id' => $id]);
        self::assertLessThan(60, abs(time() - strtotime((string) $created)), 'created_at should be NOW()-ish');
    }

    public function test_assignment_mode_check_rejects_bad_enum(): void
    {
        $uid = $this->insertUser('b@example.com');
        $hid = $this->households->createForOwner('Test', $uid);

        $this->expectException(\PDOException::class);
        $this->db->run(
            "INSERT INTO chore_schedules
               (household_id, created_by, title, rrule, anchor_at_local, timezone, assignment_mode)
             VALUES (:h, :u, 'X', 'FREQ=DAILY', '2026-06-01 09:00:00', 'Pacific/Auckland', 'banana')",
            ['h' => $hid, 'u' => $uid],
        );
    }

    public function test_partial_unique_index_blocks_duplicate_generated_occurrence(): void
    {
        $uid = $this->insertUser('c@example.com');
        $hid = $this->households->createForOwner('Test', $uid);
        $sid = $this->schedules->create($this->scheduleData($hid, $uid));

        $payload = [
            'household_id' => $hid, 'created_by' => $uid, 'schedule_id' => $sid,
            'occurrence_date' => '2026-06-02 09:00:00', 'due_at_local' => '2026-06-02 09:00:00',
            'assigned_to' => $uid, 'title' => 'bins', 'points' => 1, 'timezone' => 'Pacific/Auckland',
        ];
        $this->chores->createGenerated($payload);

        $this->expectException(\PDOException::class);
        $this->chores->createGenerated($payload);  // same (schedule_id, occurrence_date) → UNIQUE violation
    }

    public function test_household_delete_cascades_to_schedules(): void
    {
        $uid = $this->insertUser('d@example.com');
        $hid = $this->households->createForOwner('Cascade', $uid);
        $sid = $this->schedules->create($this->scheduleData($hid, $uid));

        self::assertNotNull($this->schedules->findById($sid));
        $this->db->run('DELETE FROM households WHERE id = :h', ['h' => $hid]);
        self::assertNull($this->schedules->findById($sid));
    }

    public function test_pause_pk_is_idempotent_and_cascades_on_schedule_delete(): void
    {
        $uid = $this->insertUser('e@example.com');
        $hid = $this->households->createForOwner('Den', $uid);
        $sid = $this->schedules->create($this->scheduleData($hid, $uid));

        $this->schedules->pause($sid);
        $this->schedules->pause($sid);  // ON CONFLICT DO NOTHING (PK collision)
        self::assertTrue($this->schedules->isPaused($sid));

        $this->schedules->delete($sid);  // FK CASCADE drops the pause row
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chore_schedule_pauses WHERE schedule_id = :s',
            ['s' => $sid],
        );
        self::assertSame(0, $count);
    }

    public function test_participants_composite_pk_and_cascade_on_schedule_delete(): void
    {
        $uid = $this->insertUser('f@example.com');
        $bob = $this->insertUser('g@example.com');
        $hid = $this->households->createForOwner('Den', $uid);
        $this->households->addMember($hid, $bob);
        $sid = $this->schedules->create($this->scheduleData($hid, $uid));

        $this->schedules->setParticipants($sid, [$uid, $bob]);
        self::assertCount(2, $this->schedules->listParticipantIds($sid));

        $this->schedules->delete($sid);  // FK CASCADE drops participant rows
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chore_schedule_participants WHERE schedule_id = :s',
            ['s' => $sid],
        );
        self::assertSame(0, $count);
    }

    private function insertUser(string $email): int
    {
        $suffix = bin2hex(random_bytes(4));
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name)
             VALUES (:email, :hash, :name) RETURNING id',
            ['email' => "smoke-{$suffix}-{$email}", 'hash' => 'unused', 'name' => 'Smoke'],
        );
    }

    /** @return array<string, mixed> */
    private function scheduleData(int $hid, int $uid): array
    {
        return [
            'household_id' => $hid, 'created_by' => $uid, 'title' => 'Take out bins',
            'description' => '', 'points' => 5, 'rrule' => 'FREQ=DAILY',
            'anchor_at_local' => '2026-06-01 09:00:00', 'timezone' => 'Pacific/Auckland',
            'assignment_mode' => 'rotate', 'fixed_user_id' => null,
        ];
    }
}
