<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * Integration tests for the registration / login / logout flow.
 *
 * Hits the controller through the App's middleware pipeline. Session and
 * CSRF middleware are intentionally skipped in the test harness so we can
 * manipulate $_SESSION directly and post forms without juggling a token.
 */
final class AuthControllerTest extends AppTestCase
{
    private const VALID_PASSWORD = 'correct horse battery staple';

    public function test_get_register_renders_form(): void
    {
        $response = $this->request('GET', '/register');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Create your account', $response->body());
        self::assertStringContainsString('name="email"', $response->body());
        self::assertStringContainsString('name="password_confirm"', $response->body());
    }

    public function test_register_with_valid_data_creates_user_and_logs_in(): void
    {
        $response = $this->request('POST', '/register', [
            'email' => 'first@example.com',
            'display_name' => 'First',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);

        // v0.2: post-register redirects to /household/setup (not /).
        // New users have no household yet — onboarding has to complete before
        // anything else is useful.
        self::assertSame(303, $response->status());
        self::assertSame('/household/setup', $response->header('location'));
        self::assertSame('first@example.com', $_SESSION['username'] ?? null);
        self::assertGreaterThan(0, $_SESSION['user_id'] ?? 0);
        self::assertArrayNotHasKey('active_household_id', $_SESSION);  // no household yet
        self::assertNotNull($this->userRepo->findIdByEmail('first@example.com'));
    }

    public function test_register_first_user_gets_admin_role(): void
    {
        $this->request('POST', '/register', [
            'email' => 'first@example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);

        self::assertSame(['admin'], $_SESSION['roles']);
    }

    public function test_register_subsequent_user_gets_member_role(): void
    {
        // First user — claims admin sentinel.
        $this->request('POST', '/register', [
            'email' => 'first@example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);
        $_SESSION = [];

        // Second user — gets member.
        $this->request('POST', '/register', [
            'email' => 'second@example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);

        self::assertSame(['member'], $_SESSION['roles']);
    }

    public function test_register_lowercases_email_before_storing(): void
    {
        $this->request('POST', '/register', [
            'email' => 'Mixed@Case.COM',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);

        self::assertSame('mixed@case.com', $_SESSION['username']);
        self::assertNotNull($this->userRepo->findIdByEmail('mixed@case.com'));
    }

    public function test_register_rejects_invalid_email_format(): void
    {
        $response = $this->request('POST', '/register', [
            'email' => 'not-an-email',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('valid email', strtolower($response->body()));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_register_rejects_password_under_12_chars(): void
    {
        $response = $this->request('POST', '/register', [
            'email' => 'shorty@example.com',
            'password' => 'too-short',
            'password_confirm' => 'too-short',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('12', $response->body());
    }

    public function test_register_rejects_password_over_128_chars(): void
    {
        $long = str_repeat('x', 129);
        $response = $this->request('POST', '/register', [
            'email' => 'longy@example.com',
            'password' => $long,
            'password_confirm' => $long,
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('128', $response->body());
    }

    public function test_register_rejects_password_mismatch(): void
    {
        $response = $this->request('POST', '/register', [
            'email' => 'user@example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => 'a-different-but-also-long-password',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('match', strtolower($response->body()));
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->request('POST', '/register', [
            'email' => 'dup@example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);
        $_SESSION = [];

        $response = $this->request('POST', '/register', [
            'email' => 'dup@example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('already registered', strtolower($response->body()));
    }

    public function test_register_does_not_echo_password_in_old_input(): void
    {
        $response = $this->request('POST', '/register', [
            'email' => 'bad', // triggers validation failure so the form re-renders
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);

        self::assertStringNotContainsString(self::VALID_PASSWORD, $response->body());
    }

    public function test_get_login_renders_form(): void
    {
        $response = $this->request('GET', '/login');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Sign in', $response->body());
        self::assertStringContainsString('name="email"', $response->body());
    }

    public function test_login_with_valid_credentials_redirects_home_and_sets_session(): void
    {
        $hash = $this->hasher->hash(self::VALID_PASSWORD);
        $id = $this->userRepo->create('user@example.com', $hash, 'User');

        $response = $this->request('POST', '/login', [
            'email' => 'user@example.com',
            'password' => self::VALID_PASSWORD,
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/', $response->header('location'));
        self::assertSame($id, $_SESSION['user_id']);
        self::assertSame('user@example.com', $_SESSION['username']);
    }

    public function test_login_records_last_login_at(): void
    {
        $hash = $this->hasher->hash(self::VALID_PASSWORD);
        $id = $this->userRepo->create('user@example.com', $hash, 'User');

        $before = $this->db->fetchScalar('SELECT last_login_at FROM users WHERE id = :id', ['id' => $id]);
        self::assertNull($before);

        $this->request('POST', '/login', [
            'email' => 'user@example.com',
            'password' => self::VALID_PASSWORD,
        ]);

        $after = $this->db->fetchScalar('SELECT last_login_at FROM users WHERE id = :id', ['id' => $id]);
        self::assertNotNull($after);
    }

    public function test_login_with_wrong_password_returns_401_generic_error(): void
    {
        $hash = $this->hasher->hash(self::VALID_PASSWORD);
        $this->userRepo->create('user@example.com', $hash, 'User');

        $response = $this->request('POST', '/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password-but-long-enough',
        ]);

        self::assertSame(401, $response->status());
        self::assertStringContainsString('Invalid email or password', $response->body());
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_login_with_unknown_email_returns_identical_error(): void
    {
        $response = $this->request('POST', '/login', [
            'email' => 'nobody@example.com',
            'password' => self::VALID_PASSWORD,
        ]);

        self::assertSame(401, $response->status());
        self::assertStringContainsString('Invalid email or password', $response->body());
    }

    public function test_logout_destroys_session(): void
    {
        $this->loginAs(42, 'user@example.com', ['member']);
        self::assertArrayHasKey('user_id', $_SESSION);

        $response = $this->request('POST', '/logout');

        self::assertSame(303, $response->status());
        self::assertSame('/login', $response->header('location'));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_authenticated_user_visiting_register_is_redirected_home(): void
    {
        $this->loginAs(42, 'user@example.com', ['member']);

        $response = $this->request('GET', '/register');

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->header('location'));
    }

    public function test_authenticated_user_visiting_login_is_redirected_home(): void
    {
        $this->loginAs(42, 'user@example.com', ['member']);

        $response = $this->request('GET', '/login');

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->header('location'));
    }

    // v0.2: active household restoration on login.

    public function test_login_with_no_memberships_does_not_set_active_household(): void
    {
        $hash = $this->hasher->hash(self::VALID_PASSWORD);
        $this->userRepo->create('lonely@example.com', $hash, 'L');

        $this->request('POST', '/login', [
            'email' => 'lonely@example.com',
            'password' => self::VALID_PASSWORD,
        ]);

        self::assertArrayNotHasKey('active_household_id', $_SESSION);
        self::assertArrayNotHasKey('active_household_role', $_SESSION);
    }

    public function test_login_restores_last_household_id_from_user_preferences(): void
    {
        // Arrange: user has TWO households; their last-selected was the SECOND.
        $hash = $this->hasher->hash(self::VALID_PASSWORD);
        $id = $this->userRepo->create('multi@example.com', $hash, 'M');

        $hid1 = $this->householdRepo->createForOwner('First', $id);
        $hid2 = $this->householdRepo->createForOwner('Second', $id);
        $this->prefsRepo->setLastHouseholdId($id, $hid2);

        // Act
        $this->request('POST', '/login', [
            'email' => 'multi@example.com',
            'password' => self::VALID_PASSWORD,
        ]);

        // Assert: their preferred household is the active one
        self::assertSame($hid2, $_SESSION['active_household_id']);
        self::assertSame('owner', $_SESSION['active_household_role']);
    }

    public function test_login_falls_back_to_first_membership_when_no_preference(): void
    {
        $hash = $this->hasher->hash(self::VALID_PASSWORD);
        $id = $this->userRepo->create('first@example.com', $hash, 'F');
        $hid = $this->householdRepo->createForOwner('Only Den', $id);

        $this->request('POST', '/login', [
            'email' => 'first@example.com',
            'password' => self::VALID_PASSWORD,
        ]);

        self::assertSame($hid, $_SESSION['active_household_id']);
        self::assertSame('owner', $_SESSION['active_household_role']);
    }

    public function test_login_ignores_preference_when_user_no_longer_member(): void
    {
        // Edge case: user_preferences.last_household_id points at a household
        // they've since been kicked from. Don't restore it — fall back to first
        // current membership.
        $hash = $this->hasher->hash(self::VALID_PASSWORD);
        $id = $this->userRepo->create('kicked@example.com', $hash, 'K');

        $kickedFromId = $this->householdRepo->createForOwner('Old', $id);
        $stillInId = $this->householdRepo->createForOwner('Current', $id);
        $this->prefsRepo->setLastHouseholdId($id, $kickedFromId);

        // Remove them from the preferred household via direct DB
        // (can't use removeMember; it blocks removing owners).
        $this->db->run(
            'DELETE FROM household_members WHERE household_id = :hid AND user_id = :uid',
            ['hid' => $kickedFromId, 'uid' => $id],
        );

        $this->request('POST', '/login', [
            'email' => 'kicked@example.com',
            'password' => self::VALID_PASSWORD,
        ]);

        self::assertSame($stillInId, $_SESSION['active_household_id']);
    }

    // ============================================================
    // v0.5.0 — register-hook fires the verification email
    // ============================================================

    public function test_register_emails_a_verification_link_with_app_url_host(): void
    {
        $this->request('POST', '/register', [
            'email' => 'new@example.com',
            'display_name' => 'New User',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);

        self::assertCount(1, $this->mailer->sent);
        $sent = $this->mailer->sent[0];
        self::assertSame('verification', $sent['kind']);
        self::assertSame('new@example.com', $sent['to']);
        // B1: URL host comes from APP_URL (test fixture http://localhost:8080),
        // NEVER from the request's Host header.
        self::assertStringStartsWith('http://localhost:8080/verify-email/', $sent['url']);
        self::assertMatchesRegularExpression(
            '#^http://localhost:8080/verify-email/[0-9a-f]{64}$#',
            $sent['url'],
        );
    }

    public function test_register_seeds_session_auth_time_and_email_verified_at_null(): void
    {
        $this->request('POST', '/register', [
            'email' => 'new@example.com',
            'display_name' => 'New User',
            'password' => self::VALID_PASSWORD,
            'password_confirm' => self::VALID_PASSWORD,
        ]);

        // v0.5.0 invariant: every modern session has auth_time set
        // (SessionRevocationGuard permutation (c) — modern session with no
        // password change yet → pass).
        self::assertArrayHasKey('auth_time', $_SESSION);
        self::assertIsString($_SESSION['auth_time']);
        // Unverified at register-time.
        self::assertArrayHasKey('email_verified_at', $_SESSION);
        self::assertNull($_SESSION['email_verified_at']);
    }

    public function test_login_seeds_email_verified_at_from_user_row(): void
    {
        $uid = $this->createUserWithHash('verified@example.com', self::VALID_PASSWORD);
        $this->userRepo->markEmailVerified($uid);

        $this->request('POST', '/login', [
            'email' => 'verified@example.com',
            'password' => self::VALID_PASSWORD,
        ]);

        self::assertNotNull($_SESSION['email_verified_at'] ?? null);
        // auth_time also seeded.
        self::assertArrayHasKey('auth_time', $_SESSION);
    }
}
