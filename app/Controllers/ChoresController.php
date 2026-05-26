<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Chores\ChoreRepository;
use App\Household\HouseholdRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * Household chores (v0.4.0 — one-off; recurrence + round-robin land in v0.4.1).
 *
 * Every route gates on Session user_id + active_household_id, then
 * HouseholdAuthorizer::requireMember. Any member may create/edit/delete/done/
 * reopen any chore (the calendar's trust model).
 *
 * POST bodies are whitelisted: timezone is forced from the household; assigned_to
 * is validated to a current member (else NULL); points are shape-checked before
 * casting (blank → 0; non-numeric / negative / >1000 → 422).
 *
 * Overdue is computed in PHP against each chore's own `timezone` (NULL due =
 * never overdue, done = never overdue). Completed chores are partitioned into a
 * "Done" section ordered most-recently-completed first.
 */
final class ChoresController
{
    private const MAX_POINTS = 1000;

    public function __construct(
        private readonly ChoreRepository $chores,
        private readonly HouseholdRepository $households,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/chores', methods: ['GET'], name: 'chores.list')]
    public function index(Request $request): Response
    {
        $guard = $this->requireSession();
        if ($guard !== null) {
            return $guard;
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);

        $household = $this->households->findById($hid);
        $members = $this->households->listMembers($hid);
        $memberNames = $this->memberNameMap($members);

        $all = $this->chores->listForHousehold($hid);
        $open = [];
        $done = [];
        foreach ($all as $chore) {
            $row = $chore + [
                'assignee_name' => $this->assigneeName($chore['assigned_to'], $memberNames),
                'is_overdue' => $this->isOverdue($chore),
            ];
            if ($chore['is_done']) {
                $done[] = $row;
            } else {
                $open[] = $row;
            }
        }
        // Done section: most-recently-completed first.
        usort($done, fn(array $a, array $b): int => ($b['completed_at'] ?? '') <=> ($a['completed_at'] ?? ''));

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('chores/index.twig', [
                'open_chores' => $open,
                'done_chores' => $done,
                'tally' => $this->chores->pointsTallyForHousehold($hid),
                'household' => $household,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/chores/new', methods: ['GET'])]
    public function showNew(Request $request): Response
    {
        $guard = $this->requireSession();
        if ($guard !== null) {
            return $guard;
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('chores/chore_form.twig', [
                'mode' => 'create',
                'errors' => [],
                'chore' => ['title' => '', 'description' => '', 'points' => 0, 'due_at_local' => '', 'assigned_to' => null],
                'members' => $this->households->listMembers($hid),
                'household' => $this->households->findById($hid),
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/chores', methods: ['POST'])]
    public function handleCreate(Request $request): Response
    {
        $guard = $this->requireSession();
        if ($guard !== null) {
            return $guard;
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);

        $household = $this->households->findById($hid);
        $input = $this->readInput($request);
        $errors = $this->validate($input);

        if ($errors !== []) {
            return $this->renderFormError($input, $errors, 'create', $hid, $household);
        }

        $this->chores->create([
            'household_id' => $hid,
            'created_by' => $userId,
            'timezone' => (string) ($household['timezone'] ?? 'Pacific/Auckland'),
            'title' => $input['title'],
            'description' => $input['description'],
            'points' => (int) $input['points'],
            'due_at_local' => $this->dueToSql($input['due_at_local']),
            'assigned_to' => $this->resolveAssignee($input['assigned_to'], $hid),
        ]);

        return (new Response())->redirect('/chores', 303);
    }

    #[Route('/chores/{id}', methods: ['GET'])]
    public function showEdit(Request $request): Response
    {
        $resolved = $this->resolveChore($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$chore, $hid] = $resolved;

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('chores/chore_form.twig', [
                'mode' => 'edit',
                'errors' => [],
                'chore' => $this->choreForForm($chore),
                'members' => $this->households->listMembers($hid),
                'household' => $this->households->findById($hid),
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/chores/{id}', methods: ['POST'])]
    public function handleUpdate(Request $request): Response
    {
        $resolved = $this->resolveChore($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$chore, $hid] = $resolved;
        $household = $this->households->findById($hid);

        $input = $this->readInput($request);
        $errors = $this->validate($input);
        if ($errors !== []) {
            $input['id'] = $chore['id'];
            return $this->renderFormError($input, $errors, 'edit', $hid, $household);
        }

        $this->chores->update((int) $chore['id'], [
            'title' => $input['title'],
            'description' => $input['description'],
            'points' => (int) $input['points'],
            'due_at_local' => $this->dueToSql($input['due_at_local']),
            'assigned_to' => $this->resolveAssignee($input['assigned_to'], $hid),
        ]);

        return (new Response())->redirect('/chores', 303);
    }

    #[Route('/chores/{id}/delete', methods: ['POST'])]
    public function handleDelete(Request $request): Response
    {
        $resolved = $this->resolveChore($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$chore] = $resolved;
        $this->chores->delete((int) $chore['id']);
        return (new Response())->redirect('/chores', 303);
    }

    #[Route('/chores/{id}/done', methods: ['POST'])]
    public function handleDone(Request $request): Response
    {
        $resolved = $this->resolveChore($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$chore] = $resolved;
        $this->chores->markDone((int) $chore['id'], (int) Session::get('user_id'));
        return (new Response())->redirect('/chores', 303);
    }

    #[Route('/chores/{id}/reopen', methods: ['POST'])]
    public function handleReopen(Request $request): Response
    {
        $resolved = $this->resolveChore($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$chore] = $resolved;
        $this->chores->reopen((int) $chore['id']);
        return (new Response())->redirect('/chores', 303);
    }

    // --- helpers ---

    private function requireSession(): ?Response
    {
        if (!Session::has('user_id')) {
            return (new Response())->redirect('/login', 302);
        }
        if (!Session::has('active_household_id')) {
            return (new Response())->redirect('/household/setup', 302);
        }
        return null;
    }

    /**
     * Session-gate, resolve the {id} chore, and enforce cross-household isolation.
     *
     * @return Response|array{0: array<string, mixed>, 1: int}
     */
    private function resolveChore(Request $request): Response|array
    {
        $guard = $this->requireSession();
        if ($guard !== null) {
            return $guard;
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);

        $id = (int) ($request->routeParams()['id'] ?? 0);
        $chore = $this->chores->findById($id);
        if ($chore === null || $chore['household_id'] !== $hid) {
            return (new Response(404))->withBody('Not found');
        }
        return [$chore, $hid];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $errors
     * @param array<string, mixed>|null $household
     */
    private function renderFormError(array $input, array $errors, string $mode, int $hid, ?array $household): Response
    {
        return (new Response(422))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('chores/chore_form.twig', [
                'mode' => $mode,
                'errors' => $errors,
                'chore' => $input,
                'members' => $this->households->listMembers($hid),
                'household' => $household,
            ] + $this->nav->forCurrentUser()));
    }

    /**
     * Whitelisted read of the POST body (JSON or form). Values stay as strings
     * for the validator to shape-check; coercion happens after validation.
     *
     * @return array{title: string, description: string, points: string, due_at_local: string, assigned_to: string}
     */
    private function readInput(Request $request): array
    {
        return [
            'title' => $this->str($request, 'title'),
            'description' => $this->str($request, 'description'),
            'points' => $this->str($request, 'points'),
            'due_at_local' => $this->str($request, 'due_at_local'),
            'assigned_to' => $this->str($request, 'assigned_to'),
        ];
    }

    private function str(Request $request, string $key): string
    {
        $body = $request->body();
        if (is_array($body) && array_key_exists($key, $body)) {
            $val = $body[$key];
            return is_scalar($val) ? (string) $val : '';
        }
        return $request->post($key);
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function validate(array $input): array
    {
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Title is required.';
        } elseif (mb_strlen($title) > 200) {
            $errors[] = 'Title is too long (max 200 characters).';
        }

        $points = (string) ($input['points'] ?? '');
        if ($points !== '') {
            if (preg_match('/^\d+$/', $points) !== 1) {
                $errors[] = 'Points must be a whole number.';
            } elseif ((int) $points > self::MAX_POINTS) {
                $errors[] = 'Points must be ' . self::MAX_POINTS . ' or fewer.';
            }
        }

        $due = (string) ($input['due_at_local'] ?? '');
        if ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $due) !== 1) {
            $errors[] = 'Due date is invalid.';
        }

        return $errors;
    }

    /** Browser 'Y-m-d\TH:i' → DB 'Y-m-d H:i:00'; blank → null. */
    private function dueToSql(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        return str_replace('T', ' ', $value) . ':00';
    }

    /** Empty/non-member → NULL; a current member's id → that id. */
    private function resolveAssignee(string $raw, int $hid): ?int
    {
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }
        $id = (int) $raw;
        return $this->households->isMember($id, $hid) ? $id : null;
    }

    /** @param array<string, mixed> $chore */
    private function isOverdue(array $chore): bool
    {
        if ($chore['due_at_local'] === null || $chore['is_done']) {
            return false;
        }
        $tz = new \DateTimeZone((string) $chore['timezone']);
        $due = new \DateTimeImmutable((string) $chore['due_at_local'], $tz);
        return $due < new \DateTimeImmutable('now', $tz);
    }

    /**
     * @param list<array{user_id: int, display_name: string, email: string, role: string, joined_at: string}> $members
     * @return array<int, string>
     */
    private function memberNameMap(array $members): array
    {
        $map = [];
        foreach ($members as $m) {
            $map[$m['user_id']] = $m['display_name'] !== '' ? $m['display_name'] : $m['email'];
        }
        return $map;
    }

    /** @param array<int, string> $memberNames */
    private function assigneeName(?int $assignedTo, array $memberNames): string
    {
        if ($assignedTo === null || !isset($memberNames[$assignedTo])) {
            return 'Unassigned';
        }
        return $memberNames[$assignedTo];
    }

    /**
     * @param array<string, mixed> $chore
     * @return array<string, mixed>
     */
    private function choreForForm(array $chore): array
    {
        $chore['due_at_local'] = $chore['due_at_local'] === null
            ? ''
            : substr(str_replace(' ', 'T', (string) $chore['due_at_local']), 0, 16);
        return $chore;
    }
}
