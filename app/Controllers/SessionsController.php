<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.7.0 — /me/sessions UI for per-device session revoke (DOCS #62).
 *
 * Three routes:
 *   - GET  /me/sessions             list active sessions
 *   - POST /me/sessions/{id}/revoke revoke specific (ownership-checked)
 *   - POST /me/sessions/revoke-others bulk-revoke all OTHER sessions
 *
 * Auth posture:
 *   - Anonymous → 302 /login (existing AccountController convention).
 *   - Logged in but `id` not owned / not found → 403.
 *
 * Privacy:
 *   - Sets `Referrer-Policy: no-referrer` on the GET response (round-3
 *     S4) so the IP/UA list doesn't leak via the Referer header on
 *     subsequent navigations.
 *   - Maps repo rows to clean DTOs before passing to Twig — the raw
 *     session_uuid never enters rendered HTML (round-3 C3). Twig only
 *     sees: id, device_label, ip, last_used_at, created_at, is_current.
 */
final class SessionsController
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly TwigAdapter $view,
        private readonly NavContext $nav,
    ) {}

    #[Route('/me/sessions', methods: ['GET'], name: 'me.sessions')]
    public function showSessions(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        $currentUuid = (string) (Session::get('session_uuid') ?? '');
        $rows = $this->sessions->listActiveForUser($uid);

        // Map to clean DTOs — session_uuid stays server-side (round-3 C3).
        $dtos = [];
        foreach ($rows as $r) {
            $dtos[] = [
                'id' => $r['id'],
                'device_label' => self::deviceLabel($r['user_agent']),
                'ip' => $r['ip'],
                'last_used_at' => $r['last_used_at'],
                'created_at' => $r['created_at'],
                'is_current' => $r['session_uuid'] === $currentUuid,
            ];
        }

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody($this->view->render('account/sessions.twig', [
                'sessions' => $dtos,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/me/sessions/{id}/revoke', methods: ['POST'])]
    public function revokeSession(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        $id = (int) ($request->routeParams()['id'] ?? 0);
        $ok = $this->sessions->revoke($uid, $id);
        if (!$ok) {
            // Not owned, not found, or already-revoked. 403 for the first
            // two; idempotent re-revoke also 403s for simplicity.
            return (new Response(403))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody('<h1>Forbidden</h1><p>Session not found or not yours.</p>');
        }

        Session::set('flash_success', 'Session revoked.');
        return (new Response())->redirect('/me/sessions', 303);
    }

    #[Route('/me/sessions/revoke-others', methods: ['POST'])]
    public function revokeOthers(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        // Find current session row id (defensive null-skip mirroring
        // AccountController::handlePasswordPost — round-3 C7).
        $currentUuid = (string) (Session::get('session_uuid') ?? '');
        $currentRow = $currentUuid !== '' ? $this->sessions->findByUuid($currentUuid) : null;

        if ($currentRow === null) {
            Session::set('flash_error', 'Could not identify current session; please refresh and try again.');
            return (new Response())->redirect('/me/sessions', 303);
        }

        $count = $this->sessions->revokeAllForUserExcept($uid, (int) $currentRow['id']);
        $msg = $count === 0
            ? 'No other sessions to revoke.'
            : sprintf('Revoked %d other session%s.', $count, $count === 1 ? '' : 's');
        Session::set('flash_success', $msg);
        return (new Response())->redirect('/me/sessions', 303);
    }

    /**
     * Tiny dumb device-label derivation — substring of UA, NO regex parsing
     * (round-3 S3: UA strings lie). Future maintainers shouldn't extract
     * "Chrome on Mac"-style detection; let humans interpret the truncated
     * UA verbatim.
     */
    private static function deviceLabel(string $userAgent): string
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            return 'Unknown device';
        }
        return substr($ua, 0, 80);
    }

    private function requireLogin(): ?int
    {
        $uid = Session::get('user_id');
        if (!is_int($uid) || $uid <= 0) {
            return null;
        }
        return $uid;
    }

    private function redirectToLogin(): Response
    {
        return (new Response())->redirect('/login', 302);
    }
}
