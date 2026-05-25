<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\UserPreferenceRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserPreferenceRepository against the in-memory SQLite test DB.
 * Mirrors the MishkaUserRepositoryTest pattern: outer transaction in setUp,
 * rollback in tearDown for isolation.
 */
final class UserPreferenceRepositoryTest extends TestCase
{
    private Connection $db;
    private UserPreferenceRepository $repo;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new UserPreferenceRepository($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_get_returns_null_when_no_preference_row_exists(): void
    {
        $userId = $this->insertUser('a@example.com');
        self::assertNull($this->repo->getLastHouseholdId($userId));
    }

    public function test_set_inserts_new_preference_row(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Home');

        $this->repo->setLastHouseholdId($userId, $hid);

        self::assertSame($hid, $this->repo->getLastHouseholdId($userId));
    }

    public function test_set_updates_existing_row_via_upsert(): void
    {
        $userId = $this->insertUser('a@example.com');
        $hid1 = $this->insertHousehold('Home');
        $hid2 = $this->insertHousehold('Cabin');

        $this->repo->setLastHouseholdId($userId, $hid1);
        $this->repo->setLastHouseholdId($userId, $hid2);

        self::assertSame($hid2, $this->repo->getLastHouseholdId($userId));

        // Confirm there's only one row (upsert, not duplicate insert).
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_preferences WHERE user_id = :uid',
            ['uid' => $userId],
        );
        self::assertSame(1, $count);
    }

    public function test_last_household_id_becomes_null_when_household_is_deleted(): void
    {
        // ON DELETE SET NULL is FK-level behaviour — verify it actually fires.
        $userId = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Home');
        $this->repo->setLastHouseholdId($userId, $hid);

        $this->db->run('DELETE FROM households WHERE id = :id', ['id' => $hid]);

        self::assertNull($this->repo->getLastHouseholdId($userId));
    }

    private function insertUser(string $email): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name)
             VALUES (:email, :hash, :name) RETURNING id',
            ['email' => $email, 'hash' => 'unused', 'name' => 'Test'],
        );
    }

    private function insertHousehold(string $name): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO households (name, join_code) VALUES (:name, :code) RETURNING id',
            ['name' => $name, 'code' => bin2hex(random_bytes(4))],
        );
    }
}
