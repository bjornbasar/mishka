<?php

declare(strict_types=1);

namespace App\Auth;

use Karhu\Db\Connection;

/**
 * v0.5.0 — session-revocation stamp for SessionRevocationGuard (H1 + BL-1).
 *
 * Presence of a row + the `password_changed_at` value is the predicate the
 * middleware uses to invalidate stale sessions. Comparison is `Session::get(
 * 'auth_time') < password_changed_at` — sessions issued before the latest
 * stamp get bounced to /login?reason=password_changed.
 *
 * Lives in a separate table (not as `users.password_changed_at`) because the
 * schema is additive-only — no `ALTER TABLE users ADD COLUMN` across releases.
 * v0.1 reserved `email_verified_at` ahead of v0.5, but no slot was reserved
 * for this credential-change stamp.
 *
 * BL-2: the handler is the source-of-truth for the timestamp value — pinned
 * ONCE in AccountController::handlePasswordPost() and passed to both this
 * repo and Session::set('auth_time', $now). The repo NEVER calls gmdate()
 * itself for the stamp; that would reintroduce the microsecond-drift bug.
 *
 * Permutation matrix (BL-1, in the middleware):
 *   (a) row absent + auth_time absent  → PASS  (pre-v0.5 baseline)
 *   (b) row present + auth_time absent → REVOKE (legacy session post-pw-change)
 *   (c) row absent + auth_time present → PASS  (new session, no pw change yet)
 *   (d) both present                   → COMPARE: revoke if auth_time < stamp
 */
final class UserPasswordChangeRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Returns the user's last password-change timestamp (as the SQL TIMESTAMPTZ
     * string), or null if they've never changed their password.
     */
    public function changedAt(int $userId): ?string
    {
        $value = $this->db->fetchScalar(
            'SELECT password_changed_at FROM user_password_changes WHERE user_id = :uid',
            ['uid' => $userId],
        );

        return $value === null || $value === false ? null : (string) $value;
    }

    /**
     * Upsert the stamp. Caller MUST pass the pinned `$now` (BL-2) so the
     * password-write timestamp and the session's new auth_time are bit-for-bit
     * identical — otherwise microsecond drift between two gmdate() calls in
     * the same handler can cause the user to self-revoke on a slow request.
     */
    public function stamp(int $userId, string $now): void
    {
        // PG + SQLite 3.24+ both support ON CONFLICT DO UPDATE (mirrors
        // UserPreferenceRepository::setLastHouseholdId).
        $this->db->run(
            'INSERT INTO user_password_changes (user_id, password_changed_at, updated_at)
             VALUES (:uid, :now, :now)
             ON CONFLICT (user_id) DO UPDATE
             SET password_changed_at = EXCLUDED.password_changed_at,
                 updated_at = EXCLUDED.updated_at',
            ['uid' => $userId, 'now' => $now],
        );
    }
}
