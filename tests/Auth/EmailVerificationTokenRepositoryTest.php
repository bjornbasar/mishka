<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\EmailVerificationTokenRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the v0.5.0 email-verification token store.
 *
 * Mirrors the IcalFeedTokenRepository test shape (token-pattern parity locked
 * in the plan). Coverage:
 *   - issue() returns a 64-char hex token; only the SHA-256 hash is persisted
 *   - findByRawToken() matches by hash; returns null for bogus tokens
 *   - markSent() flips sent_at from NULL → timestamp (H2)
 *   - redeemAtomically() wins on first call, loses on second (B6 single-use race)
 *   - invalidatePendingForUser() flags all pending tokens as used
 *   - issue() invalidates older pending rows for the same user atomically
 *   - expired tokens are rejected by redeemAtomically (TTL check in PHP, B3)
 *   - nested-txn guard: issue() inside an outer txn does not commit prematurely
 */
final class EmailVerificationTokenRepositoryTest extends TestCase
{
    private Connection $db;
    private EmailVerificationTokenRepository $repo;
    private int $uid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new EmailVerificationTokenRepository($this->db);

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

    public function test_issue_returns_64_char_hex_token(): void
    {
        $raw = $this->repo->issue($this->uid);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);
    }

    public function test_issue_persists_only_hash_not_raw(): void
    {
        $raw = $this->repo->issue($this->uid);

        $row = $this->db->fetchOne(
            'SELECT token_hash FROM email_verification_tokens WHERE user_id = :uid',
            ['uid' => $this->uid],
        );
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $raw), $row['token_hash']);
        self::assertStringNotContainsString($raw, json_encode($row) ?: '');
    }

    public function test_find_by_raw_token_returns_row_on_hit(): void
    {
        $raw = $this->repo->issue($this->uid);

        $row = $this->repo->findByRawToken($raw);

        self::assertNotNull($row);
        self::assertSame($this->uid, $row['user_id']);
        self::assertNull($row['used_at']);
        self::assertNull($row['sent_at']);  // H2: NULL until markSent fires
    }

    public function test_find_by_raw_token_returns_null_for_bogus_token(): void
    {
        self::assertNull($this->repo->findByRawToken(str_repeat('0', 64)));
    }

    public function test_mark_sent_flips_sent_at_from_null(): void
    {
        $raw = $this->repo->issue($this->uid);
        $row = $this->repo->findByRawToken($raw);
        self::assertNotNull($row);
        self::assertNull($row['sent_at']);

        $this->repo->markSent($row['id']);

        $after = $this->repo->findByRawToken($raw);
        self::assertNotNull($after);
        self::assertNotNull($after['sent_at']);
    }

    public function test_redeem_atomically_wins_first_loses_second(): void
    {
        $raw = $this->repo->issue($this->uid);
        $row = $this->repo->findByRawToken($raw);
        self::assertNotNull($row);

        // B6: the first call must win (single-use redemption)
        self::assertTrue($this->repo->redeemAtomically($row['id']));
        // The second call must lose — used_at is now set, so the guarded
        // UPDATE matches zero rows.
        self::assertFalse($this->repo->redeemAtomically($row['id']));
    }

    public function test_invalidate_pending_for_user_flags_all_pending(): void
    {
        $raw1 = $this->repo->issue($this->uid);
        // issue() invalidates older pending — verify the latest is still pending
        // and the call below kills it too.
        $latestRow = $this->repo->findByRawToken($raw1);
        self::assertNotNull($latestRow);
        self::assertNull($latestRow['used_at']);

        $this->repo->invalidatePendingForUser($this->uid);

        // After invalidate, no pending rows remain.
        $pendingCount = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM email_verification_tokens
             WHERE user_id = :uid AND used_at IS NULL',
            ['uid' => $this->uid],
        );
        self::assertSame(0, $pendingCount);
    }

    public function test_issue_invalidates_older_pending_for_same_user(): void
    {
        $raw1 = $this->repo->issue($this->uid);
        $raw2 = $this->repo->issue($this->uid);

        // After the second issue, the first token is no longer pending.
        $row1 = $this->repo->findByRawToken($raw1);
        $row2 = $this->repo->findByRawToken($raw2);

        self::assertNotNull($row1);
        self::assertNotNull($row1['used_at']);   // older was invalidated
        self::assertNotNull($row2);
        self::assertNull($row2['used_at']);      // newer is the live pending
    }

    public function test_expired_token_is_rejected_by_redeem_atomically(): void
    {
        $raw = $this->repo->issue($this->uid);
        // Backdate expiry to 1 second ago in UTC (B3: all timestamp math in PHP)
        $past = gmdate('Y-m-d H:i:s', time() - 1);
        $this->db->run(
            'UPDATE email_verification_tokens SET expires_at = :p WHERE user_id = :uid',
            ['p' => $past, 'uid' => $this->uid],
        );
        $row = $this->repo->findByRawToken($raw);
        self::assertNotNull($row);

        self::assertFalse($this->repo->redeemAtomically($row['id']));
    }

    public function test_issue_inside_outer_txn_does_not_commit_prematurely(): void
    {
        // setUp() already opened an outer txn. Issuing inside it must NOT
        // commit — the tearDown rollback must wipe the row.
        $raw = $this->repo->issue($this->uid);
        self::assertNotNull($this->repo->findByRawToken($raw));

        $this->db->pdo()->rollBack();
        $this->db->pdo()->beginTransaction();   // restore outer-txn invariant

        // After rollback, the token is gone — proving issue() didn't auto-commit.
        self::assertNull($this->repo->findByRawToken($raw));
    }
}
