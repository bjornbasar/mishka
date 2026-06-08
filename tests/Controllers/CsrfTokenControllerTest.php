<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\MiddlewareIntegrationTestCase;
use Karhu\Middleware\Csrf;

/**
 * v0.6.8 — GET /csrf-token endpoint.
 *
 * Extends MiddlewareIntegrationTestCase because Csrf::token() requires
 * session_status() === PHP_SESSION_ACTIVE to read/write the session-bound
 * token. The harness starts the session in its setUp.
 *
 * Powers the inline IIFE in layout.twig that closes the cross-tab
 * session-rotation gap.
 */
final class CsrfTokenControllerTest extends MiddlewareIntegrationTestCase
{
    public function test_get_returns_200_json_with_token_field(): void
    {
        $response = $this->request('GET', '/csrf-token', [], headers: ['accept' => 'application/json']);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('application/json', (string) $response->header('content-type'));

        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('token', $body);
        self::assertIsString($body['token']);
        self::assertNotSame('', $body['token']);
        // Assert hex-shape (semantic), NOT specific length — decouples from
        // karhu's TOKEN_LENGTH constant which could change.
        self::assertTrue(ctype_xdigit($body['token']));
    }

    public function test_returned_token_matches_csrf_static_token(): void
    {
        // The endpoint must return the same token Csrf::token() would issue
        // server-side, so a subsequent POST carrying it passes Csrf verification.
        $response = $this->request('GET', '/csrf-token', [], headers: ['accept' => 'application/json']);
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Csrf::token(), $body['token']);
    }

    public function test_anonymous_request_works(): void
    {
        // No loginAs() — endpoint must still 200 for anonymous flows
        // (login, register, password-reset all have forms needing fresh tokens).
        $response = $this->request('GET', '/csrf-token', [], headers: ['accept' => 'application/json']);

        self::assertSame(200, $response->status());
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotSame('', $body['token']);
        self::assertTrue(ctype_xdigit($body['token']));
    }

    public function test_logged_in_user_gets_session_bound_token(): void
    {
        // Semantic round-trip: log in, fetch token via the endpoint, POST a
        // protected route with it. Must NOT 403 — proves the endpoint serves
        // the value the server expects on the next POST.
        $uid = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'me@example.com');

        $tokenResponse = $this->request('GET', '/csrf-token', [], headers: ['accept' => 'application/json']);
        $token = json_decode($tokenResponse->body(), true, flags: JSON_THROW_ON_ERROR)['token'];

        $postResponse = $this->request('POST', '/me/profile', [
            'display_name' => 'New Name',
            '_csrf_token' => $token,
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);

        self::assertNotSame(403, $postResponse->status(), 'Endpoint-issued token must not be rejected by Csrf middleware');
    }

    public function test_after_csrf_regenerate_endpoint_returns_new_token(): void
    {
        $first = $this->request('GET', '/csrf-token', [], headers: ['accept' => 'application/json']);
        $t1 = json_decode($first->body(), true, flags: JSON_THROW_ON_ERROR)['token'];

        // Simulate login/logout/password-change which call Csrf::regenerate().
        Csrf::regenerate();

        $second = $this->request('GET', '/csrf-token', [], headers: ['accept' => 'application/json']);
        $t2 = json_decode($second->body(), true, flags: JSON_THROW_ON_ERROR)['token'];

        self::assertNotSame($t1, $t2, 'Token must rotate after Csrf::regenerate()');
    }

    public function test_response_has_cache_control_no_store(): void
    {
        // CRITICAL: no-store prevents SW, browser HTTP cache, and Cloudflare
        // from storing the token. Without this, the SW's network-with-cache-
        // fallback would happily cache and re-serve stale tokens.
        $response = $this->request('GET', '/csrf-token', [], headers: ['accept' => 'application/json']);

        $cc = (string) $response->header('cache-control');
        self::assertStringContainsString('no-store', $cc);
    }

    public function test_post_to_csrf_token_route_returns_405_with_allow_header(): void
    {
        // Karhu's router returns 405 (NOT 404) when path matches but method
        // doesn't. Allow header lists permitted methods sorted alphabetically:
        // 'GET, HEAD' (HEAD auto-added by karhu when a GET route is defined).
        // Must send a valid CSRF token in the POST — otherwise Csrf middleware
        // 403s the request before the router can resolve to 405 (security-first
        // middleware ordering).
        $token = Csrf::token();
        $response = $this->request('POST', '/csrf-token', ['_csrf_token' => $token], headers: ['content-type' => 'application/x-www-form-urlencoded']);

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', (string) $response->header('allow'));
    }

    public function test_head_request_returns_200_with_cache_control(): void
    {
        // Karhu auto-permits HEAD on GET routes. Body-stripping for HEAD
        // happens at the PHP SAPI layer (mod_php / fpm), NOT in the app —
        // PHPUnit bypasses SAPI, so the response object still has the JSON
        // body. We don't assert empty body for that reason; we assert the
        // endpoint is HEAD-callable and returns the same Cache-Control header.
        $response = $this->request('HEAD', '/csrf-token', [], headers: ['accept' => 'application/json']);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('no-store', (string) $response->header('cache-control'));
    }
}
