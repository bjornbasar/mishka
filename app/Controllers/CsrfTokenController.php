<?php

declare(strict_types=1);

namespace App\Controllers;

use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Csrf;

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
 */
final class CsrfTokenController
{
    #[Route('/csrf-token', methods: ['GET'], name: 'csrf-token')]
    public function show(Request $request): Response
    {
        return (new Response())
            ->json(['token' => Csrf::token()])
            ->withHeader('Cache-Control', 'no-store');
    }
}
