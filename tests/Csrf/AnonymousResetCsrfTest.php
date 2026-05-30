<?php

declare(strict_types=1);

namespace App\Tests\Csrf;

use App\Tests\MiddlewareIntegrationTestCase;
use Karhu\Middleware\Csrf;

/**
 * B2 — anonymous CSRF posts.
 *
 * The skeptic round of the v0.5.0 plan flagged a real gap: the test harness
 * skips Csrf middleware (for usability), but production has it piped in.
 * Karhu's Session middleware seeds a session for ANY visitor, so the form
 * GET issues a CSRF token via `csrf_field()` and the POST verifies it. The
 * anonymous /password-reset route therefore needs and gets CSRF protection.
 *
 * This test boots the FULL pipe (Session + guard + Csrf) and confirms:
 *   - POST without a token → 403
 *   - POST with a valid token → 200 generic body
 */
final class AnonymousResetCsrfTest extends MiddlewareIntegrationTestCase
{
    public function test_post_password_reset_without_csrf_token_returns_403(): void
    {
        // No csrf token in the POST body — Csrf middleware rejects with 403.
        $response = $this->request('POST', '/password-reset', ['email' => 'x@example.com']);

        self::assertSame(403, $response->status());
    }

    public function test_post_password_reset_with_valid_token_returns_200_generic(): void
    {
        // Seed a session-bound CSRF token by calling Csrf::token() directly —
        // the same mechanism the form's `csrf_field()` macro uses.
        $token = Csrf::token();

        // Send as form-urlencoded so the CSRF middleware can read the token via
        // $request->post('_csrf_token'). The default test transport is JSON,
        // which puts the body in $request->body() but leaves $request->post()
        // empty — Csrf only checks the header and the post array.
        $response = $this->request('POST', '/password-reset', [
            'email' => 'ghost@example.com',
            '_csrf_token' => $token,
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Check your email', $response->body());
    }
}
