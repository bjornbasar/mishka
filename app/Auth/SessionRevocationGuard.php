<?php

declare(strict_types=1);

namespace App\Auth;

use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;

/**
 * v0.5.0 — session revocation after password change (H1 + round-4 BL-1).
 *
 * Sits BETWEEN Session and Csrf in the pipe:
 *
 *   App pipe:   Session → SessionRevocationGuard → Csrf → router
 *
 * Predicate matrix (round-4 BL-1):
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
 * lexicographically sortable AND both sides come from gmdate. If we ever
 * compared a TIMESTAMPTZ-typed value from PG ('2026-05-29 04:00:00+00') to
 * a PHP-formatted one ('2026-05-29 04:00:00'), the +00 suffix would break
 * the comparison. UserPasswordChangeRepository::stamp always uses the
 * pinned PHP `$now` string, never PG's NOW() default — see BL-2 docs.
 */
final class SessionRevocationGuard
{
    public function __construct(private readonly UserPasswordChangeRepository $changes) {}

    public function __invoke(Request $request, callable $next): Response
    {
        if (!Session::has('user_id')) {
            return $next($request);   // anonymous → pass
        }

        $uid = Session::get('user_id');
        if (!is_int($uid) || $uid <= 0) {
            return $next($request);
        }

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

        // Bounce: destroy the session, clear the cookie, redirect to /login
        // with a reason flag the login template can surface as a flash.
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

        return (new Response())->redirect('/login?reason=password_changed', 302);
    }
}
