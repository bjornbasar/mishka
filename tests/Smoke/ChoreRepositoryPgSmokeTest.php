<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Chores\ChoreRepository;
use App\Household\HouseholdRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * PG-only smoke tests for v0.4.0's `chores` table.
 *
 * Exercises PG-specific behaviour the SQLite pass can't verify:
 *   - SERIAL sequence + TIMESTAMPTZ defaults + INTEGER DEFAULT 0
 *   - `pointsTallyForHousehold` runs cleanly under PostgreSQL's strict GROUP BY
 *     rule (SQLite tolerates a non-grouped ORDER BY column; PG rejects it — this
 *     is the only thing that catches a regression in the MIN(joined_at) ORDER BY)
 *   - household-delete CASCADE to chores
 *
 * SKIPS unless DB_DSN points at a pgsql:// connection.
 */
final class ChoreRepositoryPgSmokeTest extends TestCase
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

    public function test_create_uses_serial_and_integer_default_and_timestamptz(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->households->createForOwner('Test', $userId);

        // No explicit points → exercises INTEGER NOT NULL DEFAULT 0 on real PG.
        $id = $this->chores->create([
            'household_id' => $hid,
            'created_by' => $userId,
            'title' => 'Take out bins',
            'description' => '',
            'due_at_local' => '2026-07-14 09:00:00',
            'timezone' => 'Pacific/Auckland',
        ]);

        self::assertGreaterThan(0, $id);
        $chore = $this->chores->findById($id);
        self::assertNotNull($chore);
        self::assertSame(0, $chore['points']);

        $created = $this->db->fetchScalar('SELECT created_at FROM chores WHERE id = :id', ['id' => $id]);
        self::assertLessThan(60, abs(time() - strtotime((string) $created)), 'created_at should be NOW()-ish');
    }

    public function test_leaderboard_runs_under_pg_group_by_rule(): void
    {
        // The SQLite suite can't catch a non-grouped ORDER BY column; PG does.
        // (The ledger-backed leaderboard orders by an aggregate alias + MIN(joined_at).)
        $alice = $this->insertUser('alice@example.com');
        $hid = $this->households->createForOwner('Tally', $alice);
        $id = $this->chores->create([
            'household_id' => $hid, 'created_by' => $alice, 'title' => 'Dishes',
            'description' => '', 'points' => 10, 'due_at_local' => null,
            'assigned_to' => $alice, 'timezone' => 'Pacific/Auckland',
        ]);
        $this->chores->markDone($id, $alice);

        $board = $this->chores->leaderboardForHousehold($hid, '2000-01-01 00:00:00');

        self::assertNotEmpty($board);
        self::assertSame(10, $board[0]['total_points']);
        self::assertSame(10, $board[0]['week_points']);  // weekStart in the past → counts as this week
    }

    public function test_household_delete_cascades_to_chores(): void
    {
        $userId = $this->insertUser('b@example.com');
        $hid = $this->households->createForOwner('Cascade', $userId);
        $id = $this->chores->create([
            'household_id' => $hid, 'created_by' => $userId, 'title' => 'Doomed',
            'description' => '', 'due_at_local' => null, 'timezone' => 'Pacific/Auckland',
        ]);

        self::assertNotNull($this->chores->findById($id));

        $this->db->run('DELETE FROM households WHERE id = :hid', ['hid' => $hid]);

        self::assertNull($this->chores->findById($id));
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
}
