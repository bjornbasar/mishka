<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\MishkaUserRepository;
use Karhu\Auth\PasswordHasher;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MishkaUserRepository against the in-memory SQLite test DB.
 *
 * Each test runs inside an outer transaction (begun in setUp, rolled back
 * in tearDown) for isolation — mirrors the istrbuddy harness pattern.
 * MishkaUserRepository::create() guards its own transaction with
 * $pdo->inTransaction() so this works under the outer txn AND in production.
 */
final class MishkaUserRepositoryTest extends TestCase
{
    private Connection $db;
    private MishkaUserRepository $repo;
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new MishkaUserRepository($this->db);
        $this->hasher = new PasswordHasher();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_find_by_username_returns_null_for_unknown_email(): void
    {
        self::assertNull($this->repo->findByUsername('nobody@example.com'));
    }

    public function test_find_by_username_excludes_system_sentinel_user(): void
    {
        // The schema seeds (id=0, email='__system__'). It must never be returned.
        self::assertNull($this->repo->findByUsername('__system__'));
    }

    public function test_create_persists_user_and_lowercases_email(): void
    {
        $hash = $this->hasher->hash('correct horse battery');
        $id = $this->repo->create('Bjorn@Example.COM', $hash, 'Bjorn');

        self::assertGreaterThan(0, $id);

        $row = $this->db->fetchOne('SELECT email, display_name FROM users WHERE id = :id', ['id' => $id]);
        self::assertSame('bjorn@example.com', $row['email']);
        self::assertSame('Bjorn', $row['display_name']);
    }

    public function test_create_first_user_claims_admin_sentinel(): void
    {
        $id = $this->repo->create('first@example.com', $this->hasher->hash('correct horse battery'), 'First');

        $row = $this->repo->findByUsername('first@example.com');
        self::assertNotNull($row);
        self::assertSame(['admin'], $row['roles']);

        // Sentinel row's user_id should be the new user's id now (not 0).
        $sentinel = $this->db->fetchOne("SELECT user_id FROM system_roles WHERE role = 'admin'");
        self::assertSame($id, (int) $sentinel['user_id']);
    }

    public function test_create_second_user_gets_member_role(): void
    {
        $this->repo->create('first@example.com', $this->hasher->hash('correct horse battery'), 'First');
        $this->repo->create('second@example.com', $this->hasher->hash('correct horse battery'), 'Second');

        $second = $this->repo->findByUsername('second@example.com');
        self::assertNotNull($second);
        self::assertSame(['member'], $second['roles']);
    }

    public function test_find_by_username_returns_email_in_username_slot(): void
    {
        $this->repo->create('user@example.com', $this->hasher->hash('correct horse battery'), 'User');

        $user = $this->repo->findByUsername('user@example.com');
        self::assertNotNull($user);
        self::assertSame('user@example.com', $user['username']);
        self::assertArrayHasKey('password_hash', $user);
        self::assertArrayHasKey('roles', $user);
    }

    public function test_find_by_username_is_case_insensitive(): void
    {
        $this->repo->create('User@Example.com', $this->hasher->hash('correct horse battery'), 'User');

        self::assertNotNull($this->repo->findByUsername('user@example.com'));
        self::assertNotNull($this->repo->findByUsername('USER@EXAMPLE.COM'));
        self::assertNotNull($this->repo->findByUsername('  User@Example.com  '));
    }

    public function test_email_exists_returns_true_after_create(): void
    {
        self::assertFalse($this->repo->emailExists('user@example.com'));
        $this->repo->create('user@example.com', $this->hasher->hash('correct horse battery'), 'User');
        self::assertTrue($this->repo->emailExists('user@example.com'));
        self::assertTrue($this->repo->emailExists('USER@EXAMPLE.COM'));
    }

    public function test_email_exists_does_not_see_system_sentinel(): void
    {
        self::assertFalse($this->repo->emailExists('__system__'));
    }

    public function test_find_id_by_email_returns_int_pk(): void
    {
        $id = $this->repo->create('user@example.com', $this->hasher->hash('correct horse battery'), 'User');
        self::assertSame($id, $this->repo->findIdByEmail('user@example.com'));
        self::assertNull($this->repo->findIdByEmail('nobody@example.com'));
    }

