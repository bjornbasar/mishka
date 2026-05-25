<?php

declare(strict_types=1);

namespace App\Auth;

use App\Household\HouseholdRepository;
use Karhu\Error\ForbiddenException;
use Karhu\Middleware\Session;

/**
 * Membership / ownership gates for household-scoped routes.
 *
 * Controllers call $this->auth->requireMember(...) or requireOwner(...) at the
 * top of every household-scoped handler. Failures throw karhu v0.1.1's
 * ForbiddenException, which the framework's ExceptionHandler renders as:
 *   - $redirectTo = '/household/setup' → 302 redirect (stale-session recovery)
 *   - $redirectTo = null               → 403 (foreign-household access)
 *
 * Self-healing: when a user's session says they're active in household X but
 * they're no longer a member (e.g., they got kicked), requireMember clears the
 * stale active_household_id keys before throwing, so the redirect lands cleanly.
 */
final class HouseholdAuthorizer
{
    public function __construct(private readonly HouseholdRepository $households) {}

    /**
     * Throws ForbiddenException if $userId is not a member of $householdId.
     *
     * If the failing household matches the user's session active_household_id,
     * the stale keys are cleared and the exception carries redirectTo=/household/setup
     * so the framework redirects rather than 403-ing.
     */
    public function requireMember(int $userId, int $householdId): void
    {
        if ($this->households->isMember($userId, $householdId)) {
            return;
        }

        if (Session::has('active_household_id') && (int) Session::get('active_household_id') === $householdId) {
            Session::forget('active_household_id');
            Session::forget('active_household_role');
            throw new ForbiddenException(
                'session references a household you no longer belong to',
                redirectTo: '/household/setup',
            );
        }

        throw new ForbiddenException('not a member of this household');
    }

    /**
     * Throws ForbiddenException if $userId is not the owner of $householdId.
     *
     * Non-owners (including foreign users entirely) get a plain 403.
     * Use this on owner-only routes: rename, kick member, etc.
     */
    public function requireOwner(int $userId, int $householdId): void
    {
        if (!$this->households->isOwner($userId, $householdId)) {
            throw new ForbiddenException('owner access required');
        }
    }
}
