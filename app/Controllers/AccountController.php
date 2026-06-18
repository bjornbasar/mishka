<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Account\AccountEmailFlow;
use App\Auth\MishkaUserRepository;
use App\Auth\SystemRoleRepository;
use App\Household\HouseholdRepository;
use App\Mail\Mailer;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Auth\PasswordHasher;
use Karhu\Db\Connection;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Csrf;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.5.0+ — the `/me/*` account self-service endpoints.
 *
 * Flows:
 *   - GET/POST /me/profile               — edit display_name
 *   - GET/POST /me/password              — change password with current-pw confirmation
 *   - GET/POST /me/email                 — request email change (v0.6.11)
 *   - GET/POST /me/email-change/{token}  — confirm + apply swap (v0.6.11)
 *
 * Locked behaviours:
 *   - All `/me/*` endpoints (except the token-bearing confirm route) require
 *     an authed session; anonymous → 302 /login.
 *   - Password change ALWAYS calls PasswordHasher::verify against the current
 *     hash, even on validation failure (M1 — closes a timing oracle for
 *     password-length / current-password probing). The /me/email POST mirrors
 *     this for current_password re-auth (v0.6.11).
 *   - On password-change success, `$now = gmdate('Y-m-d H:i:s')` is pinned ONCE
 *     and reused for both updatePassword's stamp AND Session::set('auth_time')
 *     (round-4 BL-2 — prevents self-revoke via SessionRevocationGuard).
 *   - Session::regenerate() + Csrf::regenerate() fire on PASSWORD success (M4 + H-7).
 *   - Email change is NOT a credential change: NO Session::regenerate(), NO
 *     user_password_changes write. Only Csrf::regenerate() (defence in depth).
 *     This is the v0.6.11 invariant — SessionRevocationGuard must not fire on
 *     email change (decision #52). Test `test_post_email_change_does_NOT_write_user_password_changes_row`
 *     is the regression guard.
 */
final class AccountController
{
    private const DISPLAY_NAME_MAX = 120;
    private const PASSWORD_MIN = 12;
    private const PASSWORD_MAX = 128;
    // v0.6.11
    private const NEW_EMAIL_MAX = 320;
    private const TOKEN_REGEX = '/^[0-9a-f]{64}$/';
    private const RATE_LIMIT_REQUESTS = 3;
    private const RATE_LIMIT_WINDOW_MIN = 10;
    // v0.6.12
    private const CONFIRM_EMAIL_MAX = 320;

    public function __construct(
        private readonly MishkaUserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly TwigAdapter $view,
        private readonly NavContext $nav,
        private readonly Mailer $mailer,
        private readonly Connection $db,
        // v0.6.12 — account-delete deps
        private readonly HouseholdRepository $households,
        // v0.6.19 — admin-presence + audit deps
        private readonly SystemRoleRepository $systemRoles,
        // v0.6.20 — email-change-specific deps bundled (DOCS #61)
        private readonly AccountEmailFlow $emailFlow,
    ) {}

    // ============================================================
    // /me/profile — display_name edit
    // ============================================================

    #[Route('/me/profile', methods: ['GET'], name: 'me.profile')]
    public function showProfile(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        $user = $this->users->findById($uid);
        if ($user === null) {
            // Authed session but the user row was deleted — corrupt state.
            // Logout this session and bounce to /login.
            Session::destroy();
            return $this->redirectToLogin();
        }

        return $this->renderProfile($user['display_name'], errors: []);
    }

    #[Route('/me/profile', methods: ['POST'])]
    public function handleProfilePost(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        $displayName = trim((string) $this->readInputField($request, 'display_name'));
        $errors = $this->validateDisplayName($displayName);

        if ($errors !== []) {
            return $this->renderProfile($displayName, $errors, status: 422);
        }

        $this->users->updateDisplayName($uid, $displayName);
        Session::set('flash_success', 'Display name updated.');

        return (new Response())->redirect('/me/profile', 303);
    }

    // ============================================================
    // /me/password — current-pw + new-pw change
    // ============================================================

    #[Route('/me/password', methods: ['GET'], name: 'me.password')]
    public function showPassword(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        return $this->renderPassword(errors: []);
    }

    #[Route('/me/password', methods: ['POST'])]
    public function handlePasswordPost(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        $current = (string) $this->readInputField($request, 'current_password');
        $new = (string) $this->readInputField($request, 'new_password');
        $confirm = (string) $this->readInputField($request, 'new_password_confirm');

        $user = $this->users->findById($uid);
        if ($user === null) {
            Session::destroy();
            return $this->redirectToLogin();
        }

        // M1: ALWAYS call $hasher->verify regardless of subsequent validation
        // outcome — even if the new password is malformed, we still consume
        // the argon2id verify cost so a passive observer can't distinguish
        // "wrong current pw" from "malformed new pw" by timing.
        $currentMatches = $this->hasher->verify($current, $user['password_hash']);

        $errors = $this->validatePasswordChange($current, $new, $confirm, $currentMatches);

        if ($errors !== []) {
            return $this->renderPassword($errors, status: 422);
        }

        // BL-2: pin $now ONCE. Reuse for the stamp + the session's new
        // auth_time so SessionRevocationGuard does NOT bounce the user on the
        // very next request (auth_time === password_changed_at; the predicate
        // `auth_time < password_changed_at` is false → pass).
        $now = gmdate('Y-m-d H:i:s');

        $newHash = $this->hasher->hash($new);
        $this->users->updatePassword($uid, $newHash, $now);

        // Rotate the session ID (defence against fixation post-credential-change)
        // and bind the new auth_time + CSRF token to the rotated session.
        Session::regenerate();
        Session::set('auth_time', $now);
        Csrf::regenerate();

        // Preserve the user's identity in the rotated session.
        // Session::regenerate() rotates the ID; karhu keeps $_SESSION intact,
        // but we re-write the critical keys defensively in case future karhu
        // versions clear them.
        Session::set('user_id', $uid);
        Session::set('username', $user['email']);
        Session::set('roles', $user['roles']);
        Session::set('flash_success', 'Password updated.');

        return (new Response())->redirect('/me/profile', 303);
    }

    // ============================================================
    // Render + validation helpers
    // ============================================================

    /**
     * @param list<string> $errors
     */
    private function renderProfile(string $displayName, array $errors, int $status = 200): Response
    {
        $uid = $this->requireLogin();
        return (new Response($status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('account/profile.twig', [
                'errors' => $errors,
                'display_name' => $displayName,
                // v0.6.19 — gates the /me/admin/promote link in profile.twig
                'is_system_admin' => $uid !== null && $this->systemRoles->isSystemAdmin($uid),
            ] + $this->nav->forCurrentUser()));
    }

    /**
     * @param list<string> $errors
     */
    private function renderPassword(array $errors, int $status = 200): Response
    {
        return (new Response($status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('account/password.twig', [
                'errors' => $errors,
            ] + $this->nav->forCurrentUser()));
    }

    /** @return list<string> */
    private function validateDisplayName(string $displayName): array
    {
        $errors = [];
        if ($displayName === '') {
            $errors[] = 'Display name is required.';
        } elseif (strlen($displayName) > self::DISPLAY_NAME_MAX) {
            $errors[] = 'Display name is too long (max ' . self::DISPLAY_NAME_MAX . ' characters).';
        }
        return $errors;
    }

    /** @return list<string> */
    private function validatePasswordChange(
        string $current,
        string $new,
        string $confirm,
        bool $currentMatches,
    ): array {
        $errors = [];

        if (!$currentMatches) {
            // Generic copy — never disclose whether the "current" was empty
            // vs wrong vs short. Single message keeps the timing path simple.
            $errors[] = 'Current password is incorrect.';
        }

        $newLen = strlen($new);
        if ($new === '') {
            $errors[] = 'New password is required.';
        } elseif ($newLen < self::PASSWORD_MIN) {
            $errors[] = 'New password must be at least ' . self::PASSWORD_MIN . ' characters.';
        } elseif ($newLen > self::PASSWORD_MAX) {
            $errors[] = 'New password must be at most ' . self::PASSWORD_MAX . ' characters.';
        }

        if ($new !== '' && $new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }

        // Don't let the user re-set the same password — defeats the purpose
        // of a change. Compared in constant time via hash_equals to avoid a
        // timing oracle on the "same-as-current" check.
        if ($currentMatches && $new !== '' && hash_equals($current, $new)) {
            $errors[] = 'New password must be different from current.';
        }

        return $errors;
    }

    /** @return string */
    private function readInputField(Request $request, string $field): string
    {
        $body = $request->body();
        if (is_array($body) && isset($body[$field]) && is_string($body[$field])) {
            return $body[$field];
        }
        return $request->post($field);
    }

    /** Returns the logged-in user's id, or null if anonymous. */
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

    // ============================================================
    // v0.6.11 — /me/email + /me/email-change/{token}
    // ============================================================

    #[Route('/me/email', methods: ['GET'], name: 'me.email')]
    public function showEmail(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        $user = $this->users->findById($uid);
        if ($user === null) {
            Session::destroy();
            return $this->redirectToLogin();
        }

        return $this->renderEmail($user['email'], newEmail: '', errors: []);
    }

    #[Route('/me/email', methods: ['POST'])]
    public function handleEmailPost(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }

        $user = $this->users->findById($uid);
        if ($user === null) {
            Session::destroy();
            return $this->redirectToLogin();
        }

        $newEmail = strtolower(trim((string) $this->readInputField($request, 'new_email')));
        $currentPassword = (string) $this->readInputField($request, 'current_password');

        // M1: ALWAYS verify current_password even if the new email is malformed
        // — same timing-oracle defence as /me/password.
        $currentMatches = $this->hasher->verify($currentPassword, $user['password_hash']);

        $errors = $this->validateEmailChange($newEmail, $user['email'], $uid, $currentMatches);

        if ($errors !== []) {
            return $this->renderEmail($user['email'], $newEmail, $errors, status: 422);
        }

        // App-layer rate limit (H4 analogue). Pre-record so this attempt
        // counts toward the cap (matches PasswordResetController pattern).
        $this->emailFlow->attempts->record('change_email_request', null, $uid);
        $recentCount = $this->emailFlow->attempts->countRecentByUser(
            'change_email_request',
            $uid,
            self::RATE_LIMIT_WINDOW_MIN,
        );
        if ($recentCount > self::RATE_LIMIT_REQUESTS) {
            Session::set('flash_error', 'Too many email-change requests — try again later.');
            return $this->renderEmail($user['email'], $newEmail, errors: [], status: 429);
        }

        // Issue token; find back the row id for markSent later.
        $rawToken = $this->emailFlow->changeTokens->issue($uid, $newEmail);
        $tokenRow = $this->emailFlow->changeTokens->findByRawToken($rawToken);
        if ($tokenRow === null) {
            // Should never happen: we just issued. Defensive log + flash.
            error_log('account: just-issued change-token not findable for uid=' . $uid);
            Session::set('flash_error', 'Something went wrong — try again.');
            return $this->renderEmail($user['email'], $newEmail, errors: [], status: 500);
        }

        $confirmUrl = $this->emailFlow->urls->absoluteUrl('/me/email-change/' . $rawToken);

        // Asymmetric mailer behaviour (decision #52): the confirm link is the
        // load-bearing send — surface flash_error on failure, do NOT call
        // markSent, do NOT send the old-mailbox notification (notifying about
        // a change the user never got a way to complete is just noise).
        $confirmSent = $this->mailer->sendEmailChange($newEmail, $confirmUrl, $user['display_name']);
        if (!$confirmSent) {
            Session::set('flash_error', "Couldn't send the confirmation email — try again.");
            return $this->renderEmail($user['email'], $newEmail, errors: [], status: 500);
        }

        $this->emailFlow->changeTokens->markSent($tokenRow['id']);

        // Old-mailbox security alert is fire-and-forget — defence in depth.
        // Mailer logs on failure; we ignore the return value.
        $this->mailer->sendEmailChangeNotification(
            $user['email'],
            $this->maskEmail($newEmail),
            $user['display_name'],
        );

        Session::set('flash_success',
            "Confirmation email sent to {$newEmail}. Click the link to finish — the change won't apply until you do.");
        return (new Response())->redirect('/me/email', 303);
    }

    #[Route('/me/email-change/{token}', methods: ['GET'])]
    public function showEmailChangeConfirm(Request $request): Response
    {
        $token = (string) ($request->routeParams()['token'] ?? '');
        if (!preg_match(self::TOKEN_REGEX, $token)) {
            return $this->renderEmailChangeInvalid();
        }

        $row = $this->emailFlow->changeTokens->findByRawToken($token);
        if ($row === null || $row['used_at'] !== null || $this->isExpired($row['expires_at'])) {
            return $this->renderEmailChangeInvalid();
        }

        $user = $this->users->findById($row['user_id']);
        if ($user === null) {
            return $this->renderEmailChangeInvalid();
        }

        return $this->renderEmailChangeConfirm($token, $user['email'], $row['new_email']);
    }

    #[Route('/me/email-change/{token}', methods: ['POST'])]
    public function handleEmailChangeConfirm(Request $request): Response
    {
        $token = (string) ($request->routeParams()['token'] ?? '');
        if (!preg_match(self::TOKEN_REGEX, $token)) {
            return $this->renderEmailChangeInvalid();
        }

        $row = $this->emailFlow->changeTokens->findByRawToken($token);
        if ($row === null || $row['used_at'] !== null || $this->isExpired($row['expires_at'])) {
            return $this->renderEmailChangeInvalid();
        }

        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        try {
            // Single-use redemption guard. False → token race lost (another
            // request claimed it) or expired between findByRawToken and now.
            if (!$this->emailFlow->changeTokens->redeemAtomically($row['id'])) {
                if ($started) {
                    $pdo->rollBack();
                }
                return $this->renderEmailChangeInvalid();
            }

            // applyEmailSwap throws PDOException on UNIQUE conflict.
            try {
                $this->users->applyEmailSwap($row['user_id'], $row['new_email']);
            } catch (\PDOException $e) {
                // CRITICAL: rollBack MUST be the first action — PG aborts the
                // entire txn on UNIQUE violation; subsequent statements on the
                // same connection fail with 25P02 in_failed_sql_transaction
                // (round-2 C4). The inTransaction() guard handles the edge
                // case where PG already auto-rolled (rollBack would throw).
                // @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse
                if ($started && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($this->isUniqueViolation($e)) {
                    return $this->renderEmailChangeConflict();
                }
                throw $e;
            }

            // Invalidate any pending password-reset + email-verification tokens
            // for this user — old-mailbox-issued tokens must not be usable
            // post-swap (mailbox-compromise hardening, decision #52).
            $this->emailFlow->resetTokens->invalidatePendingForUser($row['user_id']);
            $this->emailFlow->verifyTokens->invalidatePendingForUser($row['user_id']);

            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            // Defensive — PG may have auto-rolled the txn on the inner error.
            // @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Session refresh if the redeeming browser holds this user's session
        // (per round-2 R5 / decision #52). Cross-device case: anonymous browser
        // clicks the link, applies the swap, no session writes.
        $sessUid = Session::get('user_id');
        if (is_int($sessUid) && $sessUid === $row['user_id']) {
            Session::set('username', $row['new_email']);
            // Truthiness marker only — NavContext checks not-null for the
            // verify banner; the canonical column value is CURRENT_TIMESTAMP
            // applied inside the swap (matches EmailVerificationController:80).
            Session::set('email_verified_at', gmdate('Y-m-d H:i:s\Z'));
            // Defence in depth — NOT a credential rotation event, but the
            // cached CSRF token's session lifetime is fine to rotate here.
            Csrf::regenerate();
        }

        Session::set('flash_success', "Email updated to {$row['new_email']}.");
        return (new Response())->redirect('/me/profile', 303);
    }

    // ------------------------------------------------------------
    // /me/email render + validation helpers
    // ------------------------------------------------------------

    /**
     * @param list<string> $errors
     */
    private function renderEmail(string $currentEmail, string $newEmail, array $errors, int $status = 200): Response
    {
        return (new Response($status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('account/email.twig', [
                'errors' => $errors,
                'current_email' => $currentEmail,
                'new_email' => $newEmail,
            ] + $this->nav->forCurrentUser()));
    }

    private function renderEmailChangeConfirm(string $token, string $oldEmail, string $newEmail): Response
    {
        return (new Response(200))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            // Decision #44: token-bearing GET routes set Referrer-Policy: no-referrer
            // so the raw token doesn't leak via Referer if the user navigates away.
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody($this->view->render('account/email_change_confirm.twig', [
                'token' => $token,
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
            ] + $this->nav->forCurrentUser()));
    }

    private function renderEmailChangeInvalid(): Response
    {
        return (new Response(404))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody($this->view->render('account/email_change_invalid.twig',
                $this->nav->forCurrentUser()));
    }

    private function renderEmailChangeConflict(): Response
    {
        return (new Response(422))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody($this->view->render('account/email_change_conflict.twig',
                $this->nav->forCurrentUser()));
    }

    /** @return list<string> */
    private function validateEmailChange(
        string $newEmail,
        string $currentEmail,
        int $uid,
        bool $currentMatches,
    ): array {
        $errors = [];

        if (!$currentMatches) {
            $errors[] = 'Current password is incorrect.';
        }

        if ($newEmail === '') {
            $errors[] = 'New email is required.';
        } elseif (strlen($newEmail) > self::NEW_EMAIL_MAX) {
            $errors[] = 'New email is too long (max ' . self::NEW_EMAIL_MAX . ' characters).';
        } elseif (filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'That doesn\'t look like a valid email address.';
        } elseif ($newEmail === strtolower(trim($currentEmail))) {
            $errors[] = 'New email must be different from your current email.';
        } else {
            $takenBy = $this->users->findIdByEmail($newEmail);
            if ($takenBy !== null && $takenBy !== $uid) {
                $errors[] = 'That email is already in use by another account.';
            }
        }

        return $errors;
    }

    /**
     * Returns true if the PDOException is a UNIQUE-constraint violation.
     * Inline rather than shared (decision #47's inline-not-helper rule;
     * 3rd callsite — promote to a trait at the 5th).
     */
    private function isUniqueViolation(\PDOException $e): bool
    {
        // PG SQLSTATE 23505 (unique_violation). SQLite returns 23000 with
        // the driver-specific UNIQUE phrase in the message.
        if ($e->getCode() === '23505') {
            return true;
        }
        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'UNIQUE')) {
            return true;
        }
        return false;
    }

    /**
     * Mask an email for the OLD-mailbox security alert. Per round-2 S3:
     *   "ab@example.com" → "a***@example.com"
     *   "a@example.com"  → "*@example.com"
     *   "@example.com"   → "***@example.com"   (empty local — defensive)
     *   "noat"           → "***"                (no `@` at all — defensive)
     *
     * The mask is a UX hint, not a precise leak-prevention primitive; the
     * OLD mailbox is the leakage surface anyway.
     */
    private function maskEmail(string $email): string
    {
        $atPos = strpos($email, '@');
        if ($atPos === false) {
            return '***';
        }
        $local = substr($email, 0, $atPos);
        $domain = substr($email, $atPos);   // includes the '@'
        $len = strlen($local);
        if ($len >= 2) {
            return $local[0] . '***' . $domain;
        }
        if ($len === 1) {
            return '*' . $domain;
        }
        return '***' . $domain;
    }

    /** Compare against now() in PHP land — TTL math is all in PHP per B3. */
    private function isExpired(string $expiresAt): bool
    {
        $exp = strtotime($expiresAt);
        if ($exp === false) {
            return true;   // unparseable → treat as expired (defensive)
        }
        return $exp <= time();
    }

    // ============================================================
    // v0.6.12 — /me/delete (account deletion)
    // ============================================================

    #[Route('/me/delete', methods: ['GET'], name: 'me.delete')]
    public function showDelete(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }
        $user = $this->users->findById($uid);
        if ($user === null) {
            Session::destroy();
            return $this->redirectToLogin();
        }
        return $this->renderDelete($user, errors: []);
    }

    #[Route('/me/delete', methods: ['POST'])]
    public function handleDelete(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }
        $user = $this->users->findById($uid);
        if ($user === null) {
            Session::destroy();
            return $this->redirectToLogin();
        }

        // Normalise input at the controller boundary (round-2 C6) — same
        // strtolower+trim normalisation that MishkaUserRepository::normaliseEmail
        // applies to the DB-side. Without this, hash_equals against the
        // canonical column value would case-mismatch on uppercase input.
        $confirmEmail = strtolower(trim((string) $this->readInputField($request, 'confirm_email')));
        $currentPassword = (string) $this->readInputField($request, 'current_password');

        // M1 always-verify: hasher::verify regardless of subsequent validation
        // outcomes, defends against password-length / "is this my current pw"
        // timing oracle. Same pattern as handlePasswordPost + handleEmailPost.
        $currentMatches = $this->hasher->verify($currentPassword, $user['password_hash']);

        // Owned-households pre-check (read-only, runs OUTSIDE the txn).
        $ownedCount = $this->households->countOwnedByUser($uid);

        // v0.6.19 — admin-presence pre-check. If this user is the only system
        // admin, the FK CASCADE on system_roles would leave the system
        // admin-less post-delete (DOCS #53 v0.6.13-candidate gap). Block with
        // a 422 + escape-hatch link to /me/admin/promote (rendered as a
        // separate Twig block to avoid HTML-in-error-string XSS — round-2 C4).
        $onlyAdmin = $this->systemRoles->countSystemAdmins() === 1
            && $this->systemRoles->isSystemAdmin($uid);

        $errors = $this->validateDelete($confirmEmail, $user['email'], $currentMatches, $ownedCount, $onlyAdmin);

        if ($errors !== []) {
            return $this->renderDelete($user, $errors, status: 422, onlyAdmin: $onlyAdmin);
        }

        // Snapshot user-facing fields BEFORE delete so the courtesy email can
        // use them. $deletedAt as explicit-UTC literal (decision #50 pattern)
        // so the email body's timestamp is unambiguous (R22).
        $toEmail = $user['email'];
        $displayName = $user['display_name'];
        $deletedAt = gmdate('Y-m-d H:i:s\Z');

        // Single transaction wrapping the destructive DELETE. The 12-table
        // CASCADE chain + 7 SET NULL columns all fire atomically. Nested-txn
        // guard so an outer caller's txn isn't double-committed.
        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            // v0.6.19 — write user_deletions audit row INSIDE the txn,
            // BEFORE the user DELETE. The membership list must be SELECTed
            // before delete because household_members.user_id CASCADEs.
            // sort() ensures deterministic JSON for test assertions
            // (round-2 M5).
            $householdIds = array_column($this->households->listForUser($uid), 'id');
            sort($householdIds);
            $this->db->run(
                'INSERT INTO user_deletions (user_id, deleted_at, household_ids)
                 VALUES (:uid, :deleted_at, :household_ids)',
                [
                    'uid' => $uid,
                    'deleted_at' => $deletedAt,
                    'household_ids' => json_encode($householdIds, JSON_THROW_ON_ERROR),
                ],
            );
            $this->users->delete($uid);
            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            // @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Fire-and-forget courtesy email. Mailer::send catches SMTP failure
        // internally and returns false; we ignore the return value because
        // the user row is already gone (no path to surface a flash to a
        // user that no longer exists).
        $this->mailer->sendAccountDeletedNotification($toEmail, $displayName, $deletedAt);

        // Wipe the session AND explicitly clear the session cookie (round-2 C1
        // — karhu's Session::destroy() wipes $_SESSION but does NOT touch the
        // browser cookie; without the setcookie() call, the next request would
        // try to re-attach to a now-dead session id). Mirrors AuthController's
        // logout flow.
        Session::destroy();
        if (PHP_SAPI !== 'cli') {
            $name = session_name() ?: 'PHPSESSID';
            setcookie($name, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return (new Response())->redirect('/login?deleted=1', 302);
    }

    // ------------------------------------------------------------
    // /me/delete render + validation helpers
    // ------------------------------------------------------------

    /**
     * @param array{id: int, email: string, display_name: string, password_hash: string,
     *              roles: list<string>} $user
     * @param list<string> $errors
     */
    private function renderDelete(array $user, array $errors, int $status = 200, bool $onlyAdmin = false): Response
    {
        return (new Response($status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('account/delete.twig', [
                'errors' => $errors,
                'current_email' => $user['email'],
                'display_name' => $user['display_name'],
                'owned_households' => $this->households->listOwnedByUser($user['id']),
                // v0.6.19 — separate flag (NOT in errors list) so the template
                // can render a real <a href="/me/admin/promote"> instead of
                // letting Twig auto-escape an HTML-in-string (round-2 C4 XSS).
                'only_admin' => $onlyAdmin,
            ] + $this->nav->forCurrentUser()));
    }

    /** @return list<string> */
    private function validateDelete(
        string $confirmEmail,
        string $currentEmail,
        bool $currentMatches,
        int $ownedCount,
        bool $onlyAdmin = false,
    ): array {
        $errors = [];
        if (!$currentMatches) {
            $errors[] = 'Current password is incorrect.';
        }
        if ($confirmEmail === '') {
            $errors[] = 'Type your email to confirm deletion.';
        } elseif (strlen($confirmEmail) > self::CONFIRM_EMAIL_MAX) {
            $errors[] = 'Confirmation email is too long.';
        } elseif (!hash_equals(strtolower(trim($currentEmail)), $confirmEmail)) {
            $errors[] = 'Confirmation email does not match your current email.';
        }
        if ($ownedCount > 0) {
            $errors[] = 'You own ' . $ownedCount . ' household'
                . ($ownedCount === 1 ? '' : 's')
                . '. Transfer ownership or delete '
                . ($ownedCount === 1 ? 'it' : 'them')
                . ' first.';
        }
        if ($onlyAdmin) {
            $errors[] = 'You are the only system administrator.';
        }
        return $errors;
    }

    // ============================================================
    // v0.6.19 — /me/admin/promote (system-admin escape hatch)
    // ============================================================
    //
    // GET renders the form (dropdown of candidate users).
    // POST grants 'admin' to the selected user via SystemRoleRepository.
    // Auth: existing system admin only (403 with templates/account/
    // forbidden.twig for non-admins). Anonymous → 302 /login per existing
    // AccountController convention. Promote-only semantics: the granter
    // keeps their admin role; the receiving user gains admin. The only-
    // admin can then self-delete via /me/delete (CASCADE removes their
    // admin row).

    #[Route('/me/admin/promote', methods: ['GET'], name: 'me.admin.promote')]
    public function showPromoteAdmin(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }
        if (!$this->systemRoles->isSystemAdmin($uid)) {
            return $this->renderForbidden();
        }
        return $this->renderPromoteAdmin($uid, errors: []);
    }

    #[Route('/me/admin/promote', methods: ['POST'])]
    public function handlePromoteAdmin(Request $request): Response
    {
        $uid = $this->requireLogin();
        if ($uid === null) {
            return $this->redirectToLogin();
        }
        if (!$this->systemRoles->isSystemAdmin($uid)) {
            return $this->renderForbidden();
        }

        $targetId = (int) $this->readInputField($request, 'target_user_id');
        $target = $targetId > 0 ? $this->users->findById($targetId) : null;
        if ($target === null || $targetId === $uid) {
            return $this->renderPromoteAdmin($uid, ['Invalid target user.'], status: 422);
        }

        $this->systemRoles->grantSystemAdmin($targetId);
        Session::set('flash_success', 'Granted admin role to ' . $target['display_name'] . '.');
        return (new Response())->redirect('/me/profile', 303);
    }

    /**
     * @param list<string> $errors
     */
    private function renderPromoteAdmin(int $uid, array $errors, int $status = 200): Response
    {
        return (new Response($status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('account/admin_promote.twig', [
                'errors' => $errors,
                'candidates' => $this->systemRoles->listPromotionCandidates($uid),
                'is_only_admin' => $this->systemRoles->countSystemAdmins() === 1,
            ] + $this->nav->forCurrentUser()));
    }

    private function renderForbidden(): Response
    {
        return (new Response(403))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('account/forbidden.twig', $this->nav->forCurrentUser()));
    }
}
