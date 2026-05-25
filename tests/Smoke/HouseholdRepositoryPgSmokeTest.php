<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Household\HouseholdRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * PG-only smoke tests for v0.2.
 *
 * The main suite runs against SQLite in-memory (the bootstrap regex-translates
 * the production schema). These tests exercise PG-specific behavior the SQLite
 * pass can't verify: SERIAL sequences, TIMESTAMPTZ defaults, CHECK constraints,
 * partial indexes with WHERE clauses, FK CASCADE, UNIQUE violation SQLSTATE.
 *
 * SKIPS unless DB_DSN points at a pgsql:// connection. Run via:
 *
 *   DB_DSN='pgsql:host=localhost;port=5432;dbname=mishka_test' \
 *   DB_USER=postgres DB_PASS=postgres \
 *     vendor/bin/phpunit --filter HouseholdRepositoryPgSmoke
 *
 * CI's pg-smoke job spins up a postgres:16 service container and runs migrate
 * before these tests.
 */
final class HouseholdRepositoryPgSmokeTest extends TestCase
{
    private Connection $db;
    private HouseholdRepository $repo;

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

        // Per-test transaction for isolation (rolled back in tearDown).
        $this->db->pdo()->beginTransaction();
        $this->repo = new HouseholdRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_for_owner_uses_serial_sequence_and_timestamptz_default(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->repo->createForOwner('Test', $userId);

        // SERIAL returns a positive int (don't assume a specific value).
        self::assertGreaterThan(0, $hid);

        // TIMESTAMPTZ default is auto-populated.
        $createdAt = $this->db->fetchScalar(
            'SELECT created_at FROM households WHERE id = :id', ['id' => $hid],
        );
        self::assertNotEmpty($createdAt);

        // The default came from NOW() — within the last minute.
        $age = abs(time() - strtotime((string) $createdAt));
        self::assertLessThan(60, $age, 'created_at should be NOW()-ish');
    }

    public function test_check_constraint_rejects_invalid_role(): void
    {
        $userId = $this->insertUser('b@example.com');
        $hid = $this->repo->createForOwner('Test', $userId);

        $this->expectException(\PDOException::class);
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role)
             VALUES (:hid, :uid, 'invalid_role')",
            ['hid' => $hid, 'uid' => $userId],
        );
    }

    public function test_partial_index_idx_household_members_role_exists_with_predicate(): void
    {
        // PG's pg_indexes view exposes the index definition; SQLite has no
        // equivalent. Verify the partial-index WHERE clause is intact.
        $defn = (string) $this->db->fetchScalar(
            "SELECT indexdef FROM pg_indexes
             WHERE indexname = 'idx_household_members_role'",
        );
        self::assertNotEmpty($defn, 'idx_household_members_role missing');
        self::assertStringContainsString("WHERE", $defn);
        self::assertStringContainsString("'owner'", $defn);
    }

    public function test_household_delete_cascades_to_household_members(): void
    {
        $userId = $this->insertUser('c@example.com');
        $hid = $this->repo->createForOwner('Test', $userId);

        // Membership row exists.
        self::assertTrue($this->repo->isOwner($userId, $hid));

        // Delete the household → FK CASCADE removes member rows.
        $this->db->run('DELETE FROM households WHERE id = :id', ['id' => $hid]);

        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM household_members WHERE household_id = :hid',
            ['hid' => $hid],
        );
        self::assertSame(0, $count, 'ON DELETE CASCADE should drop member rows');
    }

    public function test_join_code_unique_constraint_raises_unique_violation(): void
    {
        $userId = $this->insertUser('d@example.com');
        $this->db->run(
            "INSERT INTO households (name, join_code) VALUES ('A', 'TESTCODE')",
        );

        try {
            $this->db->run(
                "INSERT INTO households (name, join_code) VALUES ('B', 'TESTCODE')",
            );
            self::fail('Expected PDOException for duplicate join_code');
        } catch (\PDOException $e) {
            // PG SQLSTATE 23505 = unique_violation
            self::assertSame('23505', $e->getCode());
        }
    }

    private function insertUser(string $email): int
    {
        // Use a deterministic-unique email to dodge collisions across tests
        // (each test rolls back, but if rollback fails for any reason, the
        // suffix prevents cascade failures).
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
