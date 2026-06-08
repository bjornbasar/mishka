<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Auth\EmailChangeTokenRepository;
use App\Auth\MishkaUserRepository;
use Karhu\Auth\PasswordHasher;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.11 — PG-only smoke for the email-change flow.
 *
 * Verifies behaviour the SQLite pass can't catch:
 *   - email_change_tokens.user_id FK ON DELETE CASCADE
 *   - email_send_attempts.kind CHECK accepts 'change_email_request' (the
 *     PG_ONLY ALTER block must have landed on this DB)
 *   - email_send_attempts.kind CHECK still rejects unknown kinds (proves the
 *     ALTER didn't accidentally widen)
 *   - redeemAtomically's expires_at > :now comparison works under a non-UTC
 *     PG session TimeZone (defends the gmdate('Y-m-d H:i:s\Z', ...)
 *     explicit-UTC literal from decision #50)
 *   - UNIQUE violation on the users.email swap raises SQLSTATE 23505 (proves
 *     the AccountController's isUniqueViolation PG branch is real)
 *
 * SKIPS unless DB_DSN points at a pgsql:// connection. Uses an explicit
 * transaction + rollBack() so smoke-test-created rows never leak into shareddb.
 */
final class EmailChangePgSmokeTest extends TestCase
{
    private Connection $db;

    private EmailChangeTokenRepository $repo;

    private MishkaUserRepository $users;

    private PasswordHasher $hasher;

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
        $this->repo = new EmailChangeTokenRepository($this->db);
        $this->users = new MishkaUserRepository($this->db);
        $this->hasher = new PasswordHasher();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_user_delete_cascades_email_change_tokens(): void
    {
        $uid = $this->insertUser('cascade@example.com');
        $this->repo->issue($uid, 'new@example.com');

        $before = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM email_change_tokens WHERE user_id = :u',
            ['u' => $uid],
        );
        self::assertSame(1, $before);

        $this->db->run('DELETE FROM users WHERE id = :u', ['u' => $uid]);

        $after = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM email_change_tokens WHERE user_id = :u',
            ['u' => $uid],
        );
        self::assertSame(0, $after);
    }

    public function test_email_send_attempts_check_accepts_change_email_request(): void
    {
        $uid = $this->insertUser('check@example.com');

        $this->db->run(
            "INSERT INTO email_send_attempts (user_id, kind) VALUES (:u, 'change_email_request')",
            ['u' => $uid],
        );

        $count = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM email_send_attempts
             WHERE user_id = :u AND kind = 'change_email_request'",
            ['u' => $uid],
        );
        self::assertSame(1, $count);
    }

    public function test_email_send_attempts_check_still_rejects_unknown_kind(): void
    {
        $uid = $this->insertUser('reject@example.com');

        $this->expectException(\PDOException::class);
        $this->db->run(
            "INSERT INTO email_send_attempts (user_id, kind) VALUES (:u, 'never_a_kind')",
            ['u' => $uid],
        );
    }

    public function test_redeem_atomically_works_under_non_utc_session_timezone(): void
    {
        // Force the session into a non-UTC TimeZone. The gmdate('Y-m-d H:i:s\Z', ...)
        // literal must still be interpreted as UTC because of the trailing 'Z'.
        $this->db->run("SET TIME ZONE 'Pacific/Auckland'");

        $uid = $this->insertUser('tz@example.com');
        $raw = $this->repo->issue($uid, 'new@example.com');
        $row = $this->repo->findByRawToken($raw);
        self::assertNotNull($row);

        self::assertTrue($this->repo->redeemAtomically($row['id']));
        // Second call loses (single-use), proves the WHERE clause matched.
        self::assertFalse($this->repo->redeemAtomically($row['id']));
    }

    public function test_unique_violation_on_email_swap_raises_sqlstate_23505(): void
    {
        // Two users; swapping one's email to the other's must raise UNIQUE
        // violation with PG's documented SQLSTATE. We capture B's actual
        // post-insertion email (the helper prepends a random suffix for cross-
        // test uniqueness) and target it explicitly.
        $a = $this->insertUser('a-swap@example.com');
        $bId = $this->insertUser('b-swap@example.com');
        $bRow = $this->users->findById($bId);
        self::assertNotNull($bRow);
        $bEmail = $bRow['email'];

        try {
            $this->users->applyEmailSwap($a, $bEmail);
            self::fail('expected PDOException to be raised on UNIQUE violation');
        } catch (\PDOException $e) {
            self::assertSame('23505', $e->getCode());
        }
    }

    private function insertUser(string $email): int
    {
        $suffix = bin2hex(random_bytes(4));
        return $this->users->create($suffix . '-' . $email, $this->hasher->hash('x'), 'Smoke');
    }
}
