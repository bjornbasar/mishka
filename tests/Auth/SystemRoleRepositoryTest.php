<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\MishkaUserRepository;
use App\Auth\SystemRoleRepository;
use Karhu\Auth\PasswordHasher;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.19 — SystemRoleRepository unit tests.
 *
 * The first-user-admin sentinel claim is exercised in
 * MishkaUserRepositoryTest. These tests cover the helpers that
 * AccountController uses: isSystemAdmin, countSystemAdmins,
 * grantSystemAdmin (driver-aware idempotency), and listPromotionCandidates.
 *
 * Each test runs inside an outer transaction (begun in setUp, rolled
 * back in tearDown) for isolation — mirrors MishkaUserRepositoryTest.
 */
final class SystemRoleRepositoryTest extends TestCase
{
    private Connection $db;
    private SystemRoleRepository $repo;
    private MishkaUserRepository $users;
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new SystemRoleRepository($this->db);
        $this->users = new MishkaUserRepository($this->db);
        $this->hasher = new PasswordHasher();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_first_user_is_system_admin_via_sentinel_claim(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        self::assertTrue($this->repo->isSystemAdmin($uid));
    }

    public function test_second_user_is_not_system_admin_by_default(): void
    {
        $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $bob = $this->users->create('bob@example.com', $this->hasher->hash('pw'), 'Bob');
        self::assertFalse($this->repo->isSystemAdmin($bob));
    }

    public function test_count_system_admins_starts_at_one_after_first_register(): void
    {
        $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        self::assertSame(1, $this->repo->countSystemAdmins());
    }

    public function test_grant_system_admin_writes_a_new_row(): void
    {
        $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $bob = $this->users->create('bob@example.com', $this->hasher->hash('pw'), 'Bob');
        self::assertTrue($this->repo->grantSystemAdmin($bob));
        self::assertTrue($this->repo->isSystemAdmin($bob));
        self::assertSame(2, $this->repo->countSystemAdmins());
    }

    public function test_grant_system_admin_is_idempotent_on_re_grant(): void
    {
        $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $bob = $this->users->create('bob@example.com', $this->hasher->hash('pw'), 'Bob');
        self::assertTrue($this->repo->grantSystemAdmin($bob));
        // Re-grant: composite PK (user_id, role) conflict → silently skipped,
        // returns false (no new row written).
        self::assertFalse($this->repo->grantSystemAdmin($bob));
        self::assertSame(2, $this->repo->countSystemAdmins());
    }

    public function test_grant_system_admin_rejects_non_positive_uid(): void
    {
        self::assertFalse($this->repo->grantSystemAdmin(0));
        self::assertFalse($this->repo->grantSystemAdmin(-1));
    }

    public function test_list_promotion_candidates_excludes_caller_and_sentinel(): void
    {
        $alice = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $bob = $this->users->create('bob@example.com', $this->hasher->hash('pw'), 'Bob');
        $carol = $this->users->create('carol@example.com', $this->hasher->hash('pw'), 'Carol');

        $candidates = $this->repo->listPromotionCandidates($alice);

        self::assertCount(2, $candidates);
        $ids = array_column($candidates, 'id');
        self::assertContains($bob, $ids);
        self::assertContains($carol, $ids);
        self::assertNotContains($alice, $ids);
        self::assertNotContains(0, $ids);  // sentinel row never appears
    }

    public function test_list_promotion_candidates_returns_email_and_display_name(): void
    {
        $alice = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $this->users->create('bob@example.com', $this->hasher->hash('pw'), 'Bob');

        $candidates = $this->repo->listPromotionCandidates($alice);

        self::assertCount(1, $candidates);
        self::assertSame('bob@example.com', $candidates[0]['email']);
        self::assertSame('Bob', $candidates[0]['display_name']);
    }
}
