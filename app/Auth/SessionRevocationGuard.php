<?php

declare(strict_types=1);

namespace App\Auth;

use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;

/**
 * v0.5.0 — session revocation after password change (H1 + round-4 BL-1).
 * v0.7.0 — extended to handle per-session revocation + lazy-backfill of
 * user_sessions rows for pre-v0.7.0 sessions (DOCS #62).
 *
 * Sits BETWEEN Session and Csrf in the pipe:
 *
 *   App pipe:   Session → SessionRevocationGuard → Csrf → router
 *
 * TWO predicate paths (executed in this order):
 *
 * (1) v0.7.0 per-session block — runs FIRST so backfill + per-session
 * revoke fire for ALL authed users regardless of password-change state.
 * The v0.7.0 plan's round-2 C2/C4 catch: the v0.5.0 mass-revoke path
 * short-circuits early; if the per-session block ran AFTER it, users
 * with no `user_password_changes` row would never get backfilled.
 *
 *   - $_SESSION['session_uuid'] missing OR no matching user_sessions row?
 *     → lazy backfill (generate uuid, INSERT row)
 *   - matching row, revoked_at NOT NULL?
 *     → destroy session + cookie expire + 302 /login?reason=session_revoked
 *   - matching row, active?
 *     → touch last_used_at, fall through to (2)
 *
 * (2) v0.5.0 mass-revoke block — predicate matrix (round-4 BL-1):
 *   (a) auth_time absent + no row in user_password_changes  → PASS
 *       (pre-v0.5 baseline / legacy session — user decision U-1 grandfathers
 *       this; the security promise activates on each user's first post-deploy
 *       login when establishSession writes auth_time)
 *   (b) auth_time absent + row in user_password_changes     → REVOKE
 *       (legacy session, but the user has changed password since the v0.5
 *       deploy — kick them so they pick up the credential change)
 *   (c) auth_time set    + no row in user_password_changes  → PASS
 *       (modern session, user has never changed password)
 *   (d) both set                                            → COMPARE
 *       (revoke iff auth_time < password_changed_at)
 *
 * BL-2 invariant: when a user changes their own password, the handler pins
 * `$now` once and writes IDENTICAL values to both:
 *   - users.updated_at (and user_password_changes.password_changed_at)
 *   - Session::set('auth_time', $now)
 *
 * The comparison `$authTime < $changedAt` uses string comparison on
 * 'Y-m-d H:i:s' GMT timestamps, which works because the format is
 * lexicographically sortable AND both sides come from gmdate.
 */
final class SessionRevocationGuard
{
    public function __construct(
        private readonly UserPasswordChangeRepository $changes,
        private readonly SessionRepository $sessions,
    ) {}

    public function __invoke(Request $request, callable $next): Response
    {
        if (!Session::has('user_id')) {
            return $next($request);   // anonymous → pass
        }

        $uid = Session::get('user_id');
        if (!is_int($uid) || $uid <= 0) {
            return $next($request);
        }

        // v0.7.0 per-session block — runs FIRST so backfill + per-session
        // revoke fire independently of password-change state.
        $uuid = Session::get('session_uuid');
        $uuid = is_string($uuid) && $uuid !== '' ? $uuid : null;
        $row = $uuid !== null ? $this->sessions->findByUuid($uuid) : null;

        if ($row === null) {
            // Lazy backfill: pre-v0.7.0 session OR session_uuid was set
            // but the row was somehow lost (defensive re-register).
            if ($uuid === null) {
                $uuid = bin2hex(random_bytes(16));
                Session::set('session_uuid', $uuid);
            }
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $this->sessions->register($uid, $uuid, $ua, $ip);
        } else {
            if ($row['revoked_at'] !== null) {
                return $this->bounce($request, 'session_revoked');
            }
            $this->sessions->touch($row['id']);
        }

        // v0.5.0 mass-revoke block.
        $changedAt = $this->changes->changedAt($uid);
        if ($changedAt === null) {
            // (a) + (c) — no password change recorded; nothing to revoke against.
            return $next($request);
        }

        $authTime = Session::get('auth_time');

        // (b): legacy session (no auth_time) AND a password change exists.
        // (d): both set — compare. The comparison is lexicographic on the
        //      'Y-m-d H:i:s' GMT format; both sides come from gmdate so it's safe.
        $shouldRevoke = !is_string($authTime) || $authTime < $changedAt;

        if (!$shouldRevoke) {
            return $next($request);
        }

        return $this->bounce($request, 'password_changed');
    }

    /**
     * Destroy the session, clear the cookie, redirect to /login with a
     * reason flag the login template can surface as a flash. Shared by
     * both the v0.7.0 per-session-revoke path and the v0.5.0 mass-revoke
     * path so the cookie params stay identical to Session::start (Path=/,
     * HttpOnly, SameSite=Lax).
     */
    private function bounce(Request $request, string $reason): Response
    {
        Session::destroy();

        $secure = ($_SERVER['HTTPS'] ?? '') === 'on'
               || $request->header('x-forwarded-proto') === 'https';

        if (PHP_SAPI !== 'cli') {
            setcookie(session_name() ?: 'PHPSESSID', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return (new Response())->redirect('/login?reason=' . $reason, 302);
    }
}
