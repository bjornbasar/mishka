<?php

declare(strict_types=1);

namespace App\Controllers;

use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Csrf;
use Karhu\Middleware\Session;

/**
 * v0.6.8 — fresh-CSRF-token endpoint.
 *
 * GET /csrf-token returns {"token": "..."} JSON with Cache-Control: no-store.
 * Powers the inline IIFE in layout.twig that refreshes the in-page token on
 * every page load, closing the cross-tab session-rotation gap (login in tab
 * A invalidates tab B's CSRF token; old behaviour was a plain-text 403; new
 * behaviour is silent refresh on tab B's next nav).
 *
 * Unauthenticated: anonymous flows (login, register, password-reset) also
 * have forms and need fresh tokens. Csrf::token() handles both authenticated
 * ($_SESSION) and anonymous (cookie fallback) paths.
 *
 * GET-safelisted by Csrf middleware, so the endpoint doesn't require a token
 * to call itself.
 *
 * Token rotation triggers: Csrf::token() rotation requires an EXPLICIT
 * Csrf::regenerate() call — Session::regenerate() alone doesn't clear
 * $_SESSION['_csrf_token']. Mishka calls Csrf::regenerate() in
 * AuthController::establishSession (login), AccountController (password
 * change), PasswordResetController (reset), HouseholdController (ownership
 * transfer + delete). Logout rotates via Session::destroy() which wipes
 * $_SESSION entirely. See DOCS.md #49.
 *
 * no-store header prevents SW / browser / Cloudflare from caching the token;
 * isCacheable() in public/service-worker.js already rejects no-store responses.
 *
 * v0.8.4 extension: response now also carries `authenticated: bool` +
 * `user_id: ?int` + `active_household_id: ?int` so the offline-logging IIFE
 * (`public/mishka-offline.js`) can pre-check session validity before
 * draining the queue. Without this, a queued POST replayed against an
 * anonymous session would follow fetch's default redirect-follow to
 * `/login` → 200 HTML → silently interpreted as "success" → the queue
 * row would be deleted despite the write never happening. See DOCS #74.
 * Anonymous callers get `authenticated: false` + null user_id/household —
 * unchanged behaviour for pre-v0.8.4 clients that only read `.token`.
 */
final class CsrfTokenController
{
    #[Route('/csrf-token', methods: ['GET'], name: 'csrf-token')]
    public function show(Request $request): Response
    {
        $userId = Session::get('user_id');
        $householdId = Session::get('active_household_id');
        return (new Response())
            ->json([
                'token' => Csrf::token(),
                'authenticated' => is_int($userId) && $userId > 0,
                'user_id' => is_int($userId) && $userId > 0 ? $userId : null,
                'active_household_id' => is_int($householdId) && $householdId > 0 ? $householdId : null,
            ])
            ->withHeader('Cache-Control', 'no-store');
    }
}
