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

    // ============================================================
    // v0.5.0 extensions — household lifecycle
    // ============================================================

    public function test_regenerate_join_code_produces_a_different_8char_code(): void
    {
        $owner = $this->insertUser('owner@example.com');
        $hid = $this->repo->createForOwner('Den', $owner);
        $before = (string) $this->db->fetchScalar(
            'SELECT join_code FROM households WHERE id = :id',
            ['id' => $hid],
        );

        $after = $this->repo->regenerateJoinCode($hid);

        self::assertNotSame($before, $after);
        self::assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $after);

        $persisted = (string) $this->db->fetchScalar(
            'SELECT join_code FROM households WHERE id = :id',
            ['id' => $hid],
        );
        self::assertSame($after, $persisted);
    }

    public function test_transfer_ownership_swaps_owner_and_target_roles_atomically(): void
    {
        $owner = $this->insertUser('owner@example.com');
        $target = $this->insertUser('target@example.com');
        $hid = $this->repo->createForOwner('Den', $owner);
        $this->repo->addMember($hid, $target);

        $this->repo->transferOwnership($hid, $owner, $target);

        self::assertFalse($this->repo->isOwner($owner, $hid));
        self::assertTrue($this->repo->isOwner($target, $hid));
        // Both remain members — neither was removed.
        self::assertTrue($this->repo->isMember($owner, $hid));
        self::assertTrue($this->repo->isMember($target, $hid));
    }

    public function test_transfer_ownership_throws_when_target_is_not_a_member(): void
    {
        $owner = $this->insertUser('owner@example.com');
        $stranger = $this->insertUser('stranger@example.com');
        $hid = $this->repo->createForOwner('Den', $owner);

        $this->expectException(\RuntimeException::class);
        try {
            $this->repo->transferOwnership($hid, $owner, $stranger);
        } finally {
            // The throw must roll back any partial state — owner is still owner.
            self::assertTrue($this->repo->isOwner($owner, $hid));
        }
    }

    public function test_transfer_ownership_throws_when_old_owner_is_not_owner(): void
    {
        // BL-3 guard: a stale caller can't promote arbitrary members to owner
        // by claiming to be the existing owner. The guarded UPDATE on
        // role='owner' affects 0 rows for a non-owner caller, so the txn
        // rolls back.
        $owner = $this->insertUser('owner@example.com');
        $someoneElse = $this->insertUser('else@example.com');
        $hid = $this->repo->createForOwner('Den', $owner);
        $this->repo->addMember($hid, $someoneElse);

        $this->expectException(\RuntimeException::class);
        $this->repo->transferOwnership($hid, $someoneElse, $owner);
    }

    public function test_delete_household_cascades_membership_rows(): void
    {
        $owner = $this->insertUser('owner@example.com');
        $member = $this->insertUser('member@example.com');
        $hid = $this->repo->createForOwner('Den', $owner);
        $this->repo->addMember($hid, $member);

        $this->repo->delete($hid);

        self::assertNull($this->repo->findById($hid));
        $remainingMembers = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM household_members WHERE household_id = :hid',
            ['hid' => $hid],
        );
        self::assertSame(0, $remainingMembers);
    }

    public function test_delete_household_is_a_noop_on_nonexistent_id(): void
    {
        // Idempotent — useful for the controller flow where the user may
        // have raced two delete-form submits.
        $this->repo->delete(999_999);
        self::assertNull($this->repo->findById(999_999));
    }

    // ============================================================
    // v0.6.12 — ownership-count + list for /me/delete pre-check
    // ============================================================

    public function test_count_owned_by_user_returns_zero_for_user_with_no_ownerships(): void
    {
        $uid = $this->insertUser('lonely@example.com');
        self::assertSame(0, $this->repo->countOwnedByUser($uid));
    }

    public function test_count_owned_by_user_counts_only_owner_role_rows(): void
    {
        // User owns 2 households; also a member (non-owner) of a 3rd.
        // The count should be 2, not 3.
        $owner = $this->insertUser('owner@example.com');
        $other = $this->insertUser('other@example.com');
        $h1 = $this->repo->createForOwner('First', $owner);
        $h2 = $this->repo->createForOwner('Second', $owner);
        $h3 = $this->repo->createForOwner('Third', $other);
        $this->repo->addMember($h3, $owner);  // owner joins as member

        self::assertSame(2, $this->repo->countOwnedByUser($owner));
        // Sanity: other user only owns h3.
        self::assertSame(1, $this->repo->countOwnedByUser($other));
    }

    public function test_list_owned_by_user_returns_id_name_sorted_by_name(): void
    {
        $owner = $this->insertUser('owner@example.com');
        $this->repo->createForOwner('Zebra', $owner);
        $this->repo->createForOwner('Alpha', $owner);
        $this->repo->createForOwner('Mango', $owner);

        $owned = $this->repo->listOwnedByUser($owner);

        self::assertCount(3, $owned);
        self::assertSame(['Alpha', 'Mango', 'Zebra'], array_column($owned, 'name'));
        // Each row has id + name keys only.
        foreach ($owned as $row) {
            self::assertArrayHasKey('id', $row);
            self::assertArrayHasKey('name', $row);
            self::assertIsInt($row['id']);
        }
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