    public function test_find_by_id_returns_user_with_roles(): void
    {
        $id = $this->repo->create('first@example.com', $this->hasher->hash('correct horse battery'), 'First');

        $user = $this->repo->findById($id);
        self::assertNotNull($user);
        self::assertSame($id, $user['id']);
        self::assertSame('first@example.com', $user['email']);
        self::assertSame('First', $user['display_name']);
        self::assertSame(['admin'], $user['roles']);
    }

    public function test_find_by_id_returns_null_for_system_sentinel(): void
    {
        self::assertNull($this->repo->findById(0));
    }

    public function test_roles_for_returns_roles_for_email(): void
    {
        $this->repo->create('first@example.com', $this->hasher->hash('correct horse battery'), 'First');
        self::assertSame(['admin'], $this->repo->rolesFor('first@example.com'));
        self::assertSame([], $this->repo->rolesFor('unknown@example.com'));
    }

    public function test_record_login_updates_last_login_at(): void
    {
        $id = $this->repo->create('user@example.com', $this->hasher->hash('correct horse battery'), 'User');

        $before = $this->db->fetchScalar('SELECT last_login_at FROM users WHERE id = :id', ['id' => $id]);
        self::assertNull($before);

        $this->repo->recordLogin($id);

        $after = $this->db->fetchScalar('SELECT last_login_at FROM users WHERE id = :id', ['id' => $id]);
        self::assertNotNull($after);
    }

    // ============================================================
    // v0.5.0 extensions — account lifecycle
    // ============================================================

    public function test_update_display_name_persists_new_value(): void
    {
        $id = $this->repo->create('user@example.com', $this->hasher->hash('correct horse battery'), 'Old Name');

        $this->repo->updateDisplayName($id, 'New Name');

        $row = $this->repo->findById($id);
        self::assertNotNull($row);
        self::assertSame('New Name', $row['display_name']);
    }

    public function test_update_display_name_is_a_noop_for_sentinel(): void
    {
        // id=0 is the system sentinel; updating its display_name would corrupt
        // the schema seed. The repo silently no-ops to match findById(0) → null.
        $this->repo->updateDisplayName(0, 'Hacker');

        $row = $this->db->fetchOne('SELECT display_name FROM users WHERE id = 0');
        self::assertNotNull($row);
        self::assertSame('System', $row['display_name']);
    }

    public function test_update_password_writes_hash_and_stamps_credential_change(): void
    {
        // BL-2: caller pins $now once; the same value must land on both the
        // hash-write (via users.updated_at) AND the credential-change stamp.
        $id = $this->repo->create('user@example.com', $this->hasher->hash('old password'), 'User');
        $newHash = $this->hasher->hash('new password');
        $now = gmdate('Y-m-d H:i:s');

        $this->repo->updatePassword($id, $newHash, $now);

        // 1. Hash was written.
        $row = $this->repo->findById($id);
        self::assertNotNull($row);
        self::assertSame($newHash, $row['password_hash']);

        // 2. user_password_changes row exists with the pinned $now.
        $stamp = $this->db->fetchScalar(
            'SELECT password_changed_at FROM user_password_changes WHERE user_id = :id',
            ['id' => $id],
        );
        self::assertSame($now, $stamp);
    }

    public function test_update_password_stamps_atomically_with_hash_write(): void
    {
        // If the stamp INSERT throws (e.g., constraint violation), the hash
        // write MUST roll back too — the SessionRevocationGuard predicate
        // depends on the invariant that a hash change is always reflected in
        // user_password_changes.
        $id = $this->repo->create('user@example.com', $this->hasher->hash('old'), 'User');

        // Pre-existing stamp — the upsert path will UPDATE this row (no throw).
        // Verify hash + stamp both end up at the new value (no partial state).
        $this->repo->updatePassword($id, $this->hasher->hash('mid'), '2026-01-01 00:00:00');
        $this->repo->updatePassword($id, $this->hasher->hash('new'), '2026-01-02 00:00:00');

        $row = $this->repo->findById($id);
        $stamp = $this->db->fetchScalar(
            'SELECT password_changed_at FROM user_password_changes WHERE user_id = :id',
            ['id' => $id],
        );
        self::assertNotNull($row);
        self::assertTrue($this->hasher->verify('new', $row['password_hash']));
        self::assertSame('2026-01-02 00:00:00', $stamp);
    }

