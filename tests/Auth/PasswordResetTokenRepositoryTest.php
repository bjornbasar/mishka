<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\PasswordResetTokenRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the v0.5.0 password-reset token store.
 *
 * Same shape as EmailVerificationTokenRepositoryTest but with 1h TTL and no
 * sent_at column (always-200 + 1500ms timing floor at the controller hides
 * SMTP failure from the user, so the token row carries no delivery state).
 */
final class PasswordResetTokenRepositoryTest extends TestCase
{
    private Connection $db;
    private PasswordResetTokenRepository $repo;
    private int $uid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new PasswordResetTokenRepository($this->db);

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
            'SELECT token_hash FROM password_reset_tokens WHERE user_id = :uid',
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
    }

    public function test_redeem_atomically_wins_first_loses_second(): void
    {
        $raw = $this->repo->issue($this->uid);
        $row = $this->repo->findByRawToken($raw);
        self::assertNotNull($row);

        self::assertTrue($this->repo->redeemAtomically($row['id']));
        self::assertFalse($this->repo->redeemAtomically($row['id']));
    }

    public function test_issue_invalidates_older_pending_for_same_user(): void
    {
        $raw1 = $this->repo->issue($this->uid);
        $raw2 = $this->repo->issue($this->uid);

        $row1 = $this->repo->findByRawToken($raw1);
        $row2 = $this->repo->findByRawToken($raw2);

        self::assertNotNull($row1);
        self::assertNotNull($row1['used_at']);
        self::assertNotNull($row2);
        self::assertNull($row2['used_at']);
    }

    public function test_expired_token_is_rejected_by_redeem_atomically(): void
    {
        $raw = $this->repo->issue($this->uid);
        $past = gmdate('Y-m-d H:i:s', time() - 1);
        $this->db->run(
            'UPDATE password_reset_tokens SET expires_at = :p WHERE user_id = :uid',
            ['p' => $past, 'uid' => $this->uid],
        );
        $row = $this->repo->findByRawToken($raw);
        self::assertNotNull($row);

        self::assertFalse($this->repo->redeemAtomically($row['id']));
    }

    public function test_ttl_is_one_hour(): void
    {
        // The 1h TTL is a security-critical decision (industry standard for
        // password reset). Lock it into a test so accidental TTL drift breaks
        // the build.
        $beforeIssue = time();
        $this->repo->issue($this->uid);
        $afterIssue = time();

        $expiresAt = $this->db->fetchScalar(
            'SELECT expires_at FROM password_reset_tokens WHERE user_id = :uid AND used_at IS NULL',
            ['uid' => $this->uid],
        );
        self::assertIsString($expiresAt);

        $expiresTs = (int) strtotime((string) $expiresAt . ' UTC');
        // Expiry must be 1h ± a few seconds (allowing for the time spent in issue()).
        self::assertGreaterThanOrEqual($beforeIssue + 3599, $expiresTs);
        self::assertLessThanOrEqual($afterIssue + 3601, $expiresTs);
    }

    public function test_invalidate_pending_for_user_flags_all_pending(): void
    {
        $this->repo->issue($this->uid);
        $this->repo->invalidatePendingForUser($this->uid);

        $pendingCount = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM password_reset_tokens
             WHERE user_id = :uid AND used_at IS NULL',
            ['uid' => $this->uid],
        );
        self::assertSame(0, $pendingCount);
    }
}
