<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\UserPasswordChangeRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.5.0 session-revocation stamp (H1 + round-4 BL-1).
 *
 * The middleware compares `password_changed_at` to `Session::get('auth_time')`;
 * presence of a row + the stamp value is the source-of-truth.
 *
 * Coverage:
 *   - changedAt() returns null when the user has never changed their password
 *     (legacy users; permutation (a) + (c) of the guard predicate)
 *   - stamp() upserts a new row when none exists
 *   - stamp(int $uid, string $now) accepts a pinned timestamp (BL-2: prevents
 *     self-revoke from microsecond drift between the password-write timestamp
 *     and the session's new auth_time)
 *   - second stamp() updates the timestamp (subsequent password changes)
 */
final class UserPasswordChangeRepositoryTest extends TestCase
{
    private Connection $db;
    private UserPasswordChangeRepository $repo;
    private int $uid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new UserPasswordChangeRepository($this->db);

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

    public function test_changed_at_returns_null_when_never_changed(): void
    {
        // Legacy / new-user state — no row in user_password_changes.
        // Permutation (a) or (c) of the SessionRevocationGuard predicate.
        self::assertNull($this->repo->changedAt($this->uid));
    }

    public function test_stamp_inserts_a_row_when_none_exists(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->repo->stamp($this->uid, $now);

        self::assertSame($now, $this->repo->changedAt($this->uid));
    }

    public function test_stamp_accepts_a_pinned_timestamp_unchanged(): void
    {
        // BL-2 invariant: handler pins $now once + passes it explicitly, so the
        // password_changed_at value MUST match what the caller passed (so the
        // session's auth_time === password_changed_at and the guard does not
        // self-revoke).
        $pinned = '2026-05-29 04:00:00';
        $this->repo->stamp($this->uid, $pinned);

        self::assertSame($pinned, $this->repo->changedAt($this->uid));
    }

    public function test_second_stamp_updates_existing_row(): void
    {
        $first  = '2026-05-29 04:00:00';
        $second = '2026-05-29 05:00:00';

        $this->repo->stamp($this->uid, $first);
        self::assertSame($first, $this->repo->changedAt($this->uid));

        $this->repo->stamp($this->uid, $second);
        self::assertSame($second, $this->repo->changedAt($this->uid));

        // Confirm there's still only one row per user (PK = user_id).
        $rowCount = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_password_changes WHERE user_id = :uid',
            ['uid' => $this->uid],
        );
        self::assertSame(1, $rowCount);
    }
}
