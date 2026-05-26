<?php

declare(strict_types=1);

namespace App\Calendar;

use Karhu\Db\Connection;

/**
 * Persistence for per-user iCal feed tokens.
 *
 * The token model:
 *   - 64-char hex token (32 bytes of random) generated server-side
 *   - SHA-256 hash stored as token_hash; the raw token IS the auth
 *     (returned to the caller once, shown to the user once, never recoverable)
 *   - Lookups go hash → row; the UNIQUE constraint on token_hash makes the
 *     match operation constant-time-enough for 256-bit entropy (brute force
 *     is computationally infeasible)
 *
 * Cap-at-3 (locked round-3): max 3 active tokens per user. The 4th generate
 * auto-revokes the oldest active token (sorted by created_at ASC) so
 * device-switching stays friction-free without manual cleanup.
 *
 * last_used_at is updated on every successful findByRawToken hit. Surface
 * this in the settings UI as a leak-detection signal (a feed URL being
 * scraped silently shows a recent timestamp the owner didn't expect).
 */
final class IcalFeedTokenRepository
{
    private const ACTIVE_TOKEN_CAP = 3;

    public function __construct(private readonly Connection $db) {}

    /**
     * Generate a new token + persist its hash. Auto-revokes the oldest active
     * token if the user is at the cap. Atomic — nested-txn guarded.
     *
     * @return string the raw hex token (shown to the user ONCE)
     */
    public function generate(int $userId, ?int $scopeHouseholdId = null): string
    {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);

        $pdo = $this->db->pdo();
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            // Cap enforcement: revoke oldest active rows until we're at most
            // (cap - 1) before inserting the new one. Loop-and-revoke handles
            // the rare case where the user is somehow over the cap already.
            while (true) {
                $activeCount = (int) $this->db->fetchScalar(
                    'SELECT COUNT(*) FROM ical_feed_tokens
                     WHERE user_id = :uid AND revoked_at IS NULL',
                    ['uid' => $userId],
                );
                if ($activeCount < self::ACTIVE_TOKEN_CAP) {
                    break;
                }
                $oldestId = $this->db->fetchScalar(
                    'SELECT id FROM ical_feed_tokens
                     WHERE user_id = :uid AND revoked_at IS NULL
                     ORDER BY created_at ASC, id ASC
                     LIMIT 1',
                    ['uid' => $userId],
                );
                if ($oldestId === false || $oldestId === null) {
                    break;  // defensive: nothing to revoke (race-resistant exit)
                }
                $this->db->run(
                    'UPDATE ical_feed_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = :id',
                    ['id' => (int) $oldestId],
                );
            }

            $this->db->run(
                'INSERT INTO ical_feed_tokens (user_id, scope_household_id, token_hash)
                 VALUES (:uid, :scope, :hash)',
                ['uid' => $userId, 'scope' => $scopeHouseholdId, 'hash' => $hash],
            );

            if ($transactionStarted) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $raw;
    }

    /**
     * Hash + lookup. Returns the matching row or null. On hit, updates
     * last_used_at to NOW().
     *
     * @return array{id: int, user_id: int, scope_household_id: ?int,
     *               token_hash: string, last_used_at: ?string,
     *               created_at: string, revoked_at: ?string}|null
     */
    public function findByRawToken(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);

        $row = $this->db->fetchOne(
            'SELECT * FROM ical_feed_tokens
             WHERE token_hash = :hash AND revoked_at IS NULL',
            ['hash' => $hash],
        );
        if ($row === null) {
            return null;
        }

        $this->db->run(
            'UPDATE ical_feed_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => (int) $row['id']],
        );

        return $this->normaliseRow($row);
    }

    /** Revoke a token. Requires ownership (the actor must be the token's user). */
    public function revoke(int $tokenId, int $byUserId): void
    {
        $row = $this->db->fetchOne(
            'SELECT user_id FROM ical_feed_tokens WHERE id = :id',
            ['id' => $tokenId],
        );
        if ($row === null) {
            throw new \RuntimeException("Token {$tokenId} not found");
        }
        if ((int) $row['user_id'] !== $byUserId) {
            throw new \RuntimeException("Token {$tokenId} is not owned by user {$byUserId}");
        }

        $this->db->run(
            'UPDATE ical_feed_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $tokenId],
        );
    }

    /**
     * Active (non-revoked) tokens for a user, ordered newest first for UI display.
     *
     * @return list<array{id: int, user_id: int, scope_household_id: ?int,
     *                    last_used_at: ?string, created_at: string, revoked_at: ?string}>
     */
    public function listActiveForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ical_feed_tokens
             WHERE user_id = :uid AND revoked_at IS NULL
             ORDER BY created_at DESC, id DESC',
            ['uid' => $userId],
        );
        return array_map(fn(array $r): array => $this->normaliseRow($r), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, user_id: int, scope_household_id: ?int,
     *               token_hash: string, last_used_at: ?string,
     *               created_at: string, revoked_at: ?string}
     */
    private function normaliseRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'scope_household_id' => $row['scope_household_id'] === null ? null : (int) $row['scope_household_id'],
            'token_hash' => (string) $row['token_hash'],
            'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
            'created_at' => (string) $row['created_at'],
            'revoked_at' => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        ];
    }
}