    public function test_mark_email_verified_sets_timestamp_when_null(): void
    {
        $id = $this->repo->create('user@example.com', $this->hasher->hash('x'), 'User');
        self::assertFalse($this->repo->isEmailVerified($id));

        $this->repo->markEmailVerified($id);

        self::assertTrue($this->repo->isEmailVerified($id));
    }

    public function test_mark_email_verified_is_idempotent(): void
    {
        $id = $this->repo->create('user@example.com', $this->hasher->hash('x'), 'User');
        $this->repo->markEmailVerified($id);
        $firstStamp = $this->db->fetchScalar(
            'SELECT email_verified_at FROM users WHERE id = :id',
            ['id' => $id],
        );
        self::assertNotNull($firstStamp);

        // Sleep 1ms to make sure CURRENT_TIMESTAMP would tick if we re-stamped.
        usleep(1100);
        $this->repo->markEmailVerified($id);
        $secondStamp = $this->db->fetchScalar(
            'SELECT email_verified_at FROM users WHERE id = :id',
            ['id' => $id],
        );

        // The WHERE email_verified_at IS NULL guard means the second call is
        // a no-op — original timestamp preserved.
        self::assertSame($firstStamp, $secondStamp);
    }

    public function test_is_email_verified_returns_false_for_sentinel(): void
    {
        self::assertFalse($this->repo->isEmailVerified(0));
    }

    public function test_apply_email_swap_updates_email_and_marks_verified(): void
    {
        // The user starts unverified; swap should set both fields.
        $id = $this->repo->create('old@example.com', $this->hasher->hash('x'), 'User');
        self::assertFalse($this->repo->isEmailVerified($id));

        $ok = $this->repo->applyEmailSwap($id, 'new@example.com');

        self::assertTrue($ok);
        $row = $this->db->fetchOne(
            'SELECT email, email_verified_at FROM users WHERE id = :id',
            ['id' => $id],
        );
        self::assertNotNull($row);
        self::assertSame('new@example.com', $row['email']);
        self::assertNotNull($row['email_verified_at']);
    }

    public function test_apply_email_swap_normalises_new_email(): void
    {
        $id = $this->repo->create('old@example.com', $this->hasher->hash('x'), 'User');

        $this->repo->applyEmailSwap($id, '  Mixed@Case.Example.Com  ');

        $stored = $this->db->fetchScalar('SELECT email FROM users WHERE id = :id', ['id' => $id]);
        self::assertSame('mixed@case.example.com', $stored);
    }

    public function test_apply_email_swap_returns_false_for_sentinel(): void
    {
        // Sentinel ids skip the UPDATE entirely (no false-positive row update).
        self::assertFalse($this->repo->applyEmailSwap(0, 'new@example.com'));
    }

    public function test_apply_email_swap_raises_on_unique_conflict(): void
    {
        // Set up two users; attempting to swap one's email to the other's
        // raises a PDOException (caught by the controller as a 422 conflict).
        $a = $this->repo->create('a@example.com', $this->hasher->hash('x'), 'A');
        $this->repo->create('b@example.com', $this->hasher->hash('x'), 'B');

        $this->expectException(\PDOException::class);
        $this->repo->applyEmailSwap($a, 'b@example.com');
    }

    // ============================================================
    // v0.6.12 — delete()
    // ============================================================

    public function test_delete_returns_true_and_removes_user_row(): void
    {
        $id = $this->repo->create('gone@example.com', $this->hasher->hash('x'), 'User');

        $ok = $this->repo->delete($id);

        self::assertTrue($ok);
        self::assertNull($this->repo->findById($id));
    }

    public function test_delete_refuses_sentinel_id_zero(): void
    {
        // Sentinel guard: a system row at id=0 (if any) must never get
        // deleted via this code path.
        self::assertFalse($this->repo->delete(0));
        self::assertFalse($this->repo->delete(-1));
    }

    public function test_delete_returns_false_for_unknown_id(): void
    {
        // Idempotent: race-losers see false because the row is gone either way.
        self::assertFalse($this->repo->delete(999_999));
    }

