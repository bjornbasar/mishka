<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Household\HouseholdRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Round-4 BL-3 PG smoke — `SELECT … FOR UPDATE` in transferOwnership.
 *
 * SQLite doesn't honour FOR UPDATE (single-writer), so the suite needs a PG
 * pass to verify the lock-then-update-then-commit flow works end-to-end on
 * real PG. We can't easily simulate a true race in a single PHPUnit process,
 * but we CAN assert:
 *   - transferOwnership round-trips on PG (no syntax error from FOR UPDATE)
 *   - a stale UPDATE (target removed between validate + lock) rolls back
 *     cleanly with a thrown RuntimeException; the old owner stays the owner.
 *
 * SKIPS unless DB_DSN points at a pgsql:// connection.
 */
final class HouseholdRepositoryForUpdateSmokeTest extends TestCase
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
        $this->db->pdo()->beginTransaction();
        $this->repo = new HouseholdRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_transfer_ownership_round_trips_on_pg_with_for_update(): void
    {
        $owner = $this->insertUser('o@example.com');
        $target = $this->insertUser('t@example.com');
        $hid = $this->repo->createForOwner('Den', $owner);
        $this->repo->addMember($hid, $target);

        $this->repo->transferOwnership($hid, $owner, $target);

        self::assertTrue($this->repo->isOwner($target, $hid));
        self::assertFalse($this->repo->isOwner($owner, $hid));
    }

    public function test_transfer_ownership_rolls_back_when_target_no_longer_a_member(): void
    {
        $owner = $this->insertUser('o@example.com');
        $target = $this->insertUser('t@example.com');
        $hid = $this->repo->createForOwner('Den', $owner);
        // Target is NOT added as a member — simulates the kick-between-
        // validation-and-lock race.

        try {
            $this->repo->transferOwnership($hid, $owner, $target);
            self::fail('Expected transferOwnership to throw when target not a member.');
        } catch (\RuntimeException $e) {
            // Expected — verify the txn rolled back and owner is unchanged.
            self::assertTrue($this->repo->isOwner($owner, $hid));
            self::assertFalse($this->repo->isOwner($target, $hid));
        }
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
