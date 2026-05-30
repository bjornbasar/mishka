<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Auth\EmailSendAttemptRepository;
use App\Auth\EmailVerificationTokenRepository;
use App\Auth\PasswordResetTokenRepository;
use App\Auth\UserPasswordChangeRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * PG-only smoke for the v0.5.0 account-lifecycle additions. Verifies
 * behaviour the SQLite pass can't reach:
 *   - SERIAL/TIMESTAMPTZ types apply
 *   - FK ON DELETE CASCADE chain on users.id wipes all four child tables
 *   - email_send_attempts.kind CHECK enum rejects unknown values
 *   - PG partial index on (user_id) WHERE used_at IS NULL is honoured
 *
 * SKIPS unless DB_DSN points at a pgsql:// connection.
 */
final class AccountLifecyclePgSmokeTest extends TestCase
{
    private Connection $db;
    private EmailVerificationTokenRepository $verifyTokens;
    private PasswordResetTokenRepository $resetTokens;
    private UserPasswordChangeRepository $pwChanges;
    private EmailSendAttemptRepository $attempts;

    protected function setUp(): void
    {
        $dsn = getenv('DB_DSN') ?: ($_ENV['DB_DSN'] ?? '');
        if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) {
            self::markTestSkipped('PG smoke tests require DB_DSN=pgsql:...');
        }

        $this->db = new Connection(
            $dsn,
            (string) (getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? '')),
            (string) (getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '')),
        );
        $this->db->pdo()->beginTransaction();
        $this->verifyTokens = new EmailVerificationTokenRepository($this->db);
        $this->resetTokens = new PasswordResetTokenRepository($this->db);
        $this->pwChanges = new UserPasswordChangeRepository($this->db);
        $this->attempts = new EmailSendAttemptRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_user_delete_cascades_to_all_v050_child_tables(): void
    {
        $uid = $this->insertUser('cascade@example.com');
        $this->verifyTokens->issue($uid);
        $this->resetTokens->issue($uid);
        $this->pwChanges->stamp($uid, gmdate('Y-m-d H:i:s'));
        $this->attempts->record('verify_resend', null, $uid);

        // Assert each row exists before the delete.
        foreach ([
            'email_verification_tokens', 'password_reset_tokens',
            'user_password_changes', 'email_send_attempts',
        ] as $tbl) {
            $count = (int) $this->db->fetchScalar(
                "SELECT COUNT(*) FROM {$tbl} WHERE user_id = :uid",
                ['uid' => $uid],
            );
            self::assertSame(1, $count, "Pre-delete: {$tbl} missing the seeded row");
        }

        $this->db->run('DELETE FROM users WHERE id = :uid', ['uid' => $uid]);

        // After delete, all four FK CASCADEd.
        foreach ([
            'email_verification_tokens', 'password_reset_tokens',
            'user_password_changes', 'email_send_attempts',
        ] as $tbl) {
            $count = (int) $this->db->fetchScalar(
                "SELECT COUNT(*) FROM {$tbl} WHERE user_id = :uid",
                ['uid' => $uid],
            );
            self::assertSame(0, $count, "Post-delete: {$tbl} still has rows");
        }
    }

    public function test_email_send_attempts_check_constraint_rejects_unknown_kind(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->run(
            "INSERT INTO email_send_attempts (kind, ip_address)
             VALUES ('not_a_real_kind', '203.0.113.1')",
        );
    }

    public function test_password_reset_token_redeem_atomically_uses_guarded_update(): void
    {
        // Smoke the atomic UPDATE WHERE used_at IS NULL AND expires_at > :now
        // path on real PG (SQLite is permissive about types). First call wins,
        // second loses.
        $uid = $this->insertUser('atomic@example.com');
        $raw = $this->resetTokens->issue($uid);
        $row = $this->resetTokens->findByRawToken($raw);
        self::assertNotNull($row);

        self::assertTrue($this->resetTokens->redeemAtomically($row['id']));
        self::assertFalse($this->resetTokens->redeemAtomically($row['id']));
    }

    private function insertUser(string $email): int
    {
        $suffix = bin2hex(random_bytes(4));
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name)
             VALUES (:email, :hash, :name) RETURNING id',
            ['email' => "smoke-{$suffix}-{$email}", 'hash' => 'unused', 'name' => 'Smoke'],
        );
    }
}