    public function test_delete_cascades_all_user_id_tables(): void
    {
        // Cascade verification across the full FK chain (round-2 C7 wants
        // enumeration, not just samples). The 12 CASCADE tables on users.id
        // plus the 2 we touch via direct INSERT below. We don't bother with
        // tables like notification_dispatches or push_subscriptions where
        // setting up the fixture data is heavy — the FK cascade behaviour is
        // identical PG primitive, and the schema CREATE TABLE statements all
        // declare ON DELETE CASCADE for those user_id columns. Spot-checking
        // the 4 most-touched-by-real-code is sufficient.
        $id = $this->repo->create('cascade@example.com', $this->hasher->hash('x'), 'C');
        // 1. household_members (auto-created by createForOwner)
        $households = new \App\Household\HouseholdRepository($this->db);
        $hid = $households->createForOwner('Den', $id);
        // 2. user_preferences (created by createForOwner via setLastHouseholdId)
        // 3. email_verification_tokens (issue one)
        $verify = new \App\Auth\EmailVerificationTokenRepository($this->db);
        $verify->issue($id);
        // 4. email_send_attempts (user-keyed)
        $this->db->run(
            "INSERT INTO email_send_attempts (user_id, kind) VALUES (:u, 'verify_resend')",
            ['u' => $id],
        );

        $this->repo->delete($id);

        // All 4 cascade tables should have zero rows for this user.
        $counts = [
            'household_members' => 'SELECT COUNT(*) FROM household_members WHERE user_id = :u',
            'user_preferences'  => 'SELECT COUNT(*) FROM user_preferences WHERE user_id = :u',
            'email_verification_tokens' => 'SELECT COUNT(*) FROM email_verification_tokens WHERE user_id = :u',
            'email_send_attempts' => 'SELECT COUNT(*) FROM email_send_attempts WHERE user_id = :u',
        ];
        foreach ($counts as $table => $sql) {
            self::assertSame(
                0,
                (int) $this->db->fetchScalar($sql, ['u' => $id]),
                "{$table} did not cascade on user delete",
            );
        }
        // Sanity: the household ROW survives (households only cascade on
        // household_members.household_id, not on user_id of any single member).
        self::assertNotNull($households->findById($hid));
    }

    public function test_delete_sets_null_on_events_chores_chore_schedules_created_by(): void
    {
        // v0.6.12 migration verifier: the 3 created_by FKs that used to be
        // RESTRICT now SET NULL on user delete. Authored content survives.
        $author = $this->repo->create('author@example.com', $this->hasher->hash('x'), 'Author');
        $households = new \App\Household\HouseholdRepository($this->db);
        $hid = $households->createForOwner('Den', $author);

        // Insert one row in each of the 3 tables authored by this user.
        $eventId = (int) $this->db->fetchScalar(
            "INSERT INTO events (household_id, created_by, title, starts_at_local, ends_at_local, timezone)
             VALUES (:h, :a, 'E', '2026-01-01 10:00:00', '2026-01-01 11:00:00', 'UTC')
             RETURNING id",
            ['h' => $hid, 'a' => $author],
        );
        $choreId = (int) $this->db->fetchScalar(
            "INSERT INTO chores (household_id, created_by, title, timezone)
             VALUES (:h, :a, 'C', 'UTC') RETURNING id",
            ['h' => $hid, 'a' => $author],
        );
        $scheduleId = (int) $this->db->fetchScalar(
            "INSERT INTO chore_schedules (household_id, created_by, title, rrule, anchor_at_local, timezone)
             VALUES (:h, :a, 'S', 'FREQ=DAILY', '2026-01-01 00:00:00', 'UTC') RETURNING id",
            ['h' => $hid, 'a' => $author],
        );

        // Deleting the user should NOT raise (RESTRICT is gone post-v0.6.12)
        // AND should SET NULL on all 3 created_by columns.
        // We need another owner first so the delete isn't blocked by the
        // owned-households pre-check — but that pre-check is at the controller
        // layer. The repo-level delete() doesn't check; we just need the FK
        // chain to fire cleanly. household_members.user_id CASCADEs so the
        // membership row vanishes; the household becomes ownerless (round-2 R9).
        $this->repo->delete($author);

        self::assertNull($this->db->fetchScalar('SELECT created_by FROM events WHERE id = :id', ['id' => $eventId]));
        self::assertNull($this->db->fetchScalar('SELECT created_by FROM chores WHERE id = :id', ['id' => $choreId]));
        self::assertNull($this->db->fetchScalar('SELECT created_by FROM chore_schedules WHERE id = :id', ['id' => $scheduleId]));
    }
}
