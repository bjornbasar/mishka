<?php

declare(strict_types=1);

namespace App\View;

use App\Household\HouseholdRepository;
use Karhu\Middleware\Session;

/**
 * Shared layout context — every controller that renders a layout-using template
 * merges this in so the nav, footer, and brand bits get consistent state.
 *
 *     $this->view->render($t, $data + $this->nav->forCurrentUser());
 *
 * Single source of truth for the three things layout.twig depends on:
 *   - session_email: the logged-in user's email, or null if anonymous
 *   - households: all households the user belongs to (drives the switcher dropdown)
 *   - active_household: the full row for the currently-active household, or null
 *
 * `active_household` is fetched FRESH against the session's active_household_id —
 * if the user was kicked since login, findById might return a household they're no
 * longer a member of, OR the session id might point at a deleted household. The
 * HomeController's guard (`if (active_household === null) redirect /household/setup`)
 * is what catches both cases; NavContext just supplies the data.
 */
final class NavContext
{
    public function __construct(private readonly HouseholdRepository $households) {}

    /**
     * @return array{
     *     session_email: string|null,
     *     households: list<array{id: int, name: string, role: string, joined_at: string}>,
     *     active_household: array{id: int, name: string, join_code: string, timezone: string, created_at: string}|null,
     *     verify_required: bool,
     *     flash: string|null
     * }
     */
    public function forCurrentUser(): array
    {
        $userId = Session::get('user_id');
        if (!is_int($userId) || $userId <= 0) {
            return [
                'session_email' => null,
                'households' => [],
                'active_household' => null,
                'verify_required' => false,
                'flash' => $this->takeFlash(),
            ];
        }

        $email = Session::get('username');
        $memberships = $this->households->listForUser($userId);

        $active = null;
        $activeId = Session::get('active_household_id');
        if (is_int($activeId) && $activeId > 0) {
            // Only treat the session id as live if the user is actually still a member.
            // This dodges the stale-session case (user kicked between login and this request).
            foreach ($memberships as $m) {
                if ($m['id'] === $activeId) {
                    $active = $this->households->findById($activeId);
                    break;
                }
            }
        }

        // v0.5.0 (H5): verify_required derived from session-cached
        // email_verified_at — avoids 1 extra SELECT per render.
        // Single-copy banner (decision U-3) shows for ANY logged-in user whose
        // email_verified_at is NULL, regardless of SMTP success/failure.
        $verifiedAt = Session::get('email_verified_at');
        $verifyRequired = is_string($email) && $email !== '' && $verifiedAt === null;

        return [
            'session_email' => is_string($email) ? $email : null,
            'households' => $memberships,
            'active_household' => $active,
            'verify_required' => $verifyRequired,
            'flash' => $this->takeFlash(),
        ];
    }

    /**
     * Read-and-clear the one-shot flash message. Both 'flash_success' and
     * 'flash_error' keys collapse into the layout's single `flash` slot.
     */
    private function takeFlash(): ?string
    {
        $success = Session::get('flash_success');
        $error = Session::get('flash_error');
        // Clear both regardless of which fired so a write followed by a
        // re-render doesn't loop.
        if ($success !== null) {
            Session::forget('flash_success');
        }
        if ($error !== null) {
            Session::forget('flash_error');
        }
        if (is_string($success)) {
            return $success;
        }
        if (is_string($error)) {
            return $error;
        }
        return null;
    }
}
