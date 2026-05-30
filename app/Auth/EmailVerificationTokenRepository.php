<?php

declare(strict_types=1);

namespace App\Auth;

use Karhu\Db\Connection;

/**
 * v0.5.0 — persistence for email-verification tokens.
 *
 * Token model mirrors IcalFeedTokenRepository:
 *   - 64-char hex token (32 bytes random) generated server-side
 *   - SHA-256 hash stored as token_hash; the raw token IS the auth
 *   - Lookups go hash → row via the UNIQUE constraint on token_hash
 *
 * v0.5.0 contract (vs ical tokens):
 *   - Single-use (`used_at IS NULL` is the pending predicate); redeem flips it
 *     atomically via a guarded UPDATE so two concurrent redeems can't both win
 *     (B6).
 *   - 24h TTL stamped in PHP via gmdate('Y-m-d H:i:s') for SQLite portability —
 *     `NOW() + INTERVAL '24 hours'` doesn't translate to SQLite (B3).
 *   - `sent_at` records whether SMTP delivery succeeded (H2); the resend flow
 *     uses NULL-vs-set for ops-side observability. The user-facing banner is a
 *     single-copy "Please verify your email" regardless (decision U-3) — sent_at
 *     never flows to the user, only to logs.
 *   - issue() invalidates the user's older pending rows in the same txn so a
 *     stockpile of valid tokens can't accumulate.
 *
 * Nested-txn guard pattern (IcalFeedTokenRepository:46-95) lets repos call
 * issue() either inside an outer transaction or standalone without double-
 * committing.
 */
final class EmailVerificationTokenRepository
{
    /** Verification TTL — family-friendly 24h window. */
    private const TTL_SECONDS = 86_400;

    public function __construct(private readonly Connection $db) {}

    /**
     * Generate a new verification token + persist its hash. Invalidates any
     * older pending tokens for the same user atomically.
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
            // Invalidate prior pending rows so only ONE token is live per user
            // at a time. Mark as used (set used_at) rather than deleting so an
            // audit trail of issued-tokens remains.
            $this->db->run(
                'UPDATE email_verification_tokens SET used_at = :now
                 WHERE user_id = :uid AND used_at IS NULL',
                ['now' => $now, 'uid' => $userId],
            );

            $this->db->run(
                'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
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
     * by the caller via redeemAtomically) or null.
     *
     * @return array{id: int, user_id: int, token_hash: string,
     *               expires_at: string, used_at: ?string, sent_at: ?string,
     *               created_at: string}|null
     */
    public function findByRawToken(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);

        $row = $this->db->fetchOne(
            'SELECT * FROM email_verification_tokens WHERE token_hash = :hash',
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
     * The caller checks the return value before flipping markEmailVerified —
     * a false result means another request redeemed it first, or it's expired.
     */
    public function redeemAtomically(int $tokenId): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        $rows = $this->db->run(
            'UPDATE email_verification_tokens SET used_at = :now
             WHERE id = :id AND used_at IS NULL AND expires_at > :now',
            ['now' => $now, 'id' => $tokenId],
        );

        return $rows === 1;
    }

    /**
     * Flag SMTP delivery success (H2). Called after the Mailer returns true.
     * `sent_at IS NULL` is the ops signal for "SMTP failed at issue time" —
     * the resend flow uses it for observability; the user never sees it.
     */
    public function markSent(int $tokenId): void
    {
        $this->db->run(
            'UPDATE email_verification_tokens SET sent_at = :now WHERE id = :id',
            ['now' => gmdate('Y-m-d H:i:s'), 'id' => $tokenId],
        );
    }

    /**
     * Mark every pending token for a user as used. Called on successful
     * verification + when a fresh token is issued elsewhere to ensure only
     * one token is live at a time.
     */
    public function invalidatePendingForUser(int $userId): void
    {
        $this->db->run(
            'UPDATE email_verification_tokens SET used_at = :now
             WHERE user_id = :uid AND used_at IS NULL',
            ['now' => gmdate('Y-m-d H:i:s'), 'uid' => $userId],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, user_id: int, token_hash: string,
     *               expires_at: string, used_at: ?string, sent_at: ?string,
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
            'sent_at'    => $row['sent_at'] === null ? null : (string) $row['sent_at'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
