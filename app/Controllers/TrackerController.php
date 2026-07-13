<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Household\HouseholdRepository;
use App\Tracker\BmrCalculator;
use App\Tracker\ExerciseLogRepository;
use App\Tracker\FoodLogRepository;
use App\Tracker\LocalDay;
use App\Tracker\TrackerProfileRepository;
use App\Tracker\WeightLogRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.8.0 — Tracker "Today" dashboard.
 * v0.8.1 — extended with exercise log entries below meals.
 * v0.8.2 — added the energy-balance widget at the top of Today (net =
 *          intake − expenditure). Three states: profile-missing CTA,
 *          weight-missing CTA, or the balance numbers. Precedence:
 *          profile missing WINS over weight missing (avoids fresh-user
 *          record-weight-then-still-see-CTA loop).
 *
 * Widget privacy invariant: per-user scoping via
 * FoodLogRepository::intakeKcalForUserDay +
 * ExerciseLogRepository::exerciseKcalForUserDay + WeightLogRepository::
 * latestForUser (user_id scoped). No cross-user leak — regression tested
 * in TrackerControllerTest::test_today_does_not_leak_other_users_...
 * See DOCS #72.
 */
final class TrackerController
{
    public function __construct(
        private readonly FoodLogRepository $log,
        private readonly ExerciseLogRepository $exerciseLog,
        private readonly TrackerProfileRepository $profiles,
        private readonly WeightLogRepository $weights,
        private readonly HouseholdRepository $households,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/health', methods: ['GET'], name: 'tracker.today')]
    public function today(Request $request): Response
    {
        if (!Session::has('user_id')) {
            return (new Response())->redirect('/login', 302);
        }
        if (!Session::has('active_household_id')) {
            return (new Response())->redirect('/household/setup', 302);
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);

        $household = $this->households->findById($hid);
        $tz = new \DateTimeZone((string) $household['timezone']);
        $today = LocalDay::today($tz);

        // Meal-grouped food log (v0.8.0).
        $entries = $this->log->listForUserDay($userId, $hid, $today);
        $byMeal = ['breakfast' => [], 'lunch' => [], 'dinner' => [], 'snack' => []];
        $dayTotal = 0;
        foreach ($entries as $e) {
            $byMeal[$e['meal']][] = $e;
            $dayTotal += $e['kcal_snapshot'];
        }

        // Exercise log (v0.8.1).
        $exerciseEntries = $this->exerciseLog->listForUserDay($userId, $hid, $today);

        // v0.8.2 balance widget — fork by completeness.
        $balance = $this->computeBalance($userId, $hid, $today);

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/today.twig', [
                'meal_groups' => $byMeal,
                'day_total' => $dayTotal,
                'exercise_entries' => $exerciseEntries,
                'balance' => $balance,
                'today' => $today,
                'household' => $household,
            ] + $this->nav->forCurrentUser()));
    }

    /**
     * v0.8.2 — Today energy-balance widget state.
     *
     * State precedence (fresh user has neither profile nor weight;
     * profile CTA MUST win — otherwise a redirect-to-weight-then-back
     * leaves the user in a CTA loop):
     *   1. profile missing  → 'needs_profile' (regardless of weight)
     *   2. profile present + weight missing → 'needs_weight'
     *   3. both present → 'complete' with intake/expenditure/net numbers
     *
     * @return array{state: string, intake?: int, expenditure?: int, net?: int, bmr?: int, activity_delta?: int, exercise?: int, base_activity?: string}
     */
    private function computeBalance(int $userId, int $householdId, string $today): array
    {
        $profile = $this->profiles->findByUserId($userId);
        if ($profile === null) {
            return ['state' => 'needs_profile'];
        }
        $latestWeight = $this->weights->latestForUser($userId);
        if ($latestWeight === null) {
            return ['state' => 'needs_weight'];
        }
        $bmr = BmrCalculator::calculate(
            (string) $profile['sex'],
            (int) $profile['birth_year'],
            (float) $profile['height_cm'],
            (float) $latestWeight['weight_kg'],
        );
        if ($bmr === null) {
            // Defence-in-depth — BmrCalculator returns null on implausible
            // age. Fresh users shouldn't hit this; treat as needs_profile.
            return ['state' => 'needs_profile'];
        }
        $baseActivity = (float) $profile['base_activity'];
        $activityDelta = (int) round($bmr * ($baseActivity - 1.0));
        $exercise = $this->exerciseLog->exerciseKcalForUserDay($userId, $householdId, $today);
        $expenditure = $bmr + $activityDelta + $exercise;
        $intake = $this->log->intakeKcalForUserDay($userId, $householdId, $today);

        return [
            'state' => 'complete',
            'intake' => $intake,
            'expenditure' => $expenditure,
            'net' => $intake - $expenditure,
            'bmr' => $bmr,
            'activity_delta' => $activityDelta,
            'exercise' => $exercise,
            'base_activity' => (string) $profile['base_activity'],
        ];
    }
}
