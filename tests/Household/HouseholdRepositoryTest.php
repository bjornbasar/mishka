<?php

declare(strict_types=1);

namespace App\Tests\Household;

use App\Household\HouseholdRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class HouseholdRepositoryTest extends TestCase
{
    private Connection $db;
    private HouseholdRepository $repo;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new HouseholdRepository($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_for_owner_persists_household_and_owner_membership(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Test Den', $ownerId);

        self::assertGreaterThan(0, $hid);

        $row = $this->db->fetchOne('SELECT name, timezone FROM households WHERE id = :id', ['id' => $hid]);
        self::assertSame('Test Den', $row['name']);
        self::assertSame('Pacific/Auckland', $row['timezone']);

        $member = $this->db->fetchOne(
            'SELECT role FROM household_members WHERE household_id = :hid AND user_id = :uid',
            ['hid' => $hid, 'uid' => $ownerId],
        );
        self::assertSame('owner', $member['role']);
    }

    public function test_create_for_owner_generates_unique_join_code(): void
    {
        $a = $this->insertUser('a@example.com');
        $b = $this->insertUser('b@example.com');

        $h1 = $this->repo->createForOwner('A', $a);
        $h2 = $this->repo->createForOwner('B', $b);

        $code1 = $this->db->fetchScalar('SELECT join_code FROM households WHERE id = :id', ['id' => $h1]);
        $code2 = $this->db->fetchScalar('SELECT join_code FROM households WHERE id = :id', ['id' => $h2]);
        self::assertNotSame($code1, $code2);
        self::assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code1);
        self::assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code2);
    }

    public function test_create_for_owner_works_under_outer_transaction(): void
    {
        // The test harness already wraps each test in a transaction (setUp).
        // createForOwner must NOT call beginTransaction() blindly — that would throw.
        // (If this test passes at all, the nested-txn guard works.)
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Works under outer txn', $ownerId);
        self::assertGreaterThan(0, $hid);
    }

    public function test_find_by_id_returns_household_or_null(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Test', $ownerId);

        $found = $this->repo->findById($hid);
        self::assertNotNull($found);
        self::assertSame('Test', $found['name']);

        self::assertNull($this->repo->findById(99999));
    }

    public function test_find_by_join_code_is_case_insensitive(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Test', $ownerId);
        $code = $this->db->fetchScalar('SELECT join_code FROM households WHERE id = :id', ['id' => $hid]);

        self::assertSame($hid, $this->repo->findByJoinCode($code)['id']);
        self::assertSame($hid, $this->repo->findByJoinCode(strtolower($code))['id']);
        self::assertSame($hid, $this->repo->findByJoinCode("  $code  ")['id']);
        self::assertNull($this->repo->findByJoinCode('XXXXXXXX'));
    }

    public function test_add_member_inserts_member_role(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Test', $ownerId);
        $joinerId = $this->insertUser('joiner@example.com');

        $this->repo->addMember($hid, $joinerId);

        self::assertTrue($this->repo->isMember($joinerId, $hid));
        self::assertFalse($this->repo->isOwner($joinerId, $hid));
    }

    public function test_remove_member_works_for_non_owner(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Test', $ownerId);
        $joinerId = $this->insertUser('joiner@example.com');
        $this->repo->addMember($hid, $joinerId);

        $this->repo->removeMember($hid, $joinerId);

        self::assertFalse($this->repo->isMember($joinerId, $hid));
        // Owner is untouched.
        self::assertTrue($this->repo->isOwner($ownerId, $hid));
    }

    public function test_remove_member_rejects_owner(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Test', $ownerId);

        $this->expectException(\RuntimeException::class);
        $this->repo->removeMember($hid, $ownerId);
    }

    public function test_rename_updates_household_name(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Original', $ownerId);

        $this->repo->rename($hid, 'Renamed');

        self::assertSame('Renamed', $this->repo->findById($hid)['name']);
    }

    public function test_list_for_user_returns_each_membership_with_role(): void
    {
        $userId = $this->insertUser('multi@example.com');
        $hid1 = $this->repo->createForOwner('Owns This', $userId);
        $hid2 = $this->insertHouseholdSeparately();
        $this->repo->addMember($hid2, $userId);

        $rows = $this->repo->listForUser($userId);
        self::assertCount(2, $rows);

        $byId = array_column($rows, null, 'id');
        self::assertSame('owner', $byId[$hid1]['role']);
        self::assertSame('member', $byId[$hid2]['role']);
    }

    public function test_list_members_returns_each_member_with_email(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Test', $ownerId);
        $joinerId = $this->insertUser('joiner@example.com');
        $this->repo->addMember($hid, $joinerId);

        $members = $this->repo->listMembers($hid);
        self::assertCount(2, $members);

        $byUser = array_column($members, null, 'user_id');
        self::assertSame('owner@example.com', $byUser[$ownerId]['email']);
        self::assertSame('owner', $byUser[$ownerId]['role']);
        self::assertSame('member', $byUser[$joinerId]['role']);
    }

    public function test_list_for_user_excludes_sentinel_household_membership(): void
    {
        // The sentinel user (id=0) must never appear in any household — verify
        // listForUser doesn't accidentally return rows for it via the FK chain.
        $rows = $this->repo->listForUser(0);
        self::assertSame([], $rows);
    }

    public function test_is_member_and_is_owner_distinguish_roles(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Test', $ownerId);
        $joinerId = $this->insertUser('joiner@example.com');
        $this->repo->addMember($hid, $joinerId);

        self::assertTrue($this->repo->isMember($ownerId, $hid));
        self::assertTrue($this->repo->isOwner($ownerId, $hid));
        self::assertTrue($this->repo->isMember($joinerId, $hid));
        self::assertFalse($this->repo->isOwner($joinerId, $hid));

        $strangerId = $this->insertUser('stranger@example.com');
        self::assertFalse($this->repo->isMember($strangerId, $hid));
    }

    public function test_add_member_rejects_duplicate_membership(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Test', $ownerId);
        $joinerId = $this->insertUser('joiner@example.com');
        $this->repo->addMember($hid, $joinerId);

        $this->expectException(\Throwable::class);  // FK / PK violation surfaces as PDOException
        $this->repo->addMember($hid, $joinerId);
    }

    private function insertUser(string $email): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name)
             VALUES (:email, :hash, :name) RETURNING id',
            ['email' => $email, 'hash' => 'unused', 'name' => 'T'],
        );
    }

    private function insertHouseholdSeparately(): int
    {
        // Used when we want a household without going through createForOwner
        // (because createForOwner adds the creator as owner).
        return (int) $this->db->fetchScalar(
            'INSERT INTO households (name, join_code) VALUES (:n, :c) RETURNING id',
            ['n' => 'Other', 'c' => 'TESTCODE'],
        );
    }
}
