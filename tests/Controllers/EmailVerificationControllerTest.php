<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.5.0 — EmailVerificationController integration tests.
 *
 * Covers both routes (GET /verify-email/{token} + POST /me/verify-email/resend)
 * and the soft-banner / session-state interaction.
 */
final class EmailVerificationControllerTest extends AppTestCase
{
    public function test_get_valid_token_marks_verified_and_redirects(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->verifyTokenRepo->issue($uid);

        $response = $this->request('GET', '/verify-email/' . $raw);

        self::assertSame(303, $response->status());
        self::assertSame('no-referrer', $response->header('Referrer-Policy'));
        self::assertTrue($this->userRepo->isEmailVerified($uid));
    }

    public function test_get_valid_token_with_active_session_updates_session_verify_flag(): void
    {
        // H5: if the redeeming browser also holds the user's session, the
        // session-cached email_verified_at must be stamped so the banner
        // disappears without a re-login.
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $this->loginAs($uid, 'user@example.com');
        $_SESSION['email_verified_at'] = null;
        $raw = $this->verifyTokenRepo->issue($uid);

        $this->request('GET', '/verify-email/' . $raw);

        self::assertNotNull($_SESSION['email_verified_at']);
    }

    public function test_get_second_visit_returns_invalid_after_single_use(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->verifyTokenRepo->issue($uid);

        $first = $this->request('GET', '/verify-email/' . $raw);
        self::assertSame(303, $first->status());

        $second = $this->request('GET', '/verify-email/' . $raw);
        self::assertSame(404, $second->status());
        self::assertSame('no-referrer', $second->header('Referrer-Policy'));
    }

    public function test_get_expired_token_returns_invalid(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->verifyTokenRepo->issue($uid);
        $past = gmdate('Y-m-d H:i:s', time() - 1);
        $this->db->run(
            'UPDATE email_verification_tokens SET expires_at = :p WHERE user_id = :uid',
            ['p' => $past, 'uid' => $uid],
        );

        $response = $this->request('GET', '/verify-email/' . $raw);
        self::assertSame(404, $response->status());
        self::assertFalse($this->userRepo->isEmailVerified($uid));
    }

    public function test_get_bad_shape_token_returns_invalid(): void
    {
        $response = $this->request('GET', '/verify-email/not-a-hex-token');
        self::assertSame(404, $response->status());
    }

    public function test_post_resend_anonymous_redirects_to_login(): void
    {
        $response = $this->request('POST', '/me/verify-email/resend');
        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('location'));
    }

    public function test_post_resend_invalidates_pending_and_issues_new_token_and_mails(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse', 'User');
        $oldRaw = $this->verifyTokenRepo->issue($uid);
        $this->loginAs($uid, 'user@example.com');

        $response = $this->request('POST', '/me/verify-email/resend');

        self::assertSame(303, $response->status());
        // 1 mail sent with kind=verification + APP_URL-based URL.
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('verification', $this->mailer->sent[0]['kind']);
        self::assertStringStartsWith('http://localhost:8080/verify-email/', $this->mailer->sent[0]['url']);

        // Old token is invalidated.
        $oldRow = $this->verifyTokenRepo->findByRawToken($oldRaw);
        self::assertNotNull($oldRow);
        self::assertNotNull($oldRow['used_at']);
    }

    public function test_post_resend_already_verified_is_idempotent(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $this->userRepo->markEmailVerified($uid);
        $this->loginAs($uid, 'user@example.com');

        $response = $this->request('POST', '/me/verify-email/resend');

        self::assertSame(303, $response->status());
        self::assertSame([], $this->mailer->sent);   // no resend for already-verified
    }
}
