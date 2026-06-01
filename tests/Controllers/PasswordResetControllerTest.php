<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.5.0 — PasswordResetController integration tests.
 *
 * Coverage matrix (mirrors the plan's 14-test target plus a few extras):
 *
 * Request flow (`/password-reset`):
 *   - GET renders form
 *   - POST with unknown email → 200 generic body, zero mailer entries
 *   - POST with known email   → 200 generic body, 1 mailer entry whose URL
 *                                contains the raw token + uses APP_URL host
 *   - rate-limit: 6th POST in a window is silently swallowed (still 200,
 *     no email sent)
 *
 * Redeem flow (`/password-reset/{token}`):
 *   - Bad-shape token (not 64-hex)                  → 404 invalid
 *   - Unknown hex token                              → 404 invalid
 *   - Valid token with GET                           → form, Referrer-Policy
 *                                                       header set
 *   - Expired token                                  → 404 invalid
 *   - Used token                                     → 404 invalid
 *   - POST with mismatched passwords                 → 422
 *   - POST success                                   → updates hash, marks
 *     token used, invalidates other pending, redirects to /login?reset=ok,
 *     stamps user_password_changes, does NOT auto-login
 */
final class PasswordResetControllerTest extends AppTestCase
{
    private const NEW_PASSWORD = 'super secret new passphrase 2026';

    public function test_get_request_form_renders(): void
    {
        $response = $this->request('GET', '/password-reset');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Reset your password', $response->body());
        self::assertStringContainsString('name="email"', $response->body());
    }

    public function test_post_request_with_unknown_email_returns_generic_200(): void
    {
        $response = $this->request('POST', '/password-reset', ['email' => 'ghost@example.com']);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Check your email', $response->body());
        self::assertSame([], $this->mailer->sent);   // no email sent for unknowns
    }

    public function test_post_request_with_known_email_emails_a_link_with_raw_token(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse', 'User');

        $response = $this->request('POST', '/password-reset', ['email' => 'user@example.com']);

        self::assertSame(200, $response->status());
        self::assertCount(1, $this->mailer->sent);
        $sent = $this->mailer->sent[0];
        self::assertSame('password_reset', $sent['kind']);
        self::assertSame('user@example.com', $sent['to']);
        self::assertSame('User', $sent['display_name']);

        // B1: URL is built from APP_URL (test fixture uses http://localhost:8080).
        self::assertStringStartsWith('http://localhost:8080/password-reset/', $sent['url']);
        // The raw token in the URL must be 64 lowercase hex chars.
        self::assertMatchesRegularExpression(
            '#^http://localhost:8080/password-reset/[0-9a-f]{64}$#',
            $sent['url'],
        );
    }

    public function test_post_request_body_is_identical_for_hit_and_miss(): void
    {
        // B4: the "200 + identical body" invariant. Hit and miss must render
        // the exact same page so the user can't tell which they triggered.
        //
        // v0.6.0: layout.twig grew a <meta name="csrf-token"> in <head> for
        // the JS push-subscribe flow. The token rotates per render in the
        // test harness (no live PHP session for storage), so it's stripped
        // here — the token is not part of the body-equality contract.
        $this->createUserWithHash('user@example.com', 'old-password-correct-horse');

        $hit = $this->request('POST', '/password-reset', ['email' => 'user@example.com']);
        $this->mailer->sent = [];  // reset between requests
        $miss = $this->request('POST', '/password-reset', ['email' => 'ghost@example.com']);

        $stripCsrf = static fn(string $body): string => (string) preg_replace(
            '#<meta name="csrf-token" content="[^"]*">#',
            '<meta name="csrf-token" content="REDACTED">',
            $body,
        );

        self::assertSame($hit->status(), $miss->status());
        self::assertSame($stripCsrf($hit->body()), $stripCsrf($miss->body()));
    }

    public function test_post_request_rate_limit_silently_swallows_excess(): void
    {
        // H4: 5/10min/IP limit. The 6th attempt in the window must still
        // return 200 (no enumeration via status) but NOT send an email.
        $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';

        for ($i = 1; $i <= 5; $i++) {
            $r = $this->request('POST', '/password-reset', ['email' => 'user@example.com']);
            self::assertSame(200, $r->status());
        }
        self::assertCount(5, $this->mailer->sent);

        // 6th attempt — still 200, but should NOT add another mailer row.
        $r6 = $this->request('POST', '/password-reset', ['email' => 'user@example.com']);
        self::assertSame(200, $r6->status());
        self::assertCount(5, $this->mailer->sent);

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_get_token_with_bad_shape_returns_404(): void
    {
        $response = $this->request('GET', '/password-reset/not-a-hex-token');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('invalid or expired', $response->body());
    }

    public function test_get_token_unknown_hex_returns_invalid_page(): void
    {
        $response = $this->request('GET', '/password-reset/' . str_repeat('a', 64));
        self::assertSame(404, $response->status());
    }

    public function test_get_token_valid_renders_form_with_referrer_policy_header(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->resetTokenRepo->issue($uid);

        $response = $this->request('GET', '/password-reset/' . $raw);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="new_password"', $response->body());
        // H-5: Referrer-Policy: no-referrer so the raw token in the URL doesn't
        // leak via the Referer header if the user clicks an external link.
        self::assertSame('no-referrer', $response->header('Referrer-Policy'));
    }

    public function test_get_token_expired_returns_invalid(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->resetTokenRepo->issue($uid);
        // Backdate expiry.
        $past = gmdate('Y-m-d H:i:s', time() - 1);
        $this->db->run(
            'UPDATE password_reset_tokens SET expires_at = :p WHERE user_id = :uid',
            ['p' => $past, 'uid' => $uid],
        );

        $response = $this->request('GET', '/password-reset/' . $raw);
        self::assertSame(404, $response->status());
    }

    public function test_get_token_used_returns_invalid(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->resetTokenRepo->issue($uid);
        $row = $this->resetTokenRepo->findByRawToken($raw);
        self::assertNotNull($row);
        $this->resetTokenRepo->redeemAtomically($row['id']);  // mark used

        $response = $this->request('GET', '/password-reset/' . $raw);
        self::assertSame(404, $response->status());
    }

    public function test_post_token_mismatched_passwords_returns_422(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->resetTokenRepo->issue($uid);

        $response = $this->request('POST', '/password-reset/' . $raw, [
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirm' => self::NEW_PASSWORD . '-different',
        ]);

        self::assertSame(422, $response->status());
    }

    public function test_post_token_success_updates_hash_marks_used_and_redirects_no_autologin(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->resetTokenRepo->issue($uid);
        $row = $this->resetTokenRepo->findByRawToken($raw);
        self::assertNotNull($row);

        $response = $this->request('POST', '/password-reset/' . $raw, [
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirm' => self::NEW_PASSWORD,
        ]);

        // 303 → /login?reset=ok (no auto-login by design)
        self::assertSame(303, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('location'));
        self::assertStringContainsString('reset=ok', (string) $response->header('location'));
        self::assertArrayNotHasKey('user_id', $_SESSION);

        // Hash updated — new works, old doesn't.
        $user = $this->userRepo->findByUsername('user@example.com');
        self::assertNotNull($user);
        self::assertTrue($this->hasher->verify(self::NEW_PASSWORD, $user['password_hash']));
        self::assertFalse($this->hasher->verify('old-password-correct-horse', $user['password_hash']));

        // Token marked used.
        $reused = $this->resetTokenRepo->findByRawToken($raw);
        self::assertNotNull($reused);
        self::assertNotNull($reused['used_at']);

        // user_password_changes row stamped.
        $stamp = $this->db->fetchScalar(
            'SELECT password_changed_at FROM user_password_changes WHERE user_id = :id',
            ['id' => $uid],
        );
        self::assertNotNull($stamp);
    }

    public function test_post_token_success_invalidates_other_pending_tokens(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw1 = $this->resetTokenRepo->issue($uid);
        // The issue() call invalidates older pending automatically, so to set
        // up the "two pending at once" scenario we insert a row directly.
        $extraRaw = bin2hex(random_bytes(32));
        $this->db->run(
            "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (:uid, :hash, :exp)",
            [
                'uid' => $uid,
                'hash' => hash('sha256', $extraRaw),
                'exp' => gmdate('Y-m-d H:i:s', time() + 3600),
            ],
        );

        // Use the FIRST token.
        $this->request('POST', '/password-reset/' . $raw1, [
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirm' => self::NEW_PASSWORD,
        ]);

        // The extra token must now be invalidated.
        $extra = $this->resetTokenRepo->findByRawToken($extraRaw);
        self::assertNotNull($extra);
        self::assertNotNull($extra['used_at']);
    }

    public function test_post_token_concurrent_redemption_only_one_wins(): void
    {
        // B6: atomic single-use. Re-using the same raw token twice via the
        // controller must not double-set the password.
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->resetTokenRepo->issue($uid);

        $first = $this->request('POST', '/password-reset/' . $raw, [
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirm' => self::NEW_PASSWORD,
        ]);
        self::assertSame(303, $first->status());

        // Second submit on the same token must hit the invalid path.
        $second = $this->request('POST', '/password-reset/' . $raw, [
            'new_password' => 'another different password 2026',
            'new_password_confirm' => 'another different password 2026',
        ]);
        self::assertSame(404, $second->status());

        // Password is the value the first submit set — not overwritten by the second.
        $user = $this->userRepo->findByUsername('user@example.com');
        self::assertNotNull($user);
        self::assertTrue($this->hasher->verify(self::NEW_PASSWORD, $user['password_hash']));
    }
}
