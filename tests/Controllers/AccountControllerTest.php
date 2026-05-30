<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.5.0 — AccountController integration tests.
 *
 * Covers the two `/me/*` flows: display-name edit + password change.
 *
 * Notable invariants:
 *   - All endpoints require an authed session (Session middleware skipped in
 *     AppTestCase; we set $_SESSION directly).
 *   - Password change uses the BL-2 pinned-`$now` pattern: the hash write AND
 *     the user_password_changes stamp share one timestamp, so the new session
 *     `auth_time` matches `password_changed_at` exactly (avoids self-revoke).
 *   - PasswordHasher::verify is ALWAYS called on POST /me/password regardless
 *     of validation outcome (M1 — closes a timing oracle for password length /
 *     "is this my current password" probing).
 */
final class AccountControllerTest extends AppTestCase
{
    private const VALID_PASSWORD = 'correct horse battery staple';
    private const NEW_PASSWORD = 'super secret new passphrase 2026';

    public function test_get_profile_renders_form_with_current_display_name(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD, 'Original Name');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('GET', '/me/profile');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Original Name', $response->body());
        self::assertStringContainsString('name="display_name"', $response->body());
    }

    public function test_get_profile_redirects_anonymous_to_login(): void
    {
        $response = $this->request('GET', '/me/profile');
        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('location'));
    }

    public function test_post_profile_updates_display_name(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD, 'Old');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/profile', ['display_name' => 'New Name']);

        self::assertSame(303, $response->status());
        self::assertSame('/me/profile', $response->header('location'));

        $row = $this->userRepo->findById($uid);
        self::assertNotNull($row);
        self::assertSame('New Name', $row['display_name']);
    }

    public function test_post_profile_rejects_blank_display_name(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD, 'Old');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/profile', ['display_name' => '']);

        self::assertSame(422, $response->status());
        $row = $this->userRepo->findById($uid);
        self::assertNotNull($row);
        self::assertSame('Old', $row['display_name']);   // unchanged
    }

    public function test_post_profile_rejects_overly_long_display_name(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD, 'Old');
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/profile', [
            'display_name' => str_repeat('x', 121),
        ]);

        self::assertSame(422, $response->status());
    }

    public function test_get_password_renders_form(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('GET', '/me/password');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="current_password"', $response->body());
        self::assertStringContainsString('name="new_password"', $response->body());
        self::assertStringContainsString('name="new_password_confirm"', $response->body());
    }

    public function test_post_password_with_wrong_current_returns_422(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/password', [
            'current_password' => 'wrong-current-password',
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirm' => self::NEW_PASSWORD,
        ]);

        self::assertSame(422, $response->status());

        // Hash unchanged — the original password still works.
        $user = $this->userRepo->findByUsername('me@example.com');
        self::assertNotNull($user);
        self::assertTrue($this->hasher->verify(self::VALID_PASSWORD, $user['password_hash']));
    }

    public function test_post_password_with_mismatched_confirm_returns_422(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/password', [
            'current_password' => self::VALID_PASSWORD,
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirm' => self::NEW_PASSWORD . '-different',
        ]);

        self::assertSame(422, $response->status());
    }

    public function test_post_password_with_too_short_new_returns_422(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/password', [
            'current_password' => self::VALID_PASSWORD,
            'new_password' => 'short',
            'new_password_confirm' => 'short',
        ]);

        self::assertSame(422, $response->status());
    }

    public function test_post_password_with_same_as_current_returns_422(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/password', [
            'current_password' => self::VALID_PASSWORD,
            'new_password' => self::VALID_PASSWORD,
            'new_password_confirm' => self::VALID_PASSWORD,
        ]);

        self::assertSame(422, $response->status());
    }

    public function test_post_password_success_updates_hash_and_stamps_credential_change(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/password', [
            'current_password' => self::VALID_PASSWORD,
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirm' => self::NEW_PASSWORD,
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/me/profile', $response->header('location'));

        // Hash updated — new password verifies, old does not.
        $user = $this->userRepo->findByUsername('me@example.com');
        self::assertNotNull($user);
        self::assertTrue($this->hasher->verify(self::NEW_PASSWORD, $user['password_hash']));
        self::assertFalse($this->hasher->verify(self::VALID_PASSWORD, $user['password_hash']));

        // user_password_changes row written.
        $stamp = $this->db->fetchScalar(
            'SELECT password_changed_at FROM user_password_changes WHERE user_id = :id',
            ['id' => $uid],
        );
        self::assertNotNull($stamp);
    }

    public function test_post_password_success_pins_auth_time_to_password_changed_at(): void
    {
        // BL-2 regression: $now must be pinned ONCE in the handler and shared
        // between Session::set('auth_time') + user_password_changes stamp.
        // If they drift apart, the SessionRevocationGuard would kick the user
        // out on the next request.
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $this->request('POST', '/me/password', [
            'current_password' => self::VALID_PASSWORD,
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirm' => self::NEW_PASSWORD,
        ]);

        $stamp = (string) $this->db->fetchScalar(
            'SELECT password_changed_at FROM user_password_changes WHERE user_id = :id',
            ['id' => $uid],
        );
        $authTime = $_SESSION['auth_time'] ?? null;

        self::assertNotNull($authTime);
        // The two timestamps must be IDENTICAL (round-4 BL-2 — pin-once
        // invariant). If we ever break this, the user self-revokes on the
        // very next request.
        self::assertSame($stamp, $authTime);
    }

    public function test_post_password_anonymous_returns_redirect(): void
    {
        $response = $this->request('POST', '/me/password', [
            'current_password' => 'x',
            'new_password' => 'y',
            'new_password_confirm' => 'y',
        ]);

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('location'));
    }
}
