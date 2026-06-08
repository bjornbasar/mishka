<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.6.11 — AccountController email-change integration tests.
 *
 * Covers the four new handlers (GET/POST /me/email + GET/POST
 * /me/email-change/{token}). Pinned invariants:
 *   - PasswordHasher::verify is ALWAYS called on POST /me/email regardless of
 *     other validation outcomes (M1 — same shape as /me/password).
 *   - GET /me/email-change/{token} is non-destructive — defends against
 *     email-client link prefetch.
 *   - POST /me/email-change/{token} runs the swap inside an outer txn;
 *     UNIQUE conflict surfaces as 422, not 500.
 *   - Email change does NOT write user_password_changes — SessionRevocationGuard
 *     must not fire.
 */
final class AccountControllerEmailChangeTest extends AppTestCase
{
    private const VALID_PASSWORD = 'correct horse battery staple';

    // ============================================================
    // GET /me/email — form render
    // ============================================================

    public function test_get_email_renders_form_with_current_email(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('GET', '/me/email');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('me@example.com', $response->body());
        self::assertStringContainsString('name="new_email"', $response->body());
        self::assertStringContainsString('name="current_password"', $response->body());
    }

    public function test_get_email_redirects_anonymous_to_login(): void
    {
        $response = $this->request('GET', '/me/email');
        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('location'));
    }

    // ============================================================
    // POST /me/email — issue token + send emails
    // ============================================================

    public function test_post_email_with_wrong_current_password_returns_422(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/email', [
            'new_email' => 'new@example.com',
            'current_password' => 'wrong-password',
        ]);

        self::assertSame(422, $response->status());
        // No token issued; no mails sent.
        self::assertSame(0, (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM email_change_tokens WHERE user_id = :u',
            ['u' => $uid],
        ));
        self::assertEmpty($this->mailer->sent);
        // Current email unchanged.
        $u = $this->userRepo->findById($uid);
        self::assertNotNull($u);
        self::assertSame('me@example.com', $u['email']);
    }

    public function test_post_email_with_invalid_shape_returns_422_no_token_no_mail(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/email', [
            'new_email' => 'not-an-email',
            'current_password' => self::VALID_PASSWORD,
        ]);

        self::assertSame(422, $response->status());
        self::assertSame(0, (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM email_change_tokens WHERE user_id = :u',
            ['u' => $uid],
        ));
        self::assertEmpty($this->mailer->sent);
    }

    public function test_post_email_with_same_as_current_returns_422(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/email', [
            'new_email' => 'me@example.com',
            'current_password' => self::VALID_PASSWORD,
        ]);

        self::assertSame(422, $response->status());
        self::assertSame(0, (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM email_change_tokens WHERE user_id = :u',
            ['u' => $uid],
        ));
    }

    public function test_post_email_with_email_already_taken_returns_422(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->createUserWithHash('taken@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/email', [
            'new_email' => 'taken@example.com',
            'current_password' => self::VALID_PASSWORD,
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('already in use', $response->body());
    }

    public function test_post_email_success_issues_token_and_sends_two_emails(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $response = $this->request('POST', '/me/email', [
            'new_email' => 'new@example.com',
            'current_password' => self::VALID_PASSWORD,
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/me/email', $response->header('location'));

        // Exactly 1 token row, pending + sent_at non-null (markSent fired).
        $tokens = $this->db->fetchAll(
            'SELECT new_email, used_at, sent_at FROM email_change_tokens WHERE user_id = :u',
            ['u' => $uid],
        );
        self::assertCount(1, $tokens);
        self::assertSame('new@example.com', $tokens[0]['new_email']);
        self::assertNull($tokens[0]['used_at']);
        self::assertNotNull($tokens[0]['sent_at']);

        // 2 emails sent: change to new + notification to old.
        self::assertCount(2, $this->mailer->sent);
        self::assertSame('email_change', $this->mailer->sent[0]['kind']);
        self::assertSame('new@example.com', $this->mailer->sent[0]['to']);
        self::assertSame('email_change_notification', $this->mailer->sent[1]['kind']);
        self::assertSame('me@example.com', $this->mailer->sent[1]['to']);
        // Notification carries the masked new email — first char + *** + @domain.
        self::assertSame('n***@example.com', $this->mailer->sent[1]['url']);

        // Swap has NOT happened yet — users.email unchanged.
        $u = $this->userRepo->findById($uid);
        self::assertNotNull($u);
        self::assertSame('me@example.com', $u['email']);
    }

    public function test_post_email_rate_limit_blocks_fourth_attempt(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        // 3 successful issues + 1 over-limit.
        for ($i = 0; $i < 3; $i++) {
            $r = $this->request('POST', '/me/email', [
                'new_email' => "new{$i}@example.com",
                'current_password' => self::VALID_PASSWORD,
            ]);
            self::assertSame(303, $r->status(), "attempt {$i} should succeed");
        }
        $fourth = $this->request('POST', '/me/email', [
            'new_email' => 'new4@example.com',
            'current_password' => self::VALID_PASSWORD,
        ]);

        self::assertSame(429, $fourth->status());
    }

    public function test_post_email_records_rate_limit_kind_change_email_request(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');

        $this->request('POST', '/me/email', [
            'new_email' => 'new@example.com',
            'current_password' => self::VALID_PASSWORD,
        ]);

        $kind = $this->db->fetchScalar(
            'SELECT kind FROM email_send_attempts WHERE user_id = :u ORDER BY id DESC LIMIT 1',
            ['u' => $uid],
        );
        self::assertSame('change_email_request', $kind);
    }

    // ============================================================
    // GET /me/email-change/{token} — confirm page
    // ============================================================

    public function test_get_email_change_token_renders_confirm_page(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $token = $this->changeTokenRepo->issue($uid, 'new@example.com');

        $response = $this->request('GET', '/me/email-change/' . $token);

        self::assertSame(200, $response->status());
        // Both old and new emails are rendered for visual confirmation (round-2 S4).
        self::assertStringContainsString('me@example.com', $response->body());
        self::assertStringContainsString('new@example.com', $response->body());
        // POST form present with CSRF (round-2 C5).
        self::assertStringContainsString('method="POST"', $response->body());
        self::assertStringContainsString('name="_csrf_token"', $response->body());
        // Referrer-Policy header (decision #44).
        self::assertStringContainsString('no-referrer', (string) $response->header('referrer-policy'));
    }

    public function test_get_email_change_token_does_NOT_apply_swap(): void
    {
        // The critical link-prefetch defence: GET must be non-destructive.
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $token = $this->changeTokenRepo->issue($uid, 'new@example.com');

        $this->request('GET', '/me/email-change/' . $token);

        // users.email unchanged; token still pending.
        $u = $this->userRepo->findById($uid);
        self::assertNotNull($u);
        self::assertSame('me@example.com', $u['email']);
        $tokenRow = $this->changeTokenRepo->findByRawToken($token);
        self::assertNotNull($tokenRow);
        self::assertNull($tokenRow['used_at']);
    }

    public function test_get_email_change_with_bad_shape_token_returns_invalid(): void
    {
        $response = $this->request('GET', '/me/email-change/not-a-real-token');
        self::assertSame(404, $response->status());
    }

    public function test_get_email_change_with_used_token_returns_invalid(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $token = $this->changeTokenRepo->issue($uid, 'new@example.com');
        // Manually consume it.
        $row = $this->changeTokenRepo->findByRawToken($token);
        self::assertNotNull($row);
        $this->changeTokenRepo->redeemAtomically($row['id']);

        $response = $this->request('GET', '/me/email-change/' . $token);
        self::assertSame(404, $response->status());
    }

    // ============================================================
    // POST /me/email-change/{token} — atomic swap
    // ============================================================

    public function test_post_email_change_applies_swap_and_marks_verified(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $token = $this->changeTokenRepo->issue($uid, 'new@example.com');

        $response = $this->request('POST', '/me/email-change/' . $token, []);

        self::assertSame(303, $response->status());
        self::assertSame('/me/profile', $response->header('location'));

        $u = $this->userRepo->findById($uid);
        self::assertNotNull($u);
        self::assertSame('new@example.com', $u['email']);
        self::assertTrue($this->userRepo->isEmailVerified($uid));

        // Token row used_at is now set.
        $row = $this->changeTokenRepo->findByRawToken($token);
        self::assertNotNull($row);
        self::assertNotNull($row['used_at']);
    }

    public function test_post_email_change_invalidates_pending_password_reset_tokens(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        // Issue a reset token BEFORE the swap.
        $this->resetTokenRepo->issue($uid);
        $token = $this->changeTokenRepo->issue($uid, 'new@example.com');

        $this->request('POST', '/me/email-change/' . $token, []);

        // No more pending reset tokens after the swap.
        $pendingResets = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM password_reset_tokens
             WHERE user_id = :u AND used_at IS NULL',
            ['u' => $uid],
        );
        self::assertSame(0, $pendingResets);
    }

    public function test_post_email_change_invalidates_pending_email_verification_tokens(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->verifyTokenRepo->issue($uid);
        $token = $this->changeTokenRepo->issue($uid, 'new@example.com');

        $this->request('POST', '/me/email-change/' . $token, []);

        $pendingVerify = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM email_verification_tokens
             WHERE user_id = :u AND used_at IS NULL',
            ['u' => $uid],
        );
        self::assertSame(0, $pendingVerify);
    }

    public function test_post_email_change_when_session_holds_user_refreshes_session_username(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'me@example.com');
        $token = $this->changeTokenRepo->issue($uid, 'new@example.com');

        $this->request('POST', '/me/email-change/' . $token, []);

        self::assertSame('new@example.com', $_SESSION['username'] ?? null);
        self::assertNotNull($_SESSION['email_verified_at'] ?? null);
    }

    public function test_post_email_change_when_no_session_skips_session_refresh(): void
    {
        // Cross-device: anonymous browser clicks the link.
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $token = $this->changeTokenRepo->issue($uid, 'new@example.com');
        // No loginAs — session is empty.

        $response = $this->request('POST', '/me/email-change/' . $token, []);

        self::assertSame(303, $response->status());
        // Swap still applied at the DB level.
        $u = $this->userRepo->findById($uid);
        self::assertNotNull($u);
        self::assertSame('new@example.com', $u['email']);
        // No session writes.
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertArrayNotHasKey('username', $_SESSION);
    }

    public function test_post_email_change_second_post_after_swap_returns_invalid(): void
    {
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $token = $this->changeTokenRepo->issue($uid, 'new@example.com');

        $first = $this->request('POST', '/me/email-change/' . $token, []);
        self::assertSame(303, $first->status());

        $second = $this->request('POST', '/me/email-change/' . $token, []);
        self::assertSame(404, $second->status());
    }

    public function test_post_email_change_with_unique_conflict_returns_422_not_500(): void
    {
        // Set up: User A requests change to taken@. User B then takes the email
        // before A clicks the link. A's POST should 422 conflict, not 500.
        $uidA = $this->createUserWithHash('a@example.com', self::VALID_PASSWORD);
        $token = $this->changeTokenRepo->issue($uidA, 'wanted@example.com');
        // User B claims the email mid-flight.
        $this->createUserWithHash('wanted@example.com', self::VALID_PASSWORD);

        $response = $this->request('POST', '/me/email-change/' . $token, []);

        self::assertSame(422, $response->status());
        // User A's email unchanged.
        $a = $this->userRepo->findById($uidA);
        self::assertNotNull($a);
        self::assertSame('a@example.com', $a['email']);
    }

    public function test_post_email_change_does_NOT_write_user_password_changes_row(): void
    {
        // Critical regression guard: email change is NOT a credential change.
        // SessionRevocationGuard depends on user_password_changes; if we ever
        // accidentally write to it on email change, every user who changes
        // email would self-revoke (BL-2 trap).
        $uid = $this->createUserWithHash('me@example.com', self::VALID_PASSWORD);
        $token = $this->changeTokenRepo->issue($uid, 'new@example.com');

        $this->request('POST', '/me/email-change/' . $token, []);

        $rowCount = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_password_changes WHERE user_id = :u',
            ['u' => $uid],
        );
        self::assertSame(0, $rowCount, 'email change MUST NOT touch user_password_changes');
    }
}
