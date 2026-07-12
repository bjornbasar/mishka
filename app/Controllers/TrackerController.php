<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Household\HouseholdRepository;
use App\Tracker\ExerciseLogRepository;
use App\Tracker\FoodLogRepository;
use App\Tracker\LocalDay;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.8.0 — Tracker "Today" dashboard.
 * v0.8.1 — extended with exercise log entries below meals.
 *
 * Renders today's food + exercise log for the current user. Placeholder
 * energy-balance widget lands in v0.8.2 (with tracker_profiles + BMR);
 * v0.8.3 adds the household leaderboard from met_minutes sums.
 */
final class TrackerController
{
    public function __construct(
        private readonly FoodLogRepository $log,
        private readonly ExerciseLogRepository $exerciseLog,
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

        $entries = $this->log->listForUserDay($userId, $hid, $today);

        // Group by meal for the template. Preserve meal order.
        $byMeal = ['breakfast' => [], 'lunch' => [], 'dinner' => [], 'snack' => []];
        $dayTotal = 0;
        foreach ($entries as $e) {
            $byMeal[$e['meal']][] = $e;
            $dayTotal += $e['kcal_snapshot'];
        }

        // v0.8.1 — exercise entries logged today, chronological.
        $exerciseEntries = $this->exerciseLog->listForUserDay($userId, $hid, $today);

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/today.twig', [
                'meal_groups' => $byMeal,
                'day_total' => $dayTotal,
                'exercise_entries' => $exerciseEntries,
                'today' => $today,
                'household' => $household,
            ] + $this->nav->forCurrentUser()));
    }
}
