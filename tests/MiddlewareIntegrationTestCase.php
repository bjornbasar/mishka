<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\SessionRevocationGuard;
use Karhu\Middleware\Csrf;
use Karhu\Middleware\Session;

/**
 * Round-4 H-6 — base class for the small set of tests that need the FULL
 * production middleware pipe (Session → SessionRevocationGuard → Csrf →
 * router), not the lean AppTestCase pipe (no middleware).
 *
 * Targeted use cases:
 *   - B2 regression: CSRF token verification on anonymous POST
 *     (`tests/Csrf/AnonymousResetCsrfTest.php`)
 *   - BL-1 coverage: all four SessionRevocationGuard predicate permutations
 *     (`tests/Auth/SessionRevocationGuardTest.php`)
 *
 * Most controller tests stay on AppTestCase — adding Session + Csrf
 * everywhere would slow the suite significantly and force every test to
 * manage CSRF tokens manually.
 *
 * Karhu's Session middleware uses native PHP sessions; we DON'T pipe Session
 * here because PHPUnit's CLI SAPI tries to write headers (cookie) which
 * blow up in the test runner. Instead we set $_SESSION directly (mirroring
 * AppTestCase::loginAs) and pipe SessionRevocationGuard + Csrf. Both of
 * those read $_SESSION via the static Session::* facade, which works whether
 * an actual session is started or not.
 */
abstract class MiddlewareIntegrationTestCase extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Csrf::getStoredToken() requires session_status() === PHP_SESSION_ACTIVE
        // to read the session-bound token. Start a PHP session manually
        // (suppress headers-already-sent warnings — PHPUnit's CLI SAPI doesn't
        // send real headers but session_start() still tries to set the cookie).
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // Add the guard + Csrf to the pipe. The order matters: SessionRevocationGuard
        // must run before Csrf so a revoked session never gets a chance to
        // verify its (stale) CSRF token. We don't pipe the Session middleware
        // itself because PHPUnit can't set the secure cookie cleanly; we
        // initialise the session manually above.
        // v0.7.0 — guard now also takes SessionRepository for per-session
        // revoke + lazy backfill (DOCS #62).
        $sessionRepo = new \App\Auth\SessionRepository($this->db);
        $this->app->pipe(new SessionRevocationGuard($this->pwChangeRepo, $sessionRepo));
        $this->app->pipe(new Csrf());
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }
        parent::tearDown();
    }
}
