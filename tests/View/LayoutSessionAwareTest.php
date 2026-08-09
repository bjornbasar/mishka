<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Tests\AppTestCase;

/**
 * v0.8.7 — layout.twig must NOT emit the `<meta csrf-token>` head tag OR
 * the body-end `/csrf-token` refresh IIFE for anonymous requests.
 *
 * Motivation (DOCS #77): both call sites reach Csrf::token() which triggers
 * Csrf::regenerate() when the session has no stored token yet. Every
 * anonymous request without a session cookie therefore writes a session
 * file. On bot-scan traffic (~3169/day observed pre-v0.8.7 in prod) this
 * accumulated to 191k files in the mishka-sessions volume, which in turn
 * made the CI deploy's alpine chown-R step a 2h blocker (DOCS #77 story).
 *
 * v0.8.6's `404_anon.twig` fixed the unmatched-route path only; this
 * generalises the mitigation to every anonymous page that extends layout.
 * Post-v0.8.7 the only sessions written by anonymous requests are those
 * where an anonymous form POST is intended (Csrf::field() inside e.g.
 * /login's form still triggers a session write — deliberate, needed for
 * the double-submit CSRF check on the POST that follows).
 */
final class LayoutSessionAwareTest extends AppTestCase
{
    public function test_anonymous_home_omits_csrf_meta(): void
    {
        // Anonymous GET / renders home.twig extending layout.twig with the
        // pitch content (see HomeController::show L46-49). The head must
        // NOT contain the csrf-token meta — that would fire Csrf::token()
        // and write a session file per bot hit.
        $response = $this->request('GET', '/');

        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('<meta name="csrf-token"', $response->body());
    }

    public function test_anonymous_home_omits_csrf_refresh_iife(): void
    {
        // The body-end IIFE at layout.twig L564-593 fetches /csrf-token on
        // DOMContentLoaded + pageshow(persisted) to refresh a stale token.
        // Anonymous pages should skip it — no meta to refresh + the fetch
        // itself triggers a session write on the server side.
        $response = $this->request('GET', '/');

        self::assertSame(200, $response->status());
        self::assertStringNotContainsString("fetch('/csrf-token'", $response->body());
    }

    public function test_authenticated_home_emits_csrf_meta(): void
    {
        // Regression guard: authenticated flows depend on the meta tag for
        // JS callers (push-subscribe.js on /me/notifications, the tracker
        // offline IIFE, any future XHR). MUST still emit for session_email
        // present.
        [$uid, $hid] = $this->signedIn();

        $response = $this->request('GET', '/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<meta name="csrf-token"', $response->body());
        self::assertStringContainsString("fetch('/csrf-token'", $response->body());
    }

    public function test_anonymous_login_page_omits_csrf_meta(): void
    {
        // /login is an anonymous-facing form page. The meta tag stays off
        // for the same reason — no JS caller needs it here. The inline
        // <form> body still uses csrf_field() which triggers the session
        // write on GET, but that's the ONE write per legit login attempt
        // instead of one per bot scan of the site's home.
        $response = $this->request('GET', '/login');

        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('<meta name="csrf-token"', $response->body());
    }

    public function test_anonymous_home_still_extends_layout(): void
    {
        // Sanity: the anonymous body IS the full layout (nav header,
        // footer, brand) — this test locks the "did we break the layout
        // extend?" regression. If someone accidentally converted
        // home.twig to a bare document while trying to strip CSRF, this
        // catches it.
        $response = $this->request('GET', '/');

        self::assertSame(200, $response->status());
        // Brand from config/brand.php + footer both come from layout.twig.
        self::assertStringContainsString('Mishka Den', $response->body());
    }

    /** @return array{0: int, 1: int} */
    private function signedIn(string $email = 'bjorn@x'): array
    {
        $uid = $this->createUserWithHash($email, 'passw0rd!', 'Bjorn');
        $hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'owner')",
            ['hid' => $hid, 'uid' => $uid],
        );
        $this->loginAs($uid, $email);
        $this->activateHouseholdInSession($uid, $hid, 'owner');
        return [$uid, $hid];
    }
}
