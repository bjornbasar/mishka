<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Account\UserPreferenceRepository;
use App\Auth\HouseholdAuthorizer;
use App\Household\HouseholdRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Csrf;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * Household onboarding + settings.
 *
 * Five routes:
 *   GET  /household/setup              — Create / Join form (redirects to /household
 *                                        if the user already has an active household)
 *   POST /household/setup              — create OR join, sets active session keys,
 *                                        writes user_preferences.last_household_id
 *   GET  /household                    — settings (members roster; owner sees code
 *                                        + rename form + kick buttons)
 *   POST /household/rename             — owner-only (HouseholdAuthorizer::requireOwner)
 *   POST /household/members/{userId}/remove — owner-only; blocks owner + self
 *   POST /household/switch             — set active household to one the user is
 *                                        a member of; persists in user_preferences
 *
 * The HouseholdAuthorizer throws Karhu\Error\ForbiddenException on rejection;
 * karhu v0.1.1's ExceptionHandler renders it as 302-redirect or 403 per the
 * exception's $redirectTo field. No try/catch needed here.
 */
final class HouseholdController
{
    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly UserPreferenceRepository $prefs,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/household/setup', methods: ['GET'], name: 'household.setup')]
    public function showSetup(Request $request): Response
    {
        if (!Session::has('user_id')) {
            return (new Response())->redirect('/login', 302);
        }
        // If they already have an active household, bounce to /household — visiting
        // /household/setup directly is almost always a back-button mishap.
        if (Session::has('active_household_id')) {
            return (new Response())->redirect('/household', 302);
        }

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('household/setup.twig', [
                'errors' => [],
                'old' => [],
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/household/setup', methods: ['POST'])]
    public function handleSetup(Request $request): Response
    {
        if (!Session::has('user_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $userId = (int) Session::get('user_id');

        $input = $this->readBody($request);
        $action = $input['action'] ?? '';
        $name = trim($input['name'] ?? '');
        $joinCode = trim($input['join_code'] ?? '');
        $errors = [];

        if ($action === 'create') {
            if ($name === '') {
                $errors[] = 'Household name is required.';
            } elseif (mb_strlen($name) > 120) {
                $errors[] = 'Household name is too long (max 120 characters).';
            }
            if ($errors === []) {
                $hid = $this->households->createForOwner($name, $userId);
                $this->activateHousehold($userId, $hid, 'owner');
                return (new Response())->redirect('/', 303);
            }
        } elseif ($action === 'join') {
            if ($joinCode === '') {
                $errors[] = 'Join code is required.';
            }
            if ($errors === []) {
                $household = $this->households->findByJoinCode($joinCode);
                if ($household === null) {
                    $errors[] = 'No household found for that code.';
                } else {
                    $this->households->addMember($household['id'], $userId);
                    $this->activateHousehold($userId, $household['id'], 'member');
                    return (new Response())->redirect('/', 303);
                }
            }
        } else {
            $errors[] = 'Choose whether to create or join a household.';
        }

        return (new Response(422))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('household/setup.twig', [
                'errors' => $errors,
                'old' => ['action' => $action, 'name' => $name, 'join_code' => $joinCode],
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/household', methods: ['GET'], name: 'household.index')]
    public function showHousehold(Request $request): Response
    {
        if (!Session::has('user_id')) {
            return (new Response())->redirect('/login', 302);
        }
        if (!Session::has('active_household_id')) {
            return (new Response())->redirect('/household/setup', 302);
        }

        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');

        // requireMember throws ForbiddenException (with redirectTo='/household/setup')
        // if the session points at a household the user has been kicked from.
        $this->auth->requireMember($userId, $hid);

        $isOwner = $this->households->isOwner($userId, $hid);
        $household = $this->households->findById($hid);

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('household/index.twig', [
                'members' => $this->households->listMembers($hid),
                'invite_code' => $isOwner ? ($household['join_code'] ?? null) : null,
                'can_manage' => $isOwner,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/household/rename', methods: ['POST'])]
    public function handleRename(Request $request): Response
    {
        if (!Session::has('user_id') || !Session::has('active_household_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');

        $this->auth->requireOwner($userId, $hid);

        $name = trim($this->readBody($request)['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody('Invalid household name (1-120 characters).');
        }

        $this->households->rename($hid, $name);
        return (new Response())->redirect('/household', 303);
    }

    #[Route('/household/members/{userId}/remove', methods: ['POST'])]
    public function handleRemoveMember(Request $request): Response
    {
        if (!Session::has('user_id') || !Session::has('active_household_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $actingId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');

        $this->auth->requireOwner($actingId, $hid);

        $targetId = (int) ($request->routeParams()['userId'] ?? 0);
        if ($targetId === $actingId) {
            // Defensive: the template doesn't render a "remove self" button, but
            // an owner submitting one manually would land here. HouseholdRepository
            // already blocks removing an owner — owners can't kick themselves.
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody('Owners cannot remove themselves.');
        }

        // HouseholdRepository::removeMember throws if target is an owner (v0.2
        // invariant: exactly one owner per household, never transferable).
        $this->households->removeMember($hid, $targetId);

        return (new Response())->redirect('/household', 303);
    }

    #[Route('/household/switch', methods: ['POST'])]
    public function handleSwitch(Request $request): Response
    {
        if (!Session::has('user_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $userId = (int) Session::get('user_id');
        $newHid = (int) ($this->readBody($request)['household_id'] ?? 0);

        // requireMember throws ForbiddenException → 403 (or 302 with redirectTo
        // if the session active_household_id matches — but for switch flows
        // it'll be plain 403 because they're targeting a foreign household).
        $this->auth->requireMember($userId, $newHid);

        $role = $this->households->isOwner($userId, $newHid) ? 'owner' : 'member';
        $this->activateHousehold($userId, $newHid, $role);

        return (new Response())->redirect('/', 303);
    }

    // ============================================================
    // v0.5.0 — household lifecycle (regenerate / leave / transfer / delete)
    // ============================================================

    #[Route('/household/regenerate-code', methods: ['POST'])]
    public function handleRegenerateCode(Request $request): Response
    {
        if (!Session::has('user_id') || !Session::has('active_household_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');

        $this->auth->requireOwner($userId, $hid);
        $this->households->regenerateJoinCode($hid);

        Session::set('flash_success', 'Invite code regenerated. The old code no longer works.');
        return (new Response())->redirect('/household', 303);
    }

    #[Route('/household/leave', methods: ['POST'])]
    public function handleLeave(Request $request): Response
    {
        if (!Session::has('user_id') || !Session::has('active_household_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');

        // Anyone in the household (owner OR member) lands here. Owners get a
        // 422 with helpful copy — they must transfer ownership or delete the
        // household first. removeMember is the single source-of-truth for the
        // "owners can't leave" invariant (it throws); we surface the rule.
        $this->auth->requireMember($userId, $hid);

        if ($this->households->isOwner($userId, $hid)) {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody('Owners cannot leave a household. Transfer ownership first or delete the household.');
        }

        $this->households->removeMember($hid, $userId);

        // Drop the active-household session state so the next request goes
        // through the post-leave landing logic (either another household, or
        // /household/setup if this was the only one).
        Session::forget('active_household_id');
        Session::forget('active_household_role');

        // Pick a fallback household if the user has others; otherwise bounce
        // to /household/setup.
        $other = $this->households->listForUser($userId);
        if ($other !== []) {
            $fallback = $other[0];
            $this->activateHousehold($userId, $fallback['id'], $fallback['role']);
            return (new Response())->redirect('/household', 303);
        }
        return (new Response())->redirect('/household/setup', 303);
    }

    #[Route('/household/transfer', methods: ['POST'])]
    public function handleTransfer(Request $request): Response
    {
        if (!Session::has('user_id') || !Session::has('active_household_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $actingId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');

        $this->auth->requireOwner($actingId, $hid);

        $newOwnerId = (int) ($this->readBody($request)['new_owner_user_id'] ?? 0);

        // Defensive: must be a non-owner member of THIS household.
        // The repo's transferOwnership re-verifies inside the locked txn
        // (BL-3), but a friendly 422 here saves the round-trip on the common
        // "target was kicked just now" UI race.
        if ($newOwnerId <= 0 || !$this->households->isMember($newOwnerId, $hid)) {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody('Pick a current member of this household to transfer ownership to.');
        }
        if ($this->households->isOwner($newOwnerId, $hid)) {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody('Target is already the owner.');
        }

        try {
            $this->households->transferOwnership($hid, $actingId, $newOwnerId);
        } catch (\RuntimeException $e) {
            // Race: target was kicked or owner status changed between the
            // check above and the FOR UPDATE lock inside the repo. Return
            // 422 rather than 500 — the user can simply retry.
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody('Could not transfer ownership: ' . htmlspecialchars($e->getMessage()));
        }

        // The acting user is now a 'member' for this household — bump session.
        Session::set('active_household_role', 'member');
        // Defence in depth — rotate the CSRF token after a privilege change (M4).
        Csrf::regenerate();
        Session::set('flash_success', 'Household ownership transferred.');

        return (new Response())->redirect('/household', 303);
    }

    #[Route('/household/delete', methods: ['POST'])]
    public function handleDelete(Request $request): Response
    {
        if (!Session::has('user_id') || !Session::has('active_household_id')) {
            return (new Response())->redirect('/login', 302);
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');

        $this->auth->requireOwner($userId, $hid);

        $household = $this->households->findById($hid);
        if ($household === null) {
            // Race: already deleted. Clear session keys and bounce.
            Session::forget('active_household_id');
            Session::forget('active_household_role');
            return (new Response())->redirect('/', 303);
        }

        $confirmName = trim((string) ($this->readBody($request)['confirm_name'] ?? ''));
        if (!hash_equals($household['name'], $confirmName)) {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody('Type the household name exactly to confirm deletion. Nothing was deleted.');
        }

        $this->households->delete($hid);

        Session::forget('active_household_id');
        Session::forget('active_household_role');
        Csrf::regenerate();
        Session::set('flash_success', 'Household deleted. All its chores, events, and history are gone.');

        return (new Response())->redirect('/', 303);
    }

    /**
     * Set the active-household session keys + persist last_household_id.
     * Called from setup (create/join) and switch.
     */
    private function activateHousehold(int $userId, int $householdId, string $role): void
    {
        Session::set('active_household_id', $householdId);
        Session::set('active_household_role', $role);
        $this->prefs->setLastHouseholdId($userId, $householdId);
    }

    /**
     * Read POST body from either JSON (test harness) or form-urlencoded (browser).
     * Mirrors AuthController's readInput() pattern.
     *
     * @return array<string, string>
     */
    private function readBody(Request $request): array
    {
        $body = $request->body();
        $bodyArr = is_array($body) ? $body : [];

        $out = [];
        // v0.5.0: extend the allowlist with new_owner_user_id (transfer) +
        // confirm_name (delete). The whitelist gate keeps surprise inputs out.
        foreach (
            ['action', 'name', 'join_code', 'household_id', 'new_owner_user_id', 'confirm_name']
            as $key
        ) {
            $val = $bodyArr[$key] ?? null;
            if (is_scalar($val)) {
                $out[$key] = (string) $val;
            } else {
                $out[$key] = $request->post($key);
            }
        }
        return $out;
    }
}
