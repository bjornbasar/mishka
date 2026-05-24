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

        self::assertSame(303, $response->status());
        self::assertSame('/', $response->header('location'));
        self::assertSame('first@example.com', $_SESSION['username'] ?? null);
        self::assertGreaterThan(0, $_SESSION['user_id'] ?? 0);
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
}
