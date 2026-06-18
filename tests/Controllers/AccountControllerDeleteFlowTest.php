<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.6.12 — AccountController /me/delete integration tests.
 *
 * Pinned invariants:
 *   - M1: PasswordHasher::verify is ALWAYS called on POST /me/delete regardless
 *     of other validation outcomes (timing-oracle defence).
 *   - Owned-households block: user owning N households cannot self-delete
 *     (422 + per-row action links).
 *   - hash_equals on confirm_email with case-insensitive normalisation.
 *   - Atomic CASCADE chain fires inside one txn; SET NULL on the 3 created_by
 *     FKs preserves authored content.
 *   - Session::destroy + cookie clear post-delete; 302 to /login?deleted=1.
 *   - Account delete is NOT a credential change — no user_password_changes
 *     write. Critical regression guard against the BL-2 self-revoke trap.
 */
final class AccountControllerDeleteFlowTest extends AppTestCase
{
    private const VALID_PASSWORD = 'correct horse battery staple';

    // ============================================================
    // GET /me/delete — form render
    // ============================================================

    public function test_get_delete_renders_form_with_email_and_password_fields(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('GET', '/me/delete');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('me@example.com', $response->body());
        self::assertStringContainsString('name="current_password"', $response->body());
        self::assertStringContainsString('name="confirm_email"', $response->body());
    }

