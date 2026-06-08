<?php

declare(strict_types=1);

namespace App\Auth;

use Karhu\Db\Connection;

/**
 * v0.6.11 — persistence for email-change tokens.
 *
 * Mirrors EmailVerificationTokenRepository (decision #37 — same token shape:
 * 64-hex raw token shown once via email link; SHA-256 hashed in token_hash;
 * UNIQUE; single-use atomic redeem). One added column: `new_email` carries
 * the user's requested new address in the token row, so the swap is a
 * single-row redeem with no side-table join.
 *
 * v0.6.11 contract:
 *   - 24h TTL stamped in PHP (matches EmailVerificationTokenRepository)
 *   - issue() invalidates the user's older pending rows so only one swap-token
 *     is ever live per user (mirrors EVT::issue)
 *   - `new_email` is normalised at issue time (strtolower + trim — same as
 *     MishkaUserRepository::normaliseEmail)
 *   - expires_at + used_at comparisons use gmdate('Y-m-d H:i:s\Z', ...)
 *     explicit-UTC literal (decision #50 — PG TIMESTAMPTZ TimeZone defence)
 *   - issue() throws InvalidArgumentException if the post-normalisation
 *     new_email is empty (defensive depth — controller already validates)
 *
 * Nested-txn guard pattern same as IcalFeedTokenRepository / EVT — lets
 * callers wrap issue() in their own transaction without double-committing.
 */
final class EmailChangeTokenRepository
{
    /** Email-change TTL — matches email verification's 24h window. */
    private const TTL_SECONDS = 86_400;

    public function __construct(private readonly Connection $db) {}

    /**
     * Generate a new email-change token + persist its hash and the new email.
     * Invalidates any older pending tokens for the same user atomically.
     *
     * @return string the raw hex token (emailed to the user; never recoverable)
     */
    public function issue(int $userId, string $newEmail): string
    {
        $newEmail = strtolower(trim($newEmail));
        if ($newEmail === '') {
            throw new \InvalidArgumentException('newEmail cannot be empty');
        }

        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        // Explicit-UTC 'Z' literal per decision #50: defends PG TIMESTAMPTZ
        // comparison from session-TimeZone drift on redeemAtomically's
        // expires_at > :now check. SQLite TEXT compares lexicographically;
        // the 'Z' suffix doesn't affect ordering.
        $expiresAt = gmdate('Y-m-d H:i:s\Z', time() + self::TTL_SECONDS);
        $now = gmdate('Y-m-d H:i:s\Z');

        $pdo = $this->db->pdo();
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            // Invalidate prior pending rows so only ONE token is live per user
            // at a time. Mark used rather than delete so the row stays as an
            // audit trail of issuance.
            $this->db->run(
                'UPDATE email_change_tokens SET used_at = :now
                 WHERE user_id = :uid AND used_at IS NULL',
                ['now' => $now, 'uid' => $userId],
            );

            $this->db->run(
                'INSERT INTO email_change_tokens (user_id, token_hash, new_email, expires_at)
                 VALUES (:uid, :hash, :ne, :exp)',
                ['uid' => $userId, 'hash' => $hash, 'ne' => $newEmail, 'exp' => $expiresAt],
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
     * by the caller via redeemAtomically) or null.
     *
     * @return array{id: int, user_id: int, token_hash: string, new_email: string,
     *               expires_at: string, used_at: ?string, sent_at: ?string,
     *               created_at: string}|null
     */
    public function findByRawToken(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);

        $row = $this->db->fetchOne(
            'SELECT * FROM email_change_tokens WHERE token_hash = :hash',
            ['hash' => $hash],
        );
        if ($row === null) {
            return null;
        }

        return $this->normaliseRow($row);
    }

    /**
     * Atomic single-use redemption (B6). Updates used_at = NOW only if the
     * token is still pending AND unexpired. Returns true iff we won the race
     * (the UPDATE matched exactly 1 row).
     *
     * The caller checks the return value before applying the swap — a false
     * result means another request redeemed it first, or it's expired.
     */
    public function redeemAtomically(int $tokenId): bool
    {
        $now = gmdate('Y-m-d H:i:s\Z');

        $rows = $this->db->run(
            'UPDATE email_change_tokens SET used_at = :now
             WHERE id = :id AND used_at IS NULL AND expires_at > :now',
            ['now' => $now, 'id' => $tokenId],
        );

        return $rows === 1;
    }

    /**
     * Flag SMTP delivery success. Called after sendEmailChange returns true.
     * Asymmetric with email verification (which logs and continues silently);
     * for change-email the controller surfaces flash_error on send-failure
     * and does NOT call markSent (decision #52).
     */
    public function markSent(int $tokenId): void
    {
        $this->db->run(
            'UPDATE email_change_tokens SET sent_at = :now WHERE id = :id',
            ['now' => gmdate('Y-m-d H:i:s\Z'), 'id' => $tokenId],
        );
    }

    /**
     * Mark every pending token for a user as used. Used by the swap path so
     * a successful change closes any other in-flight change-tokens for the
     * same user (defence in depth — issue() already invalidates on each new
     * issuance, but this closes the swap-then-stale-link window).
     */
    public function invalidatePendingForUser(int $userId): void
    {
        $this->db->run(
            'UPDATE email_change_tokens SET used_at = :now
             WHERE user_id = :uid AND used_at IS NULL',
            ['now' => gmdate('Y-m-d H:i:s\Z'), 'uid' => $userId],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, user_id: int, token_hash: string, new_email: string,
     *               expires_at: string, used_at: ?string, sent_at: ?string,
     *               created_at: string}
     */
    private function normaliseRow(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'user_id'    => (int) $row['user_id'],
            'token_hash' => (string) $row['token_hash'],
            'new_email'  => (string) $row['new_email'],
            'expires_at' => (string) $row['expires_at'],
            'used_at'    => $row['used_at'] === null ? null : (string) $row['used_at'],
            'sent_at'    => $row['sent_at'] === null ? null : (string) $row['sent_at'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
