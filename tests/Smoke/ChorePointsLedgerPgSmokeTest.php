<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Chores\ChoreRepository;
use App\Household\HouseholdRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * PG-only smoke for the v0.4.2 points ledger. Verifies behaviour the SQLite pass
 * can't: SERIAL/TIMESTAMPTZ + the CHECK(points>=0), the FK ON DELETE matrix
 * (chore_id/credited_user_id SET NULL preserve history; household CASCADE), and
 * the idempotent backfill statement.
 *
 * SKIPS unless DB_DSN points at a pgsql:// connection.
 */
final class ChorePointsLedgerPgSmokeTest extends TestCase
{
    private Connection $db;
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
        $this->chores = new ChoreRepository($this->db);
        $this->households = new HouseholdRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_mark_done_writes_ledger_row_with_timestamptz(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->households->createForOwner('Den', $uid);
        $id = $this->makeChore($hid, $uid, 10);

        self::assertNotNull($this->chores->markDone($id, $uid));

        $row = $this->db->fetchOne('SELECT * FROM chore_points_ledger WHERE chore_id = :c', ['c' => $id]);
        self::assertNotNull($row);
        self::assertSame(10, (int) $row['points']);
        self::assertSame($uid, (int) $row['credited_user_id']);
    }

    public function test_check_constraint_rejects_negative_points(): void
    {
        $uid = $this->insertUser('b@example.com');
        $hid = $this->households->createForOwner('Den', $uid);

        $this->expectException(\PDOException::class);
        $this->db->run(
            "INSERT INTO chore_points_ledger (household_id, chore_id, credited_user_id, points, completed_at)
             VALUES (:h, NULL, :u, -1, NOW())",
            ['h' => $hid, 'u' => $uid],
        );
    }

    public function test_deleting_a_completed_chore_keeps_ledger_row_via_set_null(): void
    {
        $uid = $this->insertUser('c@example.com');
        $hid = $this->households->createForOwner('Den', $uid);
        $id = $this->makeChore($hid, $uid, 10);
        $this->chores->markDone($id, $uid);

        $this->chores->delete($id);

        $row = $this->db->fetchOne(
            'SELECT chore_id, points FROM chore_points_ledger WHERE household_id = :h AND credited_user_id = :u',
            ['h' => $hid, 'u' => $uid],
        );
        self::assertNotNull($row);          // history survived
        self::assertNull($row['chore_id']); // SET NULL
        self::assertSame(10, (int) $row['points']);
    }

    public function test_account_delete_orphans_credit_via_set_null(): void
    {
        $owner = $this->insertUser('owner@example.com');
        $temp = $this->insertUser('temp@example.com');
        $hid = $this->households->createForOwner('Den', $owner);
        $this->households->addMember($hid, $temp);
        $id = $this->makeChore($hid, $owner, 10);
        $this->chores->markDone($id, $temp);  // temp is the doer

        $this->db->run('DELETE FROM household_members WHERE user_id = :u', ['u' => $temp]);
        $this->db->run('DELETE FROM users WHERE id = :u', ['u' => $temp]);

        $credited = $this->db->fetchScalar(
            'SELECT credited_user_id FROM chore_points_ledger WHERE chore_id = :c',
            ['c' => $id],
        );
        self::assertNull($credited);  // SET NULL — points orphaned, not lost
    }

    public function test_household_delete_cascades_ledger(): void
    {
        $uid = $this->insertUser('d@example.com');
        $hid = $this->households->createForOwner('Den', $uid);
        $id = $this->makeChore($hid, $uid, 10);
        $this->chores->markDone($id, $uid);

        $this->db->run('DELETE FROM households WHERE id = :h', ['h' => $hid]);

        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chore_points_ledger WHERE household_id = :h',
            ['h' => $hid],
        );
        self::assertSame(0, $count);
    }

    public function test_backfill_statement_is_idempotent(): void
    {
        $uid = $this->insertUser('e@example.com');
        $hid = $this->households->createForOwner('Den', $uid);
        // A completed chore with NO ledger row (simulating a pre-v0.4.2 completion).
        $id = $this->makeChore($hid, $uid, 15);
        $this->db->run(
            "UPDATE chores SET completed_at = NOW(), completed_by = :u WHERE id = :id",
            ['u' => $uid, 'id' => $id],
        );

        $backfill = "INSERT INTO chore_points_ledger (household_id, chore_id, credited_user_id, points, completed_at)
                     SELECT c.household_id, c.id, COALESCE(c.completed_by, c.assigned_to), c.points, c.completed_at
                     FROM chores c
                     WHERE c.completed_at IS NOT NULL
                       AND COALESCE(c.completed_by, c.assigned_to) IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM chore_points_ledger l WHERE l.chore_id = c.id)";
        $this->db->run($backfill);
        $this->db->run($backfill);  // re-run must not double-insert

        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chore_points_ledger WHERE chore_id = :c',
            ['c' => $id],
        );
        self::assertSame(1, $count);
    }

    public function test_recent_completions_for_household_runs_on_pg(): void
    {
        // v0.4.3 query path: the household_members JOIN + ORDER BY DESC must run
        // cleanly on real PG (SQLite is permissive). Smoke-only — no business
        // logic; the unit tests cover correctness.
        $uid = $this->insertUser('f@example.com');
        $hid = $this->households->createForOwner('Den', $uid);
        $id = $this->makeChore($hid, $uid, 5);
        $this->chores->markDone($id, $uid);

        $map = $this->chores->recentCompletionsForHousehold($hid, '2000-01-01 00:00:00');

        self::assertArrayHasKey($uid, $map);
        self::assertCount(1, $map[$uid]);
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

    private function makeChore(int $hid, int $uid, int $points): int
    {
        return $this->chores->create([
            'household_id' => $hid, 'created_by' => $uid, 'title' => 'Dishes',
            'description' => '', 'points' => $points, 'due_at_local' => null,
            'assigned_to' => $uid, 'timezone' => 'Pacific/Auckland',
        ]);
    }
}
