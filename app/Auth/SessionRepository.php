<?php

declare(strict_types=1);

namespace App\Auth;

use Karhu\Db\Connection;

/**
 * v0.7.0 — per-device session tracking for the /me/sessions UI.
 *
 * One row per active web session. Rows are INSERTed at login by
 * AuthController::establishSession, or lazy-backfilled by
 * SessionRevocationGuard on first post-deploy request from a session
 * that pre-dates v0.7.0. CASCADE on user delete (decision #53 chain)
 * handles account-delete cleanup automatically.
 *
 * session_uuid is an APP-LEVEL identifier (bin2hex(random_bytes(16)) — 32
 * hex chars, no PII), NOT PHP session_id(). PHP session ids rotate on
 * every Session::regenerate() (login + password change); the app-level
 * UUID survives the rotation because $_SESSION keys persist across
 * regenerate. This keeps the user_sessions row reliably findable for
 * the full lifetime of one logical session.
 *
 * Driver-agnostic SQL — works on PG + SQLite (mirrors HouseholdRepository
 * + BadgeAwardRepository patterns).
 *
 * revoke() returns bool (true iff a row was flipped) so the
 * SessionsController can 403 on ownership mismatch — diverges from
 * IcalFeedTokenRepository's throw-on-mismatch pattern by design (round-2
 * C15 acknowledged inconsistency; bool is the cleaner controller contract
 * for an HTTP boundary that translates not-found/not-owned into 403).
 */
final class SessionRepository
{
    private const UA_MAX = 500;

    public function __construct(private readonly Connection $db) {}

    public function register(int $userId, string $sessionUuid, string $userAgent, string $ip): int
    {
        $this->db->run(
            'INSERT INTO user_sessions (user_id, session_uuid, user_agent, ip)
             VALUES (:uid, :uuid, :ua, :ip)',
            [
                'uid' => $userId,
                'uuid' => $sessionUuid,
                'ua' => substr($userAgent, 0, self::UA_MAX),
                'ip' => $ip,
            ],
        );
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array{id:int,user_id:int,session_uuid:string,user_agent:string,ip:string,created_at:string,last_used_at:string,revoked_at:?string}|null
     */
    public function findByUuid(string $sessionUuid): ?array
    {
        if ($sessionUuid === '') {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT id, user_id, session_uuid, user_agent, ip, created_at, last_used_at, revoked_at
             FROM user_sessions
             WHERE session_uuid = :uuid',
            ['uuid' => $sessionUuid],
        );
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'session_uuid' => (string) $row['session_uuid'],
            'user_agent' => (string) $row['user_agent'],
            'ip' => (string) $row['ip'],
            'created_at' => (string) $row['created_at'],
            'last_used_at' => (string) $row['last_used_at'],
            'revoked_at' => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        ];
    }

    /**
     * @return list<array{id:int,session_uuid:string,user_agent:string,ip:string,created_at:string,last_used_at:string}>
     */
    public function listActiveForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, session_uuid, user_agent, ip, created_at, last_used_at
             FROM user_sessions
             WHERE user_id = :uid AND revoked_at IS NULL
             ORDER BY last_used_at DESC',
            ['uid' => $userId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'session_uuid' => (string) $r['session_uuid'],
                'user_agent' => (string) $r['user_agent'],
                'ip' => (string) $r['ip'],
                'created_at' => (string) $r['created_at'],
                'last_used_at' => (string) $r['last_used_at'],
            ];
        }
        return $out;
    }

    /**
     * Fire-and-forget last_used_at update on each authed request.
     * Round-3 C4: the AND revoked_at IS NULL guard is defensive against
     * a TOCTOU race (session revoked between findByUuid + touch). A
     * no-op UPDATE (rowCount=0) is silently fine — touch is fire-and-
     * forget.
     */
    public function touch(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->db->run(
            'UPDATE user_sessions SET last_used_at = CURRENT_TIMESTAMP
             WHERE id = :id AND revoked_at IS NULL',
            ['id' => $id],
        );
    }

    /**
     * Ownership-checked soft-delete for /me/sessions/{id}/revoke.
     * Returns true iff a row was actually flipped (caller's user_id matched
     * + row was still active). False covers: not-owned, not-found, and
     * already-revoked (idempotent semantics).
     */
    public function revoke(int $userId, int $sessionId): bool
    {
        if ($userId <= 0 || $sessionId <= 0) {
            return false;
        }
        $rows = $this->db->run(
            'UPDATE user_sessions SET revoked_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :uid AND revoked_at IS NULL',
            ['id' => $sessionId, 'uid' => $userId],
        );
        return $rows === 1;
    }

    /**
     * For logout (AuthController::logout) — mark the current session
     * revoked by its app-level UUID. No ownership check needed because
     * the caller has the UUID from $_SESSION which only this session
     * could have written. Returns rowCount; callers may ignore.
     */
    public function revokeByUuid(string $sessionUuid): int
    {
        if ($sessionUuid === '') {
            return 0;
        }
        return $this->db->run(
            'UPDATE user_sessions SET revoked_at = CURRENT_TIMESTAMP
             WHERE session_uuid = :uuid AND revoked_at IS NULL',
            ['uuid' => $sessionUuid],
        );
    }

    /**
     * For password-change UI consistency: mark every OTHER active session
     * for this user revoked. The current session row (passed as
     * $exceptSessionId) stays active. Returns rowCount.
     *
     * Round-2 C3: refuses $exceptSessionId <= 0 with InvalidArgumentException.
     * A 0 would match all rows and revoke the current session too — silent
     * mass-revoke bug. Callers MUST pass a valid current session id; if
     * they can't determine one (e.g. AppTestCase skips the guard that would
     * have backfilled), they should skip the call entirely at the
     * controller level.
     */
    public function revokeAllForUserExcept(int $userId, int $exceptSessionId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        if ($exceptSessionId <= 0) {
            throw new \InvalidArgumentException(
                'revokeAllForUserExcept requires a positive exceptSessionId; '
                . 'pass-through with $exceptSessionId=0 would revoke the current session too.',
            );
        }
        return $this->db->run(
            'UPDATE user_sessions SET revoked_at = CURRENT_TIMESTAMP
             WHERE user_id = :uid AND id != :except AND revoked_at IS NULL',
            ['uid' => $userId, 'except' => $exceptSessionId],
        );
    }
}
