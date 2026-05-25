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
     *     active_household: array{id: int, name: string, join_code: string, timezone: string, created_at: string}|null
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

        return [
            'session_email' => is_string($email) ? $email : null,
            'households' => $memberships,
            'active_household' => $active,
        ];
    }
}
