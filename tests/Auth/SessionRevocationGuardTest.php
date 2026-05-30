<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Tests\MiddlewareIntegrationTestCase;

/**
 * Round-4 BL-1 — SessionRevocationGuard predicate matrix.
 *
 * All four permutations + the anonymous-pass case. The guard sits between
 * Session and Csrf in production; the test boots a partial pipe (no Session
 * middleware, see MiddlewareIntegrationTestCase docs) with the guard and Csrf.
 *
 * The endpoint we hit is `/me/profile` (a session-gated AccountController
 * route) — pass → 200; revoke → 302 /login?reason=password_changed.
 */
final class SessionRevocationGuardTest extends MiddlewareIntegrationTestCase
{
    public function test_a_legacy_session_with_no_password_change_passes(): void
    {
        // Permutation (a): auth_time absent + no row in user_password_changes.
        // User decision U-1: grandfather these. Pass.
        $uid = $this->createUserWithHash('legacy@example.com', 'old-password-correct-horse');
        $this->loginAs($uid, 'legacy@example.com');
        // Critically: DO NOT set $_SESSION['auth_time'] — simulating pre-v0.5 session.
        unset($_SESSION['auth_time']);

        $response = $this->request('GET', '/me/profile');

        // The guard passes; AccountController renders or 302s for some other
        // reason (csrf token handling); but it must NOT be the revoke 302 to
        // /login?reason=password_changed.
        self::assertNotSame(
            '/login?reason=password_changed',
            (string) $response->header('location'),
            'Guard incorrectly revoked a legacy session with no pw-change record.',
        );
    }

    public function test_b_legacy_session_post_password_change_is_revoked(): void
    {
        // Permutation (b): auth_time absent + row in user_password_changes set.
        // Treat as a stolen pre-v0.5 cookie that survived a password reset.
        // Revoke.
        $uid = $this->createUserWithHash('legacy@example.com', 'old-password-correct-horse');
        $this->loginAs($uid, 'legacy@example.com');
        unset($_SESSION['auth_time']);
        // User changed password since the session was issued.
        $this->pwChangeRepo->stamp($uid, gmdate('Y-m-d H:i:s'));

        $response = $this->request('GET', '/me/profile');

        self::assertSame(302, $response->status());
        self::assertSame('/login?reason=password_changed', $response->header('location'));
    }

    public function test_c_modern_session_with_no_password_change_passes(): void
    {
        // Permutation (c): auth_time set + no row in user_password_changes.
        // Pass.
        $uid = $this->createUserWithHash('modern@example.com', 'old-password-correct-horse');
        $this->loginAs($uid, 'modern@example.com');
        $_SESSION['auth_time'] = gmdate('Y-m-d H:i:s');

        $response = $this->request('GET', '/me/profile');

        self::assertNotSame(
            '/login?reason=password_changed',
            (string) $response->header('location'),
        );
    }

    public function test_d_modern_session_older_than_password_change_is_revoked(): void
    {
        // Permutation (d): both set, auth_time < password_changed_at. Revoke.
        $uid = $this->createUserWithHash('modern@example.com', 'old-password-correct-horse');
        $this->loginAs($uid, 'modern@example.com');
        $_SESSION['auth_time'] = '2026-01-01 00:00:00';   // old
        $this->pwChangeRepo->stamp($uid, '2026-05-01 00:00:00');   // newer

        $response = $this->request('GET', '/me/profile');

        self::assertSame(302, $response->status());
        self::assertSame('/login?reason=password_changed', $response->header('location'));
    }

    public function test_d_modern_session_newer_than_password_change_passes(): void
    {
        // Permutation (d) — the BL-2 regression scenario. After a password
        // change, the user's own session has auth_time === password_changed_at;
        // the comparison `<` is false → pass. If auth_time and changedAt drift
        // apart (BL-2 bug), this test fails.
        $uid = $this->createUserWithHash('modern@example.com', 'old-password-correct-horse');
        $this->loginAs($uid, 'modern@example.com');
        $sharedNow = gmdate('Y-m-d H:i:s');
        $_SESSION['auth_time'] = $sharedNow;
        $this->pwChangeRepo->stamp($uid, $sharedNow);

        $response = $this->request('GET', '/me/profile');

        self::assertNotSame(
            '/login?reason=password_changed',
            (string) $response->header('location'),
        );
    }

    public function test_anonymous_request_passes_regardless(): void
    {
        // Anonymous: no session.user_id → guard returns next() immediately.
        $response = $this->request('GET', '/login');

        self::assertNotSame(
            '/login?reason=password_changed',
            (string) $response->header('location'),
        );
    }
}
