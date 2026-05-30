<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Tests\MiddlewareIntegrationTestCase;
use Karhu\Middleware\Csrf;

/**
 * Round-4 BL-2 regression — the user who changes their own password must
 * NOT self-revoke on the very next request.
 *
 * Without the pin-`$now`-once invariant, the AccountController would call
 * gmdate() twice (once for the credential-change stamp, once for the
 * session's new auth_time), and on a slow request the two values could differ
 * by 1+ seconds. The SessionRevocationGuard would then see
 * `auth_time < password_changed_at` and bounce the user.
 *
 * This test runs through the full middleware pipe (so the guard is live)
 * and verifies the post-password-change request hits the AccountController
 * normally, not /login?reason=password_changed.
 */
final class AccountControllerPwChangeNoSelfRevokeTest extends MiddlewareIntegrationTestCase
{
    public function test_password_change_followed_by_another_request_does_not_self_revoke(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'correct horse battery staple');
        $this->loginAs($uid, 'user@example.com');
        $_SESSION['auth_time'] = gmdate('Y-m-d H:i:s');

        // Step 1: change password. The handler pins $now once and shares it
        // between updatePassword's stamp + Session::set('auth_time').
        $token = Csrf::token();
        $changed = $this->request('POST', '/me/password', [
            'current_password' => 'correct horse battery staple',
            'new_password' => 'brand new passphrase 2026',
            'new_password_confirm' => 'brand new passphrase 2026',
            '_csrf_token' => $token,
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(303, $changed->status(), 'Password change must succeed.');

        // Step 2: immediately make ANOTHER request. The SessionRevocationGuard
        // sees user_password_changes.password_changed_at === Session.auth_time,
        // predicate `auth_time < password_changed_at` is false → pass.
        // If BL-2 ever regresses, this lands on /login?reason=password_changed.
        $next = $this->request('GET', '/me/profile');
        self::assertNotSame(
            '/login?reason=password_changed',
            (string) $next->header('location'),
            'BL-2 regression: the user self-revoked after changing their own password.',
        );
    }
}
