<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\HouseholdAuthorizer;
use App\Household\HouseholdRepository;
use Karhu\Db\Connection;
use Karhu\Error\ForbiddenException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseholdAuthorizer.
 *
 * The authorizer manipulates $_SESSION directly (clears stale active_household_id
 * keys on the kicked-user self-heal path). Tests wipe $_SESSION in setUp.
 */
final class HouseholdAuthorizerTest extends TestCase
{
    private Connection $db;
    private HouseholdRepository $repo;
    private HouseholdAuthorizer $auth;
    private int $ownerId;
    private int $memberId;
    private int $strangerId;
    private int $hid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new HouseholdRepository($this->db);
        $this->auth = new HouseholdAuthorizer($this->repo);
        $_SESSION = [];

        $this->ownerId = $this->insertUser('owner@example.com');
        $this->memberId = $this->insertUser('member@example.com');
        $this->strangerId = $this->insertUser('stranger@example.com');
        $this->hid = $this->repo->createForOwner('Test', $this->ownerId);
        $this->repo->addMember($this->hid, $this->memberId);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $_SESSION = [];
    }

    public function test_require_member_passes_for_member(): void
    {
        $this->auth->requireMember($this->memberId, $this->hid);
        $this->auth->requireMember($this->ownerId, $this->hid);
        self::assertTrue(true);  // No exception → pass
    }

    public function test_require_member_throws_for_stranger(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->auth->requireMember($this->strangerId, $this->hid);
    }

    public function test_require_member_clears_stale_session_and_redirects_when_kicked(): void
    {
        // Simulate: user was a member, session captured that, then got kicked.
        $_SESSION['active_household_id'] = $this->hid;
        $_SESSION['active_household_role'] = 'member';

        try {
            $this->auth->requireMember($this->strangerId, $this->hid);
            self::fail('Expected ForbiddenException');
        } catch (ForbiddenException $e) {
            self::assertSame('/household/setup', $e->redirectTo);
            // The stale session keys must be cleared so the next request lands cleanly.
            self::assertArrayNotHasKey('active_household_id', $_SESSION);
            self::assertArrayNotHasKey('active_household_role', $_SESSION);
        }
    }

    public function test_require_member_does_not_clear_session_for_unrelated_household(): void
    {
        // User's session says they're active in $this->hid (which they ARE), but
        // the failing check is against a DIFFERENT household — don't clear.
        $_SESSION['active_household_id'] = $this->hid;
        $_SESSION['active_household_role'] = 'member';

        $otherHid = $this->repo->createForOwner('Other', $this->insertUser('other@example.com'));

        try {
            $this->auth->requireMember($this->memberId, $otherHid);
            self::fail('Expected ForbiddenException');
        } catch (ForbiddenException $e) {
            self::assertNull($e->redirectTo);  // Foreign household → 403, no redirect
            self::assertSame($this->hid, $_SESSION['active_household_id']);  // Untouched
        }
    }

    public function test_require_owner_passes_for_owner_throws_for_member(): void
    {
        $this->auth->requireOwner($this->ownerId, $this->hid);  // no throw

        $this->expectException(ForbiddenException::class);
        $this->auth->requireOwner($this->memberId, $this->hid);
    }

    private function insertUser(string $email): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name)
             VALUES (:email, :hash, :name) RETURNING id',
            ['email' => $email, 'hash' => 'unused', 'name' => 'T'],
        );
    }
}
