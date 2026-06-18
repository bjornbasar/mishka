<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\MishkaUserRepository;
use App\Auth\SessionRepository;
use Karhu\Auth\PasswordHasher;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.7.0 — SessionRepository unit tests.
 *
 * Each test runs inside an outer transaction (begun in setUp, rolled
 * back in tearDown) for isolation — mirrors the SystemRoleRepositoryTest
 * pattern.
 */
final class SessionRepositoryTest extends TestCase
{
    private Connection $db;
    private SessionRepository $repo;
    private MishkaUserRepository $users;
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new SessionRepository($this->db);
        $this->users = new MishkaUserRepository($this->db);
        $this->hasher = new PasswordHasher();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_register_writes_row_and_returns_id(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $uuid = bin2hex(random_bytes(16));

        $id = $this->repo->register($uid, $uuid, 'Mozilla/Test', '192.168.1.1');

        self::assertGreaterThan(0, $id);
        $row = $this->repo->findByUuid($uuid);
        self::assertNotNull($row);
        self::assertSame($uid, $row['user_id']);
        self::assertSame('Mozilla/Test', $row['user_agent']);
        self::assertSame('192.168.1.1', $row['ip']);
        self::assertNull($row['revoked_at']);
    }

    public function test_register_truncates_user_agent_at_500_chars(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $uuid = bin2hex(random_bytes(16));
        $longUa = str_repeat('A', 600);

        $this->repo->register($uid, $uuid, $longUa, '');

        $row = $this->repo->findByUuid($uuid);
        self::assertNotNull($row);
        self::assertSame(500, strlen($row['user_agent']));
    }

    public function test_findByUuid_returns_null_for_unknown(): void
    {
        self::assertNull($this->repo->findByUuid('deadbeef00000000deadbeef00000000'));
    }

    public function test_findByUuid_returns_null_for_empty_string(): void
    {
        self::assertNull($this->repo->findByUuid(''));
    }

    public function test_listActiveForUser_returns_only_non_revoked_ordered_desc(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $u1 = bin2hex(random_bytes(16));
        $u2 = bin2hex(random_bytes(16));
        $u3 = bin2hex(random_bytes(16));

        $id1 = $this->repo->register($uid, $u1, 'A', '1.1.1.1');
        usleep(1_100_000);
        $id2 = $this->repo->register($uid, $u2, 'B', '2.2.2.2');
        usleep(1_100_000);
        $id3 = $this->repo->register($uid, $u3, 'C', '3.3.3.3');
        // Revoke the middle one
        $this->repo->revoke($uid, $id2);

        $list = $this->repo->listActiveForUser($uid);
        self::assertCount(2, $list);
        // Most-recently-used first
        self::assertSame($id3, $list[0]['id']);
        self::assertSame($id1, $list[1]['id']);
    }

    public function test_touch_updates_last_used_at(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $uuid = bin2hex(random_bytes(16));
        $id = $this->repo->register($uid, $uuid, 'A', '');

        $before = $this->repo->findByUuid($uuid);
        self::assertNotNull($before);
        $beforeTs = $before['last_used_at'];

        usleep(1_100_000);
        $this->repo->touch($id);

        $after = $this->repo->findByUuid($uuid);
        self::assertNotNull($after);
        self::assertNotSame($beforeTs, $after['last_used_at']);
    }

    public function test_touch_skips_revoked_rows(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $uuid = bin2hex(random_bytes(16));
        $id = $this->repo->register($uid, $uuid, 'A', '');
        $this->repo->revoke($uid, $id);

        $before = $this->repo->findByUuid($uuid);
        self::assertNotNull($before);
        $beforeTs = $before['last_used_at'];

        usleep(1_100_000);
        $this->repo->touch($id);  // no-op, row is revoked

        $after = $this->repo->findByUuid($uuid);
        self::assertNotNull($after);
        self::assertSame($beforeTs, $after['last_used_at']);
    }

    public function test_revoke_is_ownership_checked(): void
    {
        $alice = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $bob = $this->users->create('bob@example.com', $this->hasher->hash('pw'), 'Bob');
        $uuid = bin2hex(random_bytes(16));
        $id = $this->repo->register($alice, $uuid, 'A', '');

        self::assertFalse($this->repo->revoke($bob, $id));  // not Bob's session

        $row = $this->repo->findByUuid($uuid);
        self::assertNotNull($row);
        self::assertNull($row['revoked_at']);
    }

    public function test_revoke_is_idempotent_on_already_revoked(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $uuid = bin2hex(random_bytes(16));
        $id = $this->repo->register($uid, $uuid, 'A', '');

        self::assertTrue($this->repo->revoke($uid, $id));
        // Re-revoke: row already revoked, no row flipped this time.
        self::assertFalse($this->repo->revoke($uid, $id));
    }

    public function test_revokeByUuid_flips_active_row(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $uuid = bin2hex(random_bytes(16));
        $this->repo->register($uid, $uuid, 'A', '');

        self::assertSame(1, $this->repo->revokeByUuid($uuid));

        $row = $this->repo->findByUuid($uuid);
        self::assertNotNull($row);
        self::assertNotNull($row['revoked_at']);
    }

    public function test_revokeByUuid_empty_string_is_noop(): void
    {
        self::assertSame(0, $this->repo->revokeByUuid(''));
    }

    public function test_revokeAllForUserExcept_flips_others_keeps_except(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');
        $u1 = bin2hex(random_bytes(16));
        $u2 = bin2hex(random_bytes(16));
        $u3 = bin2hex(random_bytes(16));

        $id1 = $this->repo->register($uid, $u1, 'A', '');
        $id2 = $this->repo->register($uid, $u2, 'B', '');
        $id3 = $this->repo->register($uid, $u3, 'C', '');

        // Keep $id2 (current); revoke the rest.
        $affected = $this->repo->revokeAllForUserExcept($uid, $id2);
        self::assertSame(2, $affected);

        $r1 = $this->repo->findByUuid($u1);
        $r2 = $this->repo->findByUuid($u2);
        $r3 = $this->repo->findByUuid($u3);
        self::assertNotNull($r1);
        self::assertNotNull($r2);
        self::assertNotNull($r3);
        self::assertNotNull($r1['revoked_at']);
        self::assertNull($r2['revoked_at']);  // kept
        self::assertNotNull($r3['revoked_at']);
    }

    public function test_revokeAllForUserExcept_throws_on_zero_except(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');

        $this->expectException(\InvalidArgumentException::class);
        $this->repo->revokeAllForUserExcept($uid, 0);
    }

    public function test_revokeAllForUserExcept_throws_on_negative_except(): void
    {
        $uid = $this->users->create('alice@example.com', $this->hasher->hash('pw'), 'Alice');

        $this->expectException(\InvalidArgumentException::class);
        $this->repo->revokeAllForUserExcept($uid, -1);
    }
}
