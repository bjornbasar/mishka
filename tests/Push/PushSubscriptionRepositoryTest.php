<?php

declare(strict_types=1);

namespace App\Tests\Push;

use App\Push\PushSubscriptionRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.0 — push subscription store. Mirrors IcalFeedTokenRepository's
 * SHA-256-hashed shape (here just storing the 3 raw values, no hashing — the
 * browser-issued endpoint + keys ARE the public+secret material). Soft-delete
 * via revoked_at; UNIQUE(user_id, endpoint) makes re-subscribe idempotent
 * (wakes a revoked row by clearing the flag).
 */
final class PushSubscriptionRepositoryTest extends TestCase
{
    private Connection $db;
    private PushSubscriptionRepository $repo;
    private int $uid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new PushSubscriptionRepository($this->db);

        $this->uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'T') RETURNING id",
            ['e' => 'u-' . bin2hex(random_bytes(3)) . '@example.com'],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_register_persists_subscription_and_returns_id(): void
    {
        $id = $this->repo->register(
            $this->uid,
            'https://fcm.googleapis.com/fcm/send/abc123',
            'pk-base64url',
            'auth-base64url',
            'Mozilla/5.0 (iPhone) Safari/16.4',
        );

        self::assertGreaterThan(0, $id);
        $rows = $this->repo->listActiveForUser($this->uid);
        self::assertCount(1, $rows);
        self::assertSame('https://fcm.googleapis.com/fcm/send/abc123', $rows[0]['endpoint']);
        self::assertSame('Mozilla/5.0 (iPhone) Safari/16.4', $rows[0]['user_agent']);
    }

    public function test_register_is_idempotent_and_wakes_a_revoked_row(): void
    {
        // Plain register
        $first = $this->repo->register(
            $this->uid, 'https://fcm.example/abc', 'pk1', 'auth1', 'Chrome',
        );
        // Revoke
        $this->repo->revoke($this->uid, $first);
        self::assertSame([], $this->repo->listActiveForUser($this->uid));

        // Re-register the same endpoint — UNIQUE(user_id, endpoint) should
        // wake the row by clearing revoked_at and refreshing the keys.
        $second = $this->repo->register(
            $this->uid, 'https://fcm.example/abc', 'pk2', 'auth2', 'Chrome',
        );

        self::assertSame($first, $second, 'Same row id should be reused');
        $active = $this->repo->listActiveForUser($this->uid);
        self::assertCount(1, $active);
        self::assertSame('pk2', $active[0]['p256dh']);   // updated keys
        self::assertSame('auth2', $active[0]['auth']);
    }

    public function test_list_active_for_user_excludes_revoked(): void
    {
        $a = $this->repo->register($this->uid, 'https://fcm.example/a', 'pk', 'auth', null);
        $b = $this->repo->register($this->uid, 'https://fcm.example/b', 'pk', 'auth', null);
        $this->repo->revoke($this->uid, $b);

        $active = $this->repo->listActiveForUser($this->uid);
        self::assertCount(1, $active);
        self::assertSame($a, $active[0]['id']);
    }

    public function test_revoke_rejects_foreign_user(): void
    {
        $a = $this->repo->register($this->uid, 'https://fcm.example/a', 'pk', 'auth', null);

        // Another user.
        $stranger = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'S') RETURNING id",
            ['e' => 'stranger-' . bin2hex(random_bytes(3)) . '@example.com'],
        );

        $this->expectException(\RuntimeException::class);
        try {
            $this->repo->revoke($stranger, $a);
        } finally {
            // Subscription must still be active.
            self::assertCount(1, $this->repo->listActiveForUser($this->uid));
        }
    }

    public function test_mark_revoked_works_without_user_check(): void
    {
        // markRevoked is the worker's path on HTTP 410 — no user context,
        // just the subscription id from the in-flight job.
        $a = $this->repo->register($this->uid, 'https://fcm.example/a', 'pk', 'auth', null);
        $this->repo->markRevoked($a);

        self::assertSame([], $this->repo->listActiveForUser($this->uid));
    }

    public function test_touch_updates_last_used_at(): void
    {
        $a = $this->repo->register($this->uid, 'https://fcm.example/a', 'pk', 'auth', null);

        $before = $this->repo->listActiveForUser($this->uid)[0]['last_used_at'];
        self::assertNull($before);

        $this->repo->touch($a);

        $after = $this->repo->listActiveForUser($this->uid)[0]['last_used_at'];
        self::assertNotNull($after);
    }

    public function test_fk_cascade_on_user_delete_drops_subscriptions(): void
    {
        $this->repo->register($this->uid, 'https://fcm.example/a', 'pk', 'auth', null);
        $this->repo->register($this->uid, 'https://fcm.example/b', 'pk', 'auth', null);

        $this->db->run('DELETE FROM users WHERE id = :id', ['id' => $this->uid]);

        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :id',
            ['id' => $this->uid],
        );
        self::assertSame(0, $count);
    }

    public function test_get_for_send_returns_active_subscriptions_with_keys(): void
    {
        // Worker needs the keys to actually send a push.
        $this->repo->register($this->uid, 'https://fcm.example/a', 'pk-a', 'auth-a', null);

        $subs = $this->repo->getForSend($this->uid);
        self::assertCount(1, $subs);
        self::assertSame('pk-a', $subs[0]['p256dh']);
        self::assertSame('auth-a', $subs[0]['auth']);
        self::assertSame('https://fcm.example/a', $subs[0]['endpoint']);
    }
}
