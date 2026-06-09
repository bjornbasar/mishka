<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Chores\BadgeAwardRepository;
use App\Household\HouseholdRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.6.13 — /badges page.
 *
 * Two sections:
 *   1. Per-user grid: all 6 badges (config/badges.php). Earned ones show
 *      "Earned {date}"; locked ones show their title-line criterion.
 *   2. Household roster: every OTHER member's badge count + emoji row.
 *
 * Auth triad mirrors ChoresController::resolveChore: requires login + active
 * household + member-of-active-household. Anonymous → /login. Missing
 * active_household → /household/setup. Non-member kicked by
 * HouseholdAuthorizer::requireMember (which self-heals the stale-session
 * case by clearing active_household_id and redirecting to /).
 */
final class BadgesController
{
    public function __construct(
        private readonly BadgeAwardRepository $awards,
        private readonly HouseholdRepository $households,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/badges', methods: ['GET'], name: 'badges.show')]
    public function show(Request $request): Response
    {
        $uid = Session::get('user_id');
        if (!is_int($uid) || $uid <= 0) {
            return (new Response())->redirect('/login', 302);
        }
        $hid = Session::get('active_household_id');
        if (!is_int($hid) || $hid <= 0) {
            return (new Response())->redirect('/household/setup', 302);
        }
        $this->auth->requireMember($uid, $hid);

        $earned = $this->awards->listForUser($hid, $uid);
        $earnedCodes = array_column($earned, 'badge_code');
        $allCodes = array_keys(require __DIR__ . '/../../config/badges.php');

        // Roster: every household member EXCEPT the calling user.
        // listMembers already excludes sentinel (u.id > 0); we filter self.
        $rosterMembers = array_values(array_filter(
            $this->households->listMembers($hid),
            static fn(array $m): bool => (int) $m['user_id'] !== $uid,
        ));
        $codesByUser = $this->awards->listByUserForHousehold($hid);
        $countsByUser = $this->awards->countsByUserForHousehold($hid);
        $roster = [];
        foreach ($rosterMembers as $m) {
            $memberUid = (int) $m['user_id'];
            $roster[] = [
                'user_id' => $memberUid,
                'display_name' => (string) $m['display_name'],
                'email' => (string) $m['email'],
                'badge_count' => $countsByUser[$memberUid] ?? 0,
                'badge_codes' => $codesByUser[$memberUid] ?? [],
            ];
        }

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('badges/show.twig', [
                'earned' => $earned,
                'earned_codes' => $earnedCodes,
                'all_codes' => $allCodes,
                'roster' => $roster,
            ] + $this->nav->forCurrentUser()));
    }
}
