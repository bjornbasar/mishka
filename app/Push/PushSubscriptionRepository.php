<?php

declare(strict_types=1);

namespace App\Push;

use Karhu\Db\Connection;

/**
 * v0.6.0 — persistence for browser push subscriptions.
 *
 * Lifecycle:
 *   - register() — called by NotificationsController on a successful
 *     `pushManager.subscribe()`. Idempotent via UNIQUE(user_id, endpoint):
 *     re-subscribing from the same browser wakes a revoked row (clears
 *     revoked_at + refreshes keys) instead of creating a duplicate.
 *   - revoke() — called by the user via the /me/notifications UI. Soft-
 *     delete; requires ownership.
 *   - markRevoked() — called by the worker on HTTP 410 from the push svc.
 *     No ownership check (worker only knows the subscription id from the
 *     in-flight job).
 *   - touch() — called by the worker on successful send. Updates
 *     last_used_at so the UI can show "last used" per device + ops can
 *     spot stale subscriptions.
 *
 * Schema lives in db/schema.sql under v0.6.0.
 */
final class PushSubscriptionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Idempotent register. Returns the row id (same row on re-subscribe).
     * Updates keys on conflict — browsers can rotate p256dh / auth without
     * changing the endpoint.
     */
    public function register(
        int $userId,
        string $endpoint,
        string $p256dh,
        string $auth,
        ?string $userAgent,
    ): int {
        // PG + SQLite 3.24+ ON CONFLICT (user_id, endpoint) DO UPDATE works
        // for both engines. RETURNING id gives the row id whether we inserted
        // fresh or woke a revoked one.
        $id = $this->db->fetchScalar(
            'INSERT INTO push_subscriptions
                (user_id, endpoint, p256dh, auth, user_agent)
             VALUES (:uid, :endpoint, :p256dh, :auth, :ua)
             ON CONFLICT (user_id, endpoint) DO UPDATE
             SET p256dh     = EXCLUDED.p256dh,
                 auth       = EXCLUDED.auth,
                 user_agent = EXCLUDED.user_agent,
                 revoked_at = NULL
             RETURNING id',
            [
                'uid' => $userId,
                'endpoint' => $endpoint,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'ua' => $userAgent,
            ],
        );

        return (int) $id;
    }

    /**
     * @return list<array{id: int, endpoint: string, user_agent: ?string,
     *                    p256dh: string, auth: string,
     *                    created_at: string, last_used_at: ?string}>
     */
    public function listActiveForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, endpoint, user_agent, p256dh, auth, created_at, last_used_at
             FROM push_subscriptions
             WHERE user_id = :uid AND revoked_at IS NULL
             ORDER BY created_at DESC',
            ['uid' => $userId],
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'endpoint' => (string) $r['endpoint'],
                'user_agent' => $r['user_agent'] === null ? null : (string) $r['user_agent'],
                'p256dh' => (string) $r['p256dh'],
                'auth' => (string) $r['auth'],
                'created_at' => (string) $r['created_at'],
                'last_used_at' => $r['last_used_at'] === null ? null : (string) $r['last_used_at'],
            ];
        }
        return $out;
    }

    /**
     * Worker-side read. Same shape as listActiveForUser but exposed for
     * clarity at the call site — the worker only cares about the data it
     * needs to actually send.
     *
     * @return list<array{id: int, endpoint: string, p256dh: string, auth: string}>
     */
    public function getForSend(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, endpoint, p256dh, auth
             FROM push_subscriptions
             WHERE user_id = :uid AND revoked_at IS NULL',
            ['uid' => $userId],
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'endpoint' => (string) $r['endpoint'],
                'p256dh' => (string) $r['p256dh'],
                'auth' => (string) $r['auth'],
            ];
        }
        return $out;
    }

    /** User-facing revoke. Requires the row to belong to the caller. */
    public function revoke(int $userId, int $subscriptionId): void
    {
        $owner = $this->db->fetchScalar(
            'SELECT user_id FROM push_subscriptions WHERE id = :id',
            ['id' => $subscriptionId],
        );
        if ($owner === null || $owner === false) {
            throw new \RuntimeException("Subscription {$subscriptionId} not found");
        }
        if ((int) $owner !== $userId) {
            throw new \RuntimeException("Subscription {$subscriptionId} is not owned by user {$userId}");
        }

        $this->db->run(
            'UPDATE push_subscriptions SET revoked_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $subscriptionId],
        );
    }

    /**
     * Worker-side revoke (HTTP 410 from push svc). No ownership check —
     * the subscription id came from a job we already dispatched.
     */
    public function markRevoked(int $subscriptionId): void
    {
        $this->db->run(
            'UPDATE push_subscriptions SET revoked_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $subscriptionId],
        );
    }

    /** Worker-side last-used touch after a successful send. */
    public function touch(int $subscriptionId): void
    {
        $this->db->run(
            'UPDATE push_subscriptions SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $subscriptionId],
        );
    }

    /**
     * PushScanCommand entry point: every user with at least one active
     * subscription, so the scanner can iterate without scanning the full
     * users table.
     *
     * @return list<int> user_ids
     */
    public function listUserIdsWithActiveSubscriptions(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT user_id FROM push_subscriptions WHERE revoked_at IS NULL'
        );
        return array_map(static fn(array $r): int => (int) $r['user_id'], $rows);
    }
}
