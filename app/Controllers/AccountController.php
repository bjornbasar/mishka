<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\MishkaUserRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Auth\PasswordHasher;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Csrf;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.5.0 — the `/me/*` account self-service endpoints.
 *
 * Two flows:
 *   - GET/POST /me/profile       — edit display_name (email change deferred to v0.6+)
 *   - GET/POST /me/password      — change password with current-pw confirmation
 *
 * Locked behaviours:
 *   - All endpoints require an authed session; anonymous → 302 /login.
 *   - Password change ALWAYS calls PasswordHasher::verify against the current
 *     hash, even on validation failure (M1 — closes a timing oracle for
 *     password-length / current-password probing).
 *   - On success, `$now = gmdate('Y-m-d H:i:s')` is pinned ONCE and reused for
 *     both updatePassword's stamp AND Session::set('auth_time') (round-4 BL-2
 *     prevents self-revoke via the SessionRevocationGuard).
 *   - Session::regenerate() + Csrf::regenerate() fire on success (M4 + H-7).
 */
final class AccountController
{
    private const DISPLAY_NAME_MAX = 120;
    private const PASSWORD_MIN = 12;
    private const PASSWORD_MAX = 128;

    public function __construct(
        private readonly MishkaUserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly TwigAdapter $view,
        private readonly NavContext $nav,
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
        return (new Response($status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('account/profile.twig', [
                'errors' => $errors,
                'display_name' => $displayName,
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
}
