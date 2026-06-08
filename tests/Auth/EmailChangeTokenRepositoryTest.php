<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\EmailChangeTokenRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.11 — EmailChangeTokenRepository unit tests. Mirrors
 * EmailVerificationTokenRepositoryTest with one extra case: the new_email
 * column round-trip + normalisation.
 */
final class EmailChangeTokenRepositoryTest extends TestCase
{
    private Connection $db;

    private EmailChangeTokenRepository $repo;

    private int $uid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new EmailChangeTokenRepository($this->db);

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
        $raw = $this->repo->issue($this->uid, 'new@example.com');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);
    }

    public function test_issue_persists_only_hash_and_new_email(): void
    {
        $raw = $this->repo->issue($this->uid, 'new@example.com');

        $row = $this->db->fetchOne(
            'SELECT token_hash, new_email FROM email_change_tokens WHERE user_id = :uid',
            ['uid' => $this->uid],
        );
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $raw), $row['token_hash']);
        self::assertSame('new@example.com', $row['new_email']);
        // Raw token never lands in storage — neither in the hash column nor the row at large.
        self::assertStringNotContainsString($raw, json_encode($row) ?: '');
    }

    public function test_issue_normalises_new_email_lowercase_trim(): void
    {
        $this->repo->issue($this->uid, '  FOO@Bar.Com  ');

        $stored = $this->db->fetchScalar(
            'SELECT new_email FROM email_change_tokens WHERE user_id = :uid',
            ['uid' => $this->uid],
        );
        self::assertSame('foo@bar.com', $stored);
    }

    public function test_issue_throws_on_empty_new_email(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->issue($this->uid, '   ');
    }

    public function test_find_by_raw_token_returns_row_with_new_email_on_hit(): void
    {
        $raw = $this->repo->issue($this->uid, 'fresh@example.com');

        $row = $this->repo->findByRawToken($raw);

        self::assertNotNull($row);
        self::assertSame($this->uid, $row['user_id']);
        self::assertSame('fresh@example.com', $row['new_email']);
        self::assertNull($row['used_at']);
        self::assertNull($row['sent_at']);
    }

    public function test_find_by_raw_token_returns_null_for_bogus_token(): void
    {
        self::assertNull($this->repo->findByRawToken(str_repeat('0', 64)));
    }

    public function test_mark_sent_flips_sent_at_from_null(): void
    {
        $raw = $this->repo->issue($this->uid, 'new@example.com');
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
        $raw = $this->repo->issue($this->uid, 'new@example.com');
        $row = $this->repo->findByRawToken($raw);
        self::assertNotNull($row);

        // B6: first call wins; second call's guarded UPDATE matches zero rows.
        self::assertTrue($this->repo->redeemAtomically($row['id']));
        self::assertFalse($this->repo->redeemAtomically($row['id']));
    }

    public function test_invalidate_pending_for_user_flags_all_pending(): void
    {
        $this->repo->issue($this->uid, 'new@example.com');

        $this->repo->invalidatePendingForUser($this->uid);

        $pendingCount = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM email_change_tokens
             WHERE user_id = :uid AND used_at IS NULL',
            ['uid' => $this->uid],
        );
        self::assertSame(0, $pendingCount);
    }

    public function test_issue_invalidates_older_pending_for_same_user(): void
    {
        // Even when the new_email is different on each issue, the user's
        // older row is invalidated so at most one pending change-token exists.
        $raw1 = $this->repo->issue($this->uid, 'first@example.com');
        $raw2 = $this->repo->issue($this->uid, 'second@example.com');

        $row1 = $this->repo->findByRawToken($raw1);
        $row2 = $this->repo->findByRawToken($raw2);

        self::assertNotNull($row1);
        self::assertNotNull($row1['used_at']);   // older invalidated
        self::assertNotNull($row2);
        self::assertNull($row2['used_at']);      // newer is live
    }

    public function test_expired_token_is_rejected_by_redeem_atomically(): void
    {
        $raw = $this->repo->issue($this->uid, 'new@example.com');
        // Backdate expiry past now (B3: all timestamp math in PHP).
        $past = gmdate('Y-m-d H:i:s\Z', time() - 1);
        $this->db->run(
            'UPDATE email_change_tokens SET expires_at = :p WHERE user_id = :uid',
            ['p' => $past, 'uid' => $this->uid],
        );
        $row = $this->repo->findByRawToken($raw);
        self::assertNotNull($row);

        self::assertFalse($this->repo->redeemAtomically($row['id']));
    }

    public function test_issue_inside_outer_txn_does_not_commit_prematurely(): void
    {
        // setUp opened an outer txn. issue() inside it must NOT commit.
        $raw = $this->repo->issue($this->uid, 'new@example.com');
        self::assertNotNull($this->repo->findByRawToken($raw));

        $this->db->pdo()->rollBack();
        $this->db->pdo()->beginTransaction();   // restore outer-txn invariant

        // After rollback the token is gone — proves issue() didn't auto-commit.
        self::assertNull($this->repo->findByRawToken($raw));
    }
}
