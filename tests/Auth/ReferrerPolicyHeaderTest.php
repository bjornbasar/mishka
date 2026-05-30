<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Tests\AppTestCase;

/**
 * Round-4 H-5 — Referrer-Policy: no-referrer on token-bearing routes.
 *
 * The raw token sits in the URL of /password-reset/{token} and
 * /verify-email/{token}. Without an explicit Referrer-Policy, if the user
 * clicks an external link from those pages, the browser would send the token
 * in the Referer header to that destination — a credential leak.
 *
 * `Referrer-Policy: no-referrer` tells the browser not to send a Referer
 * at all from those pages, closing the leak.
 */
final class ReferrerPolicyHeaderTest extends AppTestCase
{
    public function test_password_reset_token_get_carries_no_referrer_header(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->resetTokenRepo->issue($uid);

        $response = $this->request('GET', '/password-reset/' . $raw);

        self::assertSame('no-referrer', $response->header('Referrer-Policy'));
    }

    public function test_password_reset_invalid_carries_no_referrer_header(): void
    {
        $response = $this->request('GET', '/password-reset/' . str_repeat('0', 64));
        // 404 invalid page — the response still mustn't leak the path-borne
        // (non-)token via Referer.
        self::assertSame('no-referrer', $response->header('Referrer-Policy'));
    }

    public function test_verify_email_token_get_carries_no_referrer_header(): void
    {
        $uid = $this->createUserWithHash('user@example.com', 'old-password-correct-horse');
        $raw = $this->verifyTokenRepo->issue($uid);

        $response = $this->request('GET', '/verify-email/' . $raw);

        // The verify path 303s on success — verify the header rides the redirect.
        self::assertSame('no-referrer', $response->header('Referrer-Policy'));
    }
}
