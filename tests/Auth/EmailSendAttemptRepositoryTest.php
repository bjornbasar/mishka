<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\EmailSendAttemptRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.5.0 app-layer rate limit (H4).
 *
 * Two bucket kinds:
 *   - password_reset_request : 5 / 10min / IP (anonymous, IP-keyed)
 *   - verify_resend          : 3 / 10min / user (authed, user-keyed)
 *
 * Coverage:
 *   - record() inserts a row of the right kind with the matching key
 *   - countRecent() counts only rows in the rolling window
 *   - countRecent() filters by kind (the two buckets are independent)
 *   - countRecent() with key-by-IP ignores the user_id column and vice versa
 *   - rows older than the window are excluded
 */
final class EmailSendAttemptRepositoryTest extends TestCase
{
    private Connection $db;
    private EmailSendAttemptRepository $repo;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new EmailSendAttemptRepository($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_record_and_count_recent_by_ip(): void
    {
        $this->repo->record('password_reset_request', '203.0.113.1', null);
        $this->repo->record('password_reset_request', '203.0.113.1', null);
        $this->repo->record('password_reset_request', '203.0.113.2', null);  // different IP

        self::assertSame(2, $this->repo->countRecentByIp('password_reset_request', '203.0.113.1', 10));
        self::assertSame(1, $this->repo->countRecentByIp('password_reset_request', '203.0.113.2', 10));
        self::assertSame(0, $this->repo->countRecentByIp('password_reset_request', '203.0.113.9', 10));
    }

    public function test_record_and_count_recent_by_user(): void
    {
        $uid1 = $this->makeUser('a@example.com');
        $uid2 = $this->makeUser('b@example.com');

        $this->repo->record('verify_resend', null, $uid1);
        $this->repo->record('verify_resend', null, $uid1);
        $this->repo->record('verify_resend', null, $uid2);

        self::assertSame(2, $this->repo->countRecentByUser('verify_resend', $uid1, 10));
        self::assertSame(1, $this->repo->countRecentByUser('verify_resend', $uid2, 10));
    }

    public function test_count_recent_filters_by_kind(): void
    {
        // password_reset_request and verify_resend share row storage but are
        // independent buckets — counts must NOT cross-contaminate.
        $uid = $this->makeUser('c@example.com');
        $this->repo->record('password_reset_request', '203.0.113.1', null);
        $this->repo->record('verify_resend', null, $uid);

        self::assertSame(1, $this->repo->countRecentByIp('password_reset_request', '203.0.113.1', 10));
        self::assertSame(0, $this->repo->countRecentByIp('verify_resend', '203.0.113.1', 10));
        self::assertSame(1, $this->repo->countRecentByUser('verify_resend', $uid, 10));
        self::assertSame(0, $this->repo->countRecentByUser('password_reset_request', $uid, 10));
    }

    public function test_rows_outside_the_window_are_excluded(): void
    {
        $this->repo->record('password_reset_request', '203.0.113.1', null);
        // Backdate the row to 11 minutes ago — outside the 10-minute window.
        $past = gmdate('Y-m-d H:i:s', time() - 11 * 60);
        $this->db->run(
            'UPDATE email_send_attempts SET attempted_at = :p WHERE ip_address = :ip',
            ['p' => $past, 'ip' => '203.0.113.1'],
        );

        self::assertSame(0, $this->repo->countRecentByIp('password_reset_request', '203.0.113.1', 10));
        // But broadening the window to 15 minutes catches it.
        self::assertSame(1, $this->repo->countRecentByIp('password_reset_request', '203.0.113.1', 15));
    }

    private function makeUser(string $email): int
    {
        return (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'T') RETURNING id",
            ['e' => 'u-' . bin2hex(random_bytes(3)) . '-' . $email],
        );
    }
}
