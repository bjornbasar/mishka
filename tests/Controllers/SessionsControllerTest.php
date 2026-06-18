<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Auth\SessionRepository;
use App\Tests\AppTestCase;

/**
 * v0.7.0 — /me/sessions integration tests (DOCS #62).
 *
 * Pinned invariants:
 *   - GET 302→/login for anonymous
 *   - GET renders rows with "This device" badge on current
 *   - GET sets Referrer-Policy: no-referrer header (round-3 S4)
 *   - GET does NOT leak session_uuid into rendered HTML (round-3 C3)
 *   - POST /me/sessions/{id}/revoke 403 for not-owned id
 *   - POST /me/sessions/{id}/revoke 303 success + sets revoked_at
 *   - POST /me/sessions/revoke-others flips all others, keeps current
 *   - POST /me/sessions/revoke-others gracefully handles no-current-row case
 */
final class SessionsControllerTest extends AppTestCase
{
    private const VALID_PASSWORD = 'correct horse battery staple';

    private function freshSessionsRepo(): SessionRepository
    {
        return new SessionRepository($this->db);
    }

    public function test_get_redirects_anonymous_to_login(): void
    {
        $response = $this->request('GET', '/me/sessions');
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('location'));
    }

    public function test_get_renders_sessions_list_with_current_badge(): void
    {
        $uid = $this->createUserWithHash('alice@example.com', self::VALID_PASSWORD);
        $repo = $this->freshSessionsRepo();
        $currentUuid = bin2hex(random_bytes(16));
        $repo->register($uid, $currentUuid, 'Mozilla/Test', '10.0.0.1');
        $repo->register($uid, bin2hex(random_bytes(16)), 'Other Browser', '10.0.0.2');

        $this->loginAs($uid, 'alice@example.com');
        $_SESSION['session_uuid'] = $currentUuid;

        $response = $this->request('GET', '/me/sessions');

        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Mozilla/Test', $body);
        self::assertStringContainsString('Other Browser', $body);
        self::assertStringContainsString('This device', $body);
        // Referrer-Policy header set
        self::assertSame('no-referrer', $response->header('referrer-policy'));
    }

    public function test_get_does_not_leak_session_uuid_in_html(): void
    {
        $uid = $this->createUserWithHash('alice@example.com', self::VALID_PASSWORD);
        $repo = $this->freshSessionsRepo();
        $uuid1 = bin2hex(random_bytes(16));
        $uuid2 = bin2hex(random_bytes(16));
        $repo->register($uid, $uuid1, 'Browser1', '10.0.0.1');
        $repo->register($uid, $uuid2, 'Browser2', '10.0.0.2');

        $this->loginAs($uid, 'alice@example.com');
        $_SESSION['session_uuid'] = $uuid1;

        $response = $this->request('GET', '/me/sessions');

        $body = $response->body();
        // Round-3 C3: session_uuid stays server-side; the rendered HTML
        // should NOT contain either UUID literal.
        self::assertStringNotContainsString($uuid1, $body);
        self::assertStringNotContainsString($uuid2, $body);
    }

    public function test_post_revoke_flips_owned_session(): void
    {
        $uid = $this->createUserWithHash('alice@example.com', self::VALID_PASSWORD);
        $repo = $this->freshSessionsRepo();
        $uuid = bin2hex(random_bytes(16));
        $sessionId = $repo->register($uid, $uuid, 'Old Browser', '10.0.0.1');

        $this->loginAs($uid, 'alice@example.com');

        $response = $this->request('POST', '/me/sessions/' . $sessionId . '/revoke');

        self::assertSame(303, $response->status());
        self::assertSame('/me/sessions', $response->header('location'));

        $row = $repo->findByUuid($uuid);
        self::assertNotNull($row);
        self::assertNotNull($row['revoked_at']);
    }

    public function test_post_revoke_returns_403_for_not_owned_session(): void
    {
        $alice = $this->createUserWithHash('alice@example.com', self::VALID_PASSWORD);
        $bob = $this->createUserWithHash('bob@example.com', self::VALID_PASSWORD);
        $repo = $this->freshSessionsRepo();
        $bobsUuid = bin2hex(random_bytes(16));
        $bobsSessionId = $repo->register($bob, $bobsUuid, 'Bob Browser', '10.0.0.2');

        // Alice tries to revoke Bob's session.
        $this->loginAs($alice, 'alice@example.com');

        $response = $this->request('POST', '/me/sessions/' . $bobsSessionId . '/revoke');

        self::assertSame(403, $response->status());
        $row = $repo->findByUuid($bobsUuid);
        self::assertNotNull($row);
        self::assertNull($row['revoked_at']);  // not flipped
    }

    public function test_post_revoke_returns_403_for_unknown_session_id(): void
    {
        $uid = $this->createUserWithHash('alice@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'alice@example.com');

        $response = $this->request('POST', '/me/sessions/99999/revoke');

        self::assertSame(403, $response->status());
    }

    public function test_post_revoke_others_flips_others_keeps_current(): void
    {
        $uid = $this->createUserWithHash('alice@example.com', self::VALID_PASSWORD);
        $repo = $this->freshSessionsRepo();
        $currentUuid = bin2hex(random_bytes(16));
        $currentId = $repo->register($uid, $currentUuid, 'Current', '10.0.0.1');
        $otherUuid1 = bin2hex(random_bytes(16));
        $repo->register($uid, $otherUuid1, 'Other1', '10.0.0.2');
        $otherUuid2 = bin2hex(random_bytes(16));
        $repo->register($uid, $otherUuid2, 'Other2', '10.0.0.3');

        $this->loginAs($uid, 'alice@example.com');
        $_SESSION['session_uuid'] = $currentUuid;

        $response = $this->request('POST', '/me/sessions/revoke-others');

        self::assertSame(303, $response->status());
        self::assertSame('/me/sessions', $response->header('location'));

        $current = $repo->findByUuid($currentUuid);
        $other1 = $repo->findByUuid($otherUuid1);
        $other2 = $repo->findByUuid($otherUuid2);
        self::assertNotNull($current);
        self::assertNotNull($other1);
        self::assertNotNull($other2);
        self::assertNull($current['revoked_at']);  // kept
        self::assertNotNull($other1['revoked_at']);
        self::assertNotNull($other2['revoked_at']);
    }

    public function test_post_revoke_others_gracefully_handles_missing_current_row(): void
    {
        // Round-3 C7: if the current session has no user_sessions row (which
        // shouldn't happen in prod thanks to the guard's lazy backfill, but
        // CAN happen in AppTestCase which skips the guard), the controller
        // must NOT pass 0 to revokeAllForUserExcept (which throws). Instead
        // it flashes an error + redirects gracefully.
        $uid = $this->createUserWithHash('alice@example.com', self::VALID_PASSWORD);
        $this->loginAs($uid, 'alice@example.com');
        // No $_SESSION['session_uuid'] set + no user_sessions rows.

        $response = $this->request('POST', '/me/sessions/revoke-others');

        self::assertSame(303, $response->status());
        self::assertSame('/me/sessions', $response->header('location'));
    }

    public function test_post_revoke_others_redirects_anonymous_to_login(): void
    {
        $response = $this->request('POST', '/me/sessions/revoke-others');
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('location'));
    }
}
