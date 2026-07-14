<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Household\HouseholdRepository;
use App\Tracker\ExerciseKcalCalculator;
use App\Tracker\ExerciseLogRepository;
use App\Tracker\ExerciseRepository;
use App\Tracker\LoggedOnValidator;
use App\Tracker\TrackerBadgeAwarder;
use App\Tracker\WeightLogRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.8.1 — Exercise logging + live search.
 *
 * INTRA-CLASS ROUTE ORDER: literal-segment routes MUST be declared as
 * methods BEFORE any {id} routes in this class. See DOCS #70 v0.8.0
 * intra-class ordering rule.
 *
 * Discriminated union by exercise type — reads the picked exercise's
 * `type` from the DB (not from the form) to route validation + kcal
 * computation.
 */
final class ExerciseLogController
{
    public function __construct(
        private readonly ExerciseLogRepository $log,
        private readonly ExerciseRepository $exercises,
        private readonly WeightLogRepository $weights,
        private readonly HouseholdRepository $households,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
        private readonly TrackerBadgeAwarder $trackerAwards,
    ) {}

    // --- LITERAL SEGMENTS (must precede {id} routes) ---

    #[Route('/health/log/exercise', methods: ['GET'], name: 'tracker.log_exercise.form')]
    public function form(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/log_exercise.twig', [] + $this->nav->forCurrentUser()));
    }