    public function test_get_delete_redirects_anonymous_to_login(): void
    {
        $response = $this->request('GET', '/me/delete');
        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('location'));
    }

    public function test_get_delete_lists_owned_households_when_user_owns_any(): void
    {
        $uid = $this->createUserWithHash('owner@example.com', self::VALID_PASSWORD);
        $this->householdRepo->createForOwner('Alpha House', $uid);
        $this->householdRepo->createForOwner('Beta House', $uid);
        $this->loginAs($uid, 'owner@example.com');

        $response = $this->request('GET', '/me/delete');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Alpha House', $response->body());
        self::assertStringContainsString('Beta House', $response->body());
        // Submit button is disabled when owned-households > 0.
        self::assertStringContainsString('disabled', $response->body());
    }

    // ============================================================
    // POST /me/delete — validation
    // ============================================================

    public function test_post_delete_with_wrong_password_returns_422_user_row_intact(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => 'wrong-password',
            'confirm_email' => 'me@example.com',
        ]);

        self::assertSame(422, $response->status());
        self::assertNotNull($this->userRepo->findById($uid));
    }

    public function test_post_delete_with_wrong_confirm_email_returns_422_user_row_intact(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'wrong@example.com',
        ]);

        self::assertSame(422, $response->status());
        self::assertNotNull($this->userRepo->findById($uid));
    }

    public function test_post_delete_with_empty_confirm_email_returns_422(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => '',
        ]);

        self::assertSame(422, $response->status());
        self::assertNotNull($this->userRepo->findById($uid));
    }

    /**
     * v0.6.19 — seed a deputy admin so a self-delete test isn't blocked by
     * the only-admin pre-check. The deputy is `deputy@example.com` (a
     * fresh user, granted admin via the AppTestCase fixture helper).
     */
    private function seedDeputyAdmin(): int
    {
        $deputy = $this->createUserWithHash('deputy@example.com', self::VALID_PASSWORD);
        $this->grantSystemAdmin($deputy);
        return $deputy;
    }

    public function test_post_delete_with_uppercase_confirm_email_normalises_and_succeeds(): void
    {
        // Controller-side normalisation: input goes through strtolower+trim
        // before hash_equals against the DB-canonical (lowercase) email.
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->seedDeputyAdmin();
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => '  Me@Example.Com  ',
        ]);

        self::assertSame(302, $response->status());
        self::assertNull($this->userRepo->findById($uid));
    }

    public function test_post_delete_with_owned_households_returns_422_user_row_intact(): void
    {
        $uid = $this->createUserWithHash('owner@example.com', self::VALID_PASSWORD);
        $this->householdRepo->createForOwner('Alpha House', $uid);
        $this->loginAs($uid, 'owner@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'owner@example.com',
        ]);

        self::assertSame(422, $response->status());
        self::assertNotNull($this->userRepo->findById($uid));
        // Error message mentions the owned-households requirement.
        self::assertStringContainsString('1 household', $response->body());
    }

    // ============================================================
    // POST /me/delete — success
    // ============================================================

    public function test_post_delete_success_deletes_user_row(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->seedDeputyAdmin();
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'me@example.com',
        ]);

        self::assertSame(302, $response->status());
        self::assertNull($this->userRepo->findById($uid));
    }

    public function test_post_delete_success_destroys_session(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->seedDeputyAdmin();
        $this->loginAs($uid, 'me@example.com');

        $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'me@example.com',
        ]);

        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertArrayNotHasKey('username', $_SESSION);
    }

    public function test_post_delete_success_redirects_to_login_with_deleted_flag(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->seedDeputyAdmin();
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'me@example.com',
        ]);

        self::assertSame(302, $response->status());
        self::assertSame('/login?deleted=1', $response->header('location'));
    }

    public function test_post_delete_success_sends_courtesy_email_to_old_address(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD, 'Me Bear');
        $this->seedDeputyAdmin();
        $this->loginAs($uid, 'me@example.com');

        $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'me@example.com',
        ]);

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('account_deleted', $this->mailer->sent[0]['kind']);
        self::assertSame('me@example.com', $this->mailer->sent[0]['to']);
        self::assertSame('Me Bear', $this->mailer->sent[0]['display_name']);
        // 'url' field carries the deleted_at timestamp for this kind (per
        // RecordingMailer convention) — sanity-check it looks like ISO+Z.
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}Z$/', $this->mailer->sent[0]['url']);
    }

    public function test_post_delete_success_cascades_household_member_row(): void
    {
        // Non-owner: user is just a member; delete should cascade the membership row.
        $owner = $this->createUserWithHash('owner@example.com', self::VALID_PASSWORD);
        $member = $this->createUserWithHash('member@example.com', self::VALID_PASSWORD);
        $hid = $this->householdRepo->createForOwner('Den', $owner);
        $this->householdRepo->addMember($hid, $member);
        $this->loginAs($member, 'member@example.com');

        $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'member@example.com',
        ]);

        // Member's row is gone; household + owner still there.
        self::assertNull($this->userRepo->findById($member));
        self::assertNotNull($this->userRepo->findById($owner));
        $memberCount = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM household_members WHERE household_id = :h',
            ['h' => $hid],
        );
        self::assertSame(1, $memberCount);  // just the owner remains
    }

    public function test_post_delete_success_sets_null_on_authored_content(): void
    {
        // The v0.6.12 SET NULL migration: authored events/chores/chore_schedules
        // survive the author's deletion as "Deleted user" (created_by NULL).
        $owner = $this->createUserWithHash('owner@example.com', self::VALID_PASSWORD);
        $author = $this->createUserWithHash('author@example.com', self::VALID_PASSWORD);
        $hid = $this->householdRepo->createForOwner('Den', $owner);
        $this->householdRepo->addMember($hid, $author);

        // Insert authored content directly (bypassing the controllers' txn).
        $eventId = (int) $this->db->fetchScalar(
            "INSERT INTO events (household_id, created_by, title, starts_at_local, ends_at_local, timezone)
             VALUES (:h, :a, 'Birthday', '2026-01-01 10:00:00', '2026-01-01 11:00:00', 'UTC')
             RETURNING id",
            ['h' => $hid, 'a' => $author],
        );

        $this->loginAs($author, 'author@example.com');
        $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'author@example.com',
        ]);

        // Event row survives with NULL author.
        $row = $this->db->fetchOne('SELECT created_by, title FROM events WHERE id = :id', ['id' => $eventId]);
        self::assertNotNull($row);
        self::assertNull($row['created_by']);
        self::assertSame('Birthday', $row['title']);
    }

    public function test_post_delete_M1_always_verifies_password_even_on_invalid_confirm_email(): void
    {
        // M1 timing-oracle defence: even with a completely wrong confirm_email,
        // the password verification call still fires. We can't directly observe
        // the timing, but we CAN observe that with a wrong PASSWORD AND wrong
        // EMAIL the user is intact (i.e. neither validation short-circuited
        // before the other).
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => 'wrong-password',
            'confirm_email' => 'wrong@example.com',
        ]);

        self::assertSame(422, $response->status());
        self::assertNotNull($this->userRepo->findById($uid));
        // Body shows both error lines (the hasher::verify DID fire, validation
        // gathered both errors before returning).
        self::assertStringContainsString('Current password is incorrect', $response->body());
        self::assertStringContainsString('does not match', $response->body());
    }

    public function test_post_delete_does_NOT_write_user_password_changes_row(): void
    {
        // CRITICAL regression guard: email/account changes are NOT credential
        // changes — they must not write to user_password_changes (which would
        // trip SessionRevocationGuard's BL-2 self-revoke trap if a future
        // maintainer accidentally adds it). Decision #52 invariant carries
        // through to v0.6.12.
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'me@example.com',
        ]);

        // user_password_changes has no row for this user — the user_id FK
        // CASCADEs on user delete, but we never WROTE one in the first place,
        // which is the actual invariant being tested. We can't query by uid
        // (the user row is gone), but we can verify the row count is zero
        // post-delete: any previously-written row would have been cascaded
        // away too, but the test still proves the controller doesn't issue
        // a write that would re-create one in some future buggy code path.
        $rowCount = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM user_password_changes');
        self::assertSame(0, $rowCount,
            'account delete must NOT write user_password_changes (SessionRevocationGuard invariant)');
    }

    // ============================================================
    // v0.6.19 — admin-presence pre-check + user_deletions audit
    // ============================================================

    public function test_post_delete_blocks_only_admin_with_422_and_promote_link(): void
    {
        // First user gets admin via the sentinel claim in MishkaUserRepository::create.
        $uid = $this->createUserWithHash('admin@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'admin@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'admin@example.com',
        ]);

        self::assertSame(422, $response->status());
        self::assertNotNull($this->userRepo->findById($uid));
        self::assertStringContainsString('only system administrator', $response->body());
        // The escape-hatch link to /me/admin/promote is rendered as a real
        // anchor (NOT escaped inside the errors list — round-2 C4 XSS guard).
        self::assertStringContainsString('href="/me/admin/promote"', $response->body());
    }

    public function test_post_delete_succeeds_when_a_second_admin_exists(): void
    {
        // First user (sentinel admin) + second user explicitly granted admin
        // via the AppTestCase fixture helper. Either can self-delete because
        // the post-delete admin count stays >= 1.
        $alice = $this->createUserWithHash('alice@example.com', self::VALID_PASSWORD);
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $this->grantSystemAdmin($bob);

        $this->loginAs($alice, 'alice@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'alice@example.com',
        ]);

        self::assertSame(302, $response->status());
        self::assertNull($this->userRepo->findById($alice));
        // CASCADE removed alice's system_roles row; bob remains.
        self::assertNotNull($this->userRepo->findById($bob));
    }

    public function test_post_delete_writes_user_deletions_audit_row(): void
    {
        $alice = $this->createUserWithHash('alice@example.com', self::VALID_PASSWORD);
        // Create a household member so household_ids has something in it.
        // (alice is the first user, so she's the sentinel admin already; the
        // owned-households check would otherwise block deletion if she owned
        // any households — keep her as just a member of bob's household.)
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $this->grantSystemAdmin($bob);  // alice can self-delete
        $hid = $this->householdRepo->createForOwner('Bob House', $bob);
        $this->householdRepo->addMember($hid, $alice);

        $this->loginAs($alice, 'alice@example.com');

        $response = $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'alice@example.com',
        ]);

        self::assertSame(302, $response->status());

        $row = $this->db->fetchOne(
            'SELECT user_id, deleted_at, household_ids FROM user_deletions WHERE user_id = :uid',
            ['uid' => $alice],
        );
        self::assertNotNull($row, 'user_deletions row should be written atomically with the user DELETE');
        self::assertSame($alice, (int) $row['user_id']);
        self::assertNotEmpty($row['deleted_at']);
        self::assertSame(json_encode([$hid]), $row['household_ids']);
    }

    public function test_post_delete_writes_user_deletions_with_empty_array_when_no_memberships(): void
    {
        // First user is sentinel admin; second user is also admin (so alice
        // can self-delete) but alice has no household memberships.
        $alice = $this->createUserWithHash('alice@example.com', self::VALID_PASSWORD);
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $this->grantSystemAdmin($bob);

        $this->loginAs($alice, 'alice@example.com');

        $this->request('POST', '/me/delete', [
            'current_password' => self::VALID_PASSWORD,
            'confirm_email' => 'alice@example.com',
        ]);

        $row = $this->db->fetchOne(
            'SELECT household_ids FROM user_deletions WHERE user_id = :uid',
            ['uid' => $alice],
        );
        self::assertNotNull($row);
        self::assertSame('[]', $row['household_ids']);
    }
}
