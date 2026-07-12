<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Tracker\FoodRepository;
use App\Tracker\FoodServingRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Db\Connection;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.8.0 — Dish library browse + CRUD.
 *
 * INTRA-CLASS ROUTE ORDER: /health/foods/new must be declared as a method
 * BEFORE /health/foods/{id} or it gets matched as {id}="new". Karhu's
 * router uses method-declaration order.
 *
 * "At least one serving per food" is enforced here (create + update
 * reject empty servings arrays). The DB has no NOT NULL constraint on
 * "food has a serving" — the search endpoint's INNER JOIN silently drops
 * default-less dishes, so a controller-side reject at write time is the
 * user-facing gate.
 */
final class FoodLibraryController
{
    public function __construct(
        private readonly FoodRepository $foods,
        private readonly FoodServingRepository $servings,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
        private readonly Connection $db,
    ) {}

    // --- LITERAL SEGMENTS FIRST ---

    #[Route('/health/foods', methods: ['GET'], name: 'tracker.foods.index')]
    public function index(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $hid] = $ctx;
        $rows = $this->foods->listForHousehold($hid);
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/foods_index.twig', [
                'foods' => $rows,
                'household_id' => $hid,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/health/foods/new', methods: ['GET'], name: 'tracker.foods.new')]
    public function createForm(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/food_form.twig', [
                'food' => null,
                'servings' => [],
                'errors' => [],
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/health/foods', methods: ['POST'], name: 'tracker.foods.store')]
    public function store(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $hid] = $ctx;

        $name = trim($request->post('name'));
        $aliases = $request->post('aliases');
        $cuisineTag = $request->post('cuisine_tag');
        $servingLabel = trim($request->post('serving_label'));
        $servingGrams = (float) $request->post('serving_grams', '0');
        $servingKcal = (int) $request->post('serving_kcal', '0');

        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 200) {
            $errors[] = 'Name is too long (max 200 chars).';
        }
        if ($servingLabel === '' || $servingGrams <= 0 || $servingKcal < 0) {
            $errors[] = 'A first serving (label + grams + kcal) is required.';
        }
        if ($errors !== []) {
            $old = ['name' => $name, 'aliases' => $aliases, 'cuisine_tag' => $cuisineTag];
            return $this->renderForm(null, $old, $errors);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $foodId = $this->foods->create($hid, [
                'name' => $name,
                'aliases' => $aliases !== '' ? $aliases : null,
                'cuisine_tag' => $cuisineTag !== '' ? $cuisineTag : null,
                'source' => 'custom',
            ], $userId);
            $this->servings->create($foodId, [
                'label' => $servingLabel,
                'grams' => $servingGrams,
                'kcal' => $servingKcal,
                'is_default' => true,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Session::set('flash_success', 'Added ' . $name . ' to the library.');
        return (new Response())->redirect('/health/foods', 303);
    }

    // --- {id} ROUTES (declared AFTER literals) ---

    #[Route('/health/foods/{id}', methods: ['GET'], name: 'tracker.foods.edit')]
    public function edit(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $id = (int) ($request->routeParams()['id'] ?? 0);
        $food = $this->foods->findById($id);
        if ($food === null) {
            return (new Response(404))->withBody('Not found');
        }
        $servings = $this->servings->listForFood($id);
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/food_form.twig', [
                'food' => $food,
                'servings' => $servings,
                'errors' => [],
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/health/foods/{id}', methods: ['POST'], name: 'tracker.foods.update')]
    public function update(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $id = (int) ($request->routeParams()['id'] ?? 0);
        $food = $this->foods->findById($id);
        if ($food === null) {
            return (new Response(404))->withBody('Not found');
        }
        $name = trim($request->post('name'));
        $aliases = $request->post('aliases');
        $cuisineTag = $request->post('cuisine_tag');

        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 200) {
            $errors[] = 'Name is too long (max 200 chars).';
        }
        if ($errors !== []) {
            $old = ['name' => $name, 'aliases' => $aliases, 'cuisine_tag' => $cuisineTag];
            return $this->renderForm($food, $old, $errors);
        }
        $this->foods->update($id, [
            'name' => $name,
            'aliases' => $aliases !== '' ? $aliases : null,
            'cuisine_tag' => $cuisineTag !== '' ? $cuisineTag : null,
        ]);
        Session::set('flash_success', 'Updated.');
        return (new Response())->redirect('/health/foods', 303);
    }

    #[Route('/health/foods/{id}/delete', methods: ['POST'], name: 'tracker.foods.delete')]
    public function delete(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $id = (int) ($request->routeParams()['id'] ?? 0);
        $food = $this->foods->findById($id);
        if ($food === null) {
            return (new Response(404))->withBody('Not found');
        }
        $this->foods->delete($id);
        Session::set('flash_success', 'Removed from library.');
        return (new Response())->redirect('/health/foods', 303);
    }

    // --- helpers ---

    /**
     * @param array<string, mixed>|null $food previously-loaded food row (edit mode)
     * @param array<string, mixed>      $body user-supplied form values for repopulation
     * @param list<string>              $errors
     */
    private function renderForm(?array $food, array $body, array $errors): Response
    {
        return (new Response(422))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/food_form.twig', [
                'food' => $food,
                'servings' => [],
                'errors' => $errors,
                'old' => $body,
            ] + $this->nav->forCurrentUser()));
    }

    /** @return Response|array{0: int, 1: int} */
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