    #[Route('/health/log/exercise/search', methods: ['GET'], name: 'tracker.log_exercise.search')]
    public function search(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $hid] = $ctx;
        $q = (string) $request->query('q');
        $results = $this->exercises->search($hid, $q, limit: 20);
        // Distinct exercise-shape payload (NOT null-shimmed food shape).
        $out = [];
        foreach ($results as $r) {
            $out[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'type' => $r['type'],
                'met' => $r['met'],
                'default_rom_m' => $r['default_rom_m'],
            ];
        }
        return (new Response())
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody(json_encode(['results' => $out], JSON_THROW_ON_ERROR));
    }

    #[Route('/health/log/exercise', methods: ['POST'], name: 'tracker.log_exercise.store')]
    public function store(Request $request): Response
    {
        $ctx = $this->requireContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $hid] = $ctx;

        $exerciseId = (int) $request->post('exercise_id');
        if ($exerciseId <= 0) {
            return $this->rejectStore($request, 'Please pick an exercise.', '/health/log/exercise');
        }
        $exercise = $this->exercises->findById($exerciseId);
        if ($exercise === null) {
            return $this->rejectStore($request, 'Exercise not found.', '/health/log/exercise');
        }
        // Household visibility — global seed OR own household only.
        if ($exercise['household_id'] !== null && $exercise['household_id'] !== $hid) {
            return $this->rejectStore($request, 'Exercise not available.', '/health/log/exercise');
        }

        $type = $exercise['type'];  // 'duration' | 'strength' — server-side, not form-side
        $met = (float) $exercise['met'];
        $rom = $exercise['default_rom_m'] !== null ? (float) $exercise['default_rom_m'] : null;

        $household = $this->households->findById($hid);
        $tz = new \DateTimeZone((string) $household['timezone']);
        // v0.8.4 — accept optional client-stamped logged_on (offline replay).
        try {
            $loggedOn = LoggedOnValidator::parse($request->post('logged_on'), $tz);
        } catch (\InvalidArgumentException $e) {
            return $this->rejectStore($request, 'Bad date: ' . $e->getMessage(), '/health/log/exercise');
        }

        if ($type === 'duration') {
            $minutes = (float) $request->post('minutes');
            if ($minutes <= 0) {
                return $this->rejectStore($request, 'Please enter a positive number of minutes.', '/health/log/exercise');
            }
            $latestWeight = $this->weights->latestForUser($userId);
            $weightKg = $latestWeight !== null ? (float) $latestWeight['weight_kg'] : null;
            $metMinutes = ExerciseKcalCalculator::metMinutes($met, $minutes);
            $kcal = ExerciseKcalCalculator::durationKcal($met, $minutes, $weightKg);

            $this->log->create(
                $hid, $userId, $exerciseId,
                'duration', (string) $exercise['name'],
                minutes: $minutes, sets: null, reps: null, loadKg: null,
                metMinutes: $metMinutes, kcalSnapshot: $kcal,
                loggedOn: $loggedOn,
            );
        } else {
            // strength branch
            $sets = (int) $request->post('sets');
            $reps = (int) $request->post('reps');
            $loadKgRaw = $request->post('load_kg');
            $loadKg = $loadKgRaw !== '' ? (float) $loadKgRaw : null;
            if ($sets <= 0 || $reps <= 0) {
                return $this->rejectStore($request, 'Please enter positive sets and reps.', '/health/log/exercise');
            }
            $kcal = $loadKg !== null
                ? ExerciseKcalCalculator::mechanicalWorkKcal($loadKg, $rom, $reps)
                : null;

            $this->log->create(
                $hid, $userId, $exerciseId,
                'strength', (string) $exercise['name'],
                minutes: null, sets: $sets, reps: $reps, loadKg: $loadKg,
                metMinutes: null, kcalSnapshot: $kcal,
                loggedOn: $loggedOn,
            );
        }

        // v0.8.3 — best-effort badge award. Mirrors ChoresController::handleDone
        // L320-346 posture: catch \Throwable + error_log; a badge-eval failure
        // must NEVER 500 the log-write. Defensive TZ fallback for corrupted
        // households.timezone rows. Idempotent by BadgeAwardRepository UNIQUE
        // dedup — safe to fire on v0.8.4 offline-replay POSTs.
        try {
            $awarderTz = new \DateTimeZone((string) ($household['timezone'] ?? 'Pacific/Auckland'));
            $this->trackerAwards->evaluateAndGrant($hid, $userId, $awarderTz, new \DateTimeImmutable('now'));
        } catch (\Throwable $e) {
            error_log('tracker-award failure: ' . $e->getMessage());
        }

        if ($this->wantsJson($request)) {
            return (new Response())
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withBody(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));
        }
        Session::set('flash_success', 'Logged ' . $exercise['name'] . '.');
        return (new Response())->redirect('/health', 303);
    }

    // --- {id} ROUTES (declared AFTER literals) ---

    #[Route('/health/log/exercise/{id}/delete', methods: ['POST'], name: 'tracker.log_exercise.delete')]
    public function delete(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId] = $ctx;
        $id = (int) ($request->routeParams()['id'] ?? 0);
        if ($id <= 0) {
            return (new Response(404))->withBody('Not found');
        }
        $affected = $this->log->deleteOwnedById($id, $userId);
        if ($affected === 0) {
            Session::set('flash_error', 'Not your entry to remove.');
        } else {
            Session::set('flash_success', 'Removed.');
        }
        return (new Response())->redirect('/health', 303);
    }

    /** @return Response|array{0: int, 1: int} */
    private function requireContext(?Request $request = null): Response|array
    {
        if (!Session::has('user_id')) {
            if ($request !== null && $this->wantsJson($request)) {
                return $this->jsonError(401, 'auth', 'Session required.');
            }
            return (new Response())->redirect('/login', 302);
        }
        if (!Session::has('active_household_id')) {
            if ($request !== null && $this->wantsJson($request)) {
                return $this->jsonError(401, 'auth', 'Active household required.');
            }
            return (new Response())->redirect('/household/setup', 302);
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);
        return [$userId, $hid];
    }

    /** v0.8.4 — detect JSON caller (offline replay) vs HTML form submit. */
    private function wantsJson(Request $request): bool
    {
        return str_contains(strtolower((string) $request->header('accept')), 'application/json');
    }

    /** v0.8.4 — validation-reject helper; branches on wantsJson. */
    private function rejectStore(Request $request, string $message, string $htmlRedirect): Response
    {
        if ($this->wantsJson($request)) {
            return $this->jsonError(400, 'validation', $message);
        }
        Session::set('flash_error', $message);
        return (new Response())->redirect($htmlRedirect, 303);
    }

    /** v0.8.4 — JSON error response builder. */
    private function jsonError(int $status, string $code, string $message): Response
    {
        return (new Response($status))
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(json_encode(['status' => 'error', 'code' => $code, 'message' => $message], JSON_THROW_ON_ERROR));
    }
}
