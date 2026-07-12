<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Household\HouseholdRepository;
use App\Tracker\FoodLogRepository;
use App\Tracker\FoodRepository;
use App\Tracker\FoodServingRepository;
use App\Tracker\LocalDay;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.8.0 — Food logging + live dish search.
 *
 * INTRA-CLASS ROUTE ORDER: literal-segment routes MUST be declared as
 * methods BEFORE any {id} routes in this class or /health/log/food/search
 * gets matched as /health/log/food/{id} with id="search". Karhu's router
 * uses method-declaration order.
 */
final class FoodLogController
{
    /** Valid meal values. DB CHECK constraint mirrors this. */
    private const MEALS = ['breakfast', 'lunch', 'dinner', 'snack'];

    public function __construct(
        private readonly FoodLogRepository $log,
        private readonly FoodRepository $foods,
        private readonly FoodServingRepository $servings,
        private readonly HouseholdRepository $households,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    // --- LITERAL SEGMENTS (must precede {id} routes) ---

    #[Route('/health/log/food', methods: ['GET'], name: 'tracker.log_food.form')]
    public function form(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $meal = (string) ($request->query('meal') ?? 'breakfast');
        if (!in_array($meal, self::MEALS, true)) {
            $meal = 'breakfast';
        }
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/log_food.twig', [
                'meal' => $meal,
                'meals' => self::MEALS,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/health/log/food/search', methods: ['GET'], name: 'tracker.log_food.search')]
    public function search(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $hid] = $ctx;
        $q = (string) ($request->query('q') ?? '');
        $results = $this->foods->search($hid, $q, limit: 20);
        // Reshape into the {results: [{id, name, cuisine_tag, default_serving: {...}}]}
        // contract the layout IIFE reads.
        $out = [];
        foreach ($results as $r) {
            $out[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'cuisine_tag' => $r['cuisine_tag'],
                'default_serving' => [
                    'id' => $r['default_serving_id'],
                    'label' => $r['default_serving_label'],
                    'kcal' => $r['default_serving_kcal'],
                    'grams' => $r['default_serving_grams'],
                ],
            ];
        }
        $body = json_encode(['results' => $out], JSON_THROW_ON_ERROR);
        return (new Response())
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($body);
    }

    #[Route('/health/log/food', methods: ['POST'], name: 'tracker.log_food.store')]
    public function store(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $hid] = $ctx;

        $meal = $request->post('meal');
        $foodId = (int) $request->post('food_id');
        $servingId = (int) $request->post('serving_id');
        $qty = (float) $request->post('qty', '1');

        if (!in_array($meal, self::MEALS, true)) {
            Session::set('flash_error', 'Invalid meal.');
            return (new Response())->redirect('/health/log/food', 303);
        }
        if ($foodId <= 0 || $servingId <= 0 || $qty <= 0) {
            Session::set('flash_error', 'Please pick a dish + serving.');
            return (new Response())->redirect('/health/log/food?meal=' . urlencode($meal), 303);
        }

        // Enforce food + serving visibility to this household (global seed
        // OR own household). Prevents crafted POSTs from logging foreign
        // households' custom dishes.
        $food = $this->foods->findById($foodId);
        $serving = $this->servings->findById($servingId);
        if ($food === null || $serving === null || $serving['food_id'] !== $foodId) {
            Session::set('flash_error', 'Dish not available.');
            return (new Response())->redirect('/health/log/food?meal=' . urlencode($meal), 303);
        }
        if ($food['household_id'] !== null && $food['household_id'] !== $hid) {
            Session::set('flash_error', 'Dish not available.');
            return (new Response())->redirect('/health/log/food?meal=' . urlencode($meal), 303);
        }

        $household = $this->households->findById($hid);
        $tz = new \DateTimeZone((string) $household['timezone']);
        $today = LocalDay::today($tz);
        $kcal = (int) round($qty * (int) $serving['kcal']);

        $this->log->create($hid, $userId, $foodId, $servingId, $qty, $meal, $today, $kcal);

        Session::set('flash_success', 'Logged ' . $food['name'] . '.');
        return (new Response())->redirect('/health', 303);
    }

    // --- {id} ROUTES (must come after literals) ---

    #[Route('/health/log/food/{id}/delete', methods: ['POST'], name: 'tracker.log_food.delete')]
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
            // Silently redirect — either not-owned or already-deleted.
            Session::set('flash_error', 'Not your entry to remove.');
        } else {
            Session::set('flash_success', 'Removed.');
        }
        return (new Response())->redirect('/health', 303);
    }

    // --- helpers ---

    /**
     * @return Response|array{0: int, 1: int} guard-response OR [$userId, $hid]
     */
    private function requireContext(): Response|array
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
        return [$userId, $hid];
    }
}
