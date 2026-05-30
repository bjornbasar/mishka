<?php

declare(strict_types=1);

namespace App\Auth;

use Karhu\Db\Connection;

/**
 * v0.5.0 — persistence for password-reset tokens.
 *
 * Same shape as EmailVerificationTokenRepository (token pattern + atomic single-
 * use redeem + invalidate-older-on-issue) but with two contract differences:
 *
 *   - 1h TTL instead of 24h (industry standard for password reset)
 *   - No `sent_at` column. `/password-reset` is always-200 with a 1500ms timing
 *     floor at the controller (B4), so SMTP failure is hidden from the user;
 *     the token row carries no delivery state.
 *
 * All expiry math in PHP via gmdate (B3); guarded UPDATE on redeem (B6).
 */
final class PasswordResetTokenRepository
{
    /** Reset TTL — 1h, industry standard. */
    private const TTL_SECONDS = 3600;

    public function __construct(private readonly Connection $db) {}

    /**
     * Generate a new reset token + persist its hash. Invalidates older pending
     * tokens for the same user in the same txn.
     *
     * @return string the raw hex token (emailed to the user; never recoverable)
     */
    public function issue(int $userId): string
    {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);
        $now = gmdate('Y-m-d H:i:s');

        $pdo = $this->db->pdo();
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            $this->db->run(
                'UPDATE password_reset_tokens SET used_at = :now
                 WHERE user_id = :uid AND used_at IS NULL',
                ['now' => $now, 'uid' => $userId],
            );

            $this->db->run(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                 VALUES (:uid, :hash, :exp)',
                ['uid' => $userId, 'hash' => $hash, 'exp' => $expiresAt],
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
     * Hash + lookup. Returns the matching row (pending OR used; expiry checked
     * by redeemAtomically) or null.
     *
     * @return array{id: int, user_id: int, token_hash: string,
     *               expires_at: string, used_at: ?string,
     *               created_at: string}|null
     */
    public function findByRawToken(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);

        $row = $this->db->fetchOne(
            'SELECT * FROM password_reset_tokens WHERE token_hash = :hash',
            ['hash' => $hash],
        );
        if ($row === null) {
            return null;
        }

        return $this->normaliseRow($row);
    }

    /**
     * Atomic single-use redemption (B6). UPDATE matches only if the token is
     * still pending AND unexpired. Returns true iff we won the race.
     */
    public function redeemAtomically(int $tokenId): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        $rows = $this->db->run(
            'UPDATE password_reset_tokens SET used_at = :now
             WHERE id = :id AND used_at IS NULL AND expires_at > :now',
            ['now' => $now, 'id' => $tokenId],
        );

        return $rows === 1;
    }

    /**
     * Mark every pending token for a user as used. Called on successful reset
     * so a racing parallel reset link gets nuked too.
     */
    public function invalidatePendingForUser(int $userId): void
    {
        $this->db->run(
            'UPDATE password_reset_tokens SET used_at = :now
             WHERE user_id = :uid AND used_at IS NULL',
            ['now' => gmdate('Y-m-d H:i:s'), 'uid' => $userId],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, user_id: int, token_hash: string,
     *               expires_at: string, used_at: ?string,
     *               created_at: string}
     */
    private function normaliseRow(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'user_id'    => (int) $row['user_id'],
            'token_hash' => (string) $row['token_hash'],
            'expires_at' => (string) $row['expires_at'],
            'used_at'    => $row['used_at'] === null ? null : (string) $row['used_at'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
