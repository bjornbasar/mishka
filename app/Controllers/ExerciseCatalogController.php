<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Tracker\ExerciseRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.8.1 — exercise catalog browse + CRUD (mirrors FoodLibraryController).
 *
 * INTRA-CLASS ROUTE ORDER: /health/exercises/new declared as a method
 * BEFORE /health/exercises/{id}. Karhu's router uses method-declaration
 * order.
 */
final class ExerciseCatalogController
{
    public function __construct(
        private readonly ExerciseRepository $exercises,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    // --- LITERAL SEGMENTS FIRST ---

    #[Route('/health/exercises', methods: ['GET'], name: 'tracker.exercises.index')]
    public function index(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $hid] = $ctx;
        $rows = $this->exercises->listForHousehold($hid);
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/exercises_index.twig', [
                'exercises' => $rows,
                'household_id' => $hid,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/health/exercises/new', methods: ['GET'], name: 'tracker.exercises.new')]
    public function createForm(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/exercise_form.twig', [
                'exercise' => null,
                'errors' => [],
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/health/exercises', methods: ['POST'], name: 'tracker.exercises.store')]
    public function store(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $hid] = $ctx;

        $name = trim($request->post('name'));
        $type = $request->post('type');
        $metRaw = $request->post('met', '0');
        $romRaw = $request->post('default_rom_m');

        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (!in_array($type, ['duration', 'strength'], true)) {
            $errors[] = 'Type must be duration or strength.';
        }
        $met = (float) $metRaw;
        if ($met <= 0 || $met > 25) {
            $errors[] = 'MET must be in (0, 25].';
        }
        if ($errors !== []) {
            return $this->renderForm(null, ['name' => $name, 'type' => $type, 'met' => $metRaw, 'default_rom_m' => $romRaw], $errors);
        }

        $this->exercises->create($hid, [
            'name' => $name,
            'type' => $type,
            'met' => $met,
            'default_rom_m' => $romRaw !== '' ? (float) $romRaw : null,
            'source' => 'custom',
        ], $userId);
        Session::set('flash_success', 'Added ' . $name . '.');
        return (new Response())->redirect('/health/exercises', 303);
    }

    // --- {id} ROUTES (declared AFTER literals) ---

    #[Route('/health/exercises/{id}', methods: ['GET'], name: 'tracker.exercises.edit')]
    public function edit(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $id = (int) ($request->routeParams()['id'] ?? 0);
        $exercise = $this->exercises->findById($id);
        if ($exercise === null) {
            return (new Response(404))->withBody('Not found');
        }
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/exercise_form.twig', [
                'exercise' => $exercise,
                'errors' => [],
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/health/exercises/{id}', methods: ['POST'], name: 'tracker.exercises.update')]
    public function update(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $id = (int) ($request->routeParams()['id'] ?? 0);
        $exercise = $this->exercises->findById($id);
        if ($exercise === null) {
            return (new Response(404))->withBody('Not found');
        }
        $name = trim($request->post('name'));
        $type = $request->post('type');
        $metRaw = $request->post('met', '0');
        $romRaw = $request->post('default_rom_m');

        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (!in_array($type, ['duration', 'strength'], true)) {
            $errors[] = 'Type must be duration or strength.';
        }
        $met = (float) $metRaw;
        if ($met <= 0 || $met > 25) {
            $errors[] = 'MET must be in (0, 25].';
        }
        if ($errors !== []) {
            return $this->renderForm($exercise, ['name' => $name, 'type' => $type, 'met' => $metRaw, 'default_rom_m' => $romRaw], $errors);
        }

        $this->exercises->update($id, [
            'name' => $name,
            'type' => $type,
            'met' => $met,
            'default_rom_m' => $romRaw !== '' ? (float) $romRaw : null,
        ]);
        Session::set('flash_success', 'Updated.');
        return (new Response())->redirect('/health/exercises', 303);
    }

    #[Route('/health/exercises/{id}/delete', methods: ['POST'], name: 'tracker.exercises.delete')]
    public function delete(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $id = (int) ($request->routeParams()['id'] ?? 0);
        $exercise = $this->exercises->findById($id);
        if ($exercise === null) {
            return (new Response(404))->withBody('Not found');
        }
        $this->exercises->delete($id);
        Session::set('flash_success', 'Removed from catalog.');
        return (new Response())->redirect('/health/exercises', 303);
    }

    /**
     * @param array<string, mixed>|null $exercise previously-loaded row (edit mode)
     * @param array<string, mixed>      $old      user-supplied form values for repopulation
     * @param list<string>              $errors
     */
    private function renderForm(?array $exercise, array $old, array $errors): Response
    {
        return (new Response(422))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/exercise_form.twig', [
                'exercise' => $exercise,
                'old' => $old,
                'errors' => $errors,
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
