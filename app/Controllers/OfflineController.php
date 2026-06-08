<?php

declare(strict_types=1);

namespace App\Controllers;

use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\View\TwigAdapter;

/**
 * v0.6.7 — /offline shell.
 *
 * Forcibly-anonymous fallback page precached by the service worker. Rendered
 * when the user is offline AND the requested route is not in the cache.
 *
 * CRITICAL: this controller MUST NOT merge NavContext::forCurrentUser(). The
 * SW caches this page at install time, typically while the user IS logged in
 * (the SW registration script in layout.twig fires on every nav). If the
 * cache contained that user's session_email / household-switcher / CSRF
 * token, a different user on the same device (mom -> child on family iPad)
 * would see the previous user's chrome the next time they went offline.
 *
 * Forces layout.twig's `{% if session_email %}` guard to render the anonymous
 * "Sign in / Register" branch by passing explicit empty NavContext keys. Key
 * shape mirrors NavContext::forCurrentUser() exactly (single 'flash' key, not
 * 'flash_success'/'flash_error').
 *
 * Test gate: tests/View/ServiceWorkerStructureTest::test_offline_template_omits_personal_data
 * (added when implemented) GETs /offline while logged in and asserts the body
 * doesn't contain session_email / household names / hidden CSRF form fields.
 * The unconditional `<meta name="csrf-token">` in layout.twig:12 DOES bake
 * into the cache — acceptable because karhu rotates tokens on session_regenerate
 * (login/logout/password-change per decision #36) so a stale meta token is
 * useless to a different user.
 */
final class OfflineController
{
    public function __construct(
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/offline', methods: ['GET'], name: 'offline')]
    public function show(Request $request): Response
    {
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('offline.twig', [
                'session_email' => null,
                'active_household' => null,
                'households' => [],
                'verify_required' => false,
                'flash' => null,
            ]));
    }
}
