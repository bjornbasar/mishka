<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Calendar\RruleTranslator;
use App\Chores\ChoreRepository;
use App\Chores\ChoreScheduleRepository;
use App\Household\HouseholdRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * Recurring-chore templates (v0.4.1). A schedule defines an rrule + anchor +
 * assignment mode; ChoreScheduleGenerator materialises occurrences as chores
 * rows lazily on view. This controller is the schedule CRUD surface.
 *
 * Reuses App\Calendar\RruleTranslator (preset form ↔ RRULE) — the anchor date is
 * passed as the "start" so weekly/monthly rules phase correctly.
 *
 * No DB FK on chores.schedule_id, so edit/delete coordinate the chores side in
 * the app: edit refreshes upcoming open occurrences; delete drops open + detaches
 * completed (preserving points history).
 */
final class ChoreSchedulesController
{
    private const MAX_POINTS = 1000;

    public function __construct(
        private readonly ChoreScheduleRepository $schedules,
        private readonly ChoreRepository $chores,
        private readonly RruleTranslator $rrules,
        private readonly HouseholdRepository $households,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/chores/schedules/new', methods: ['GET'])]
    public function showNew(Request $request): Response
    {
        $guard = $this->requireSession();
        if ($guard !== null) {
            return $guard;
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);

        $tz = new \DateTimeZone((string) ($this->households->findById($hid)['timezone'] ?? 'Pacific/Auckland'));
        $tomorrow = (new \DateTimeImmutable('tomorrow', $tz));

        return $this->renderForm('create', [], [
            'title' => '', 'description' => '', 'points' => 5,
            'anchor_date' => $tomorrow->format('Y-m-d'), 'due_time' => '09:00',
            'assignment_mode' => 'rotate', 'fixed_user_id' => null,
        ], $this->rrules->toForm(null), $hid);
    }

    #[Route('/chores/schedules', methods: ['POST'])]
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
        $tz = (string) ($household['timezone'] ?? 'Pacific/Auckland');
        $input = $this->readInput($request);
        $recurrence = $this->extractRecurrence($request);

        $errors = $this->validate($input, $recurrence, $hid);
        if ($errors !== []) {
            return $this->renderForm('create', $errors, $input, $recurrence, $hid, 422);
        }

        $anchor = $this->anchorSql($input['anchor_date'], $input['due_time']);
        $rrule = $this->rrules->fromForm($recurrence, new \DateTimeImmutable($anchor, new \DateTimeZone($tz)));

        $sid = $this->schedules->create([
            'household_id' => $hid,
            'created_by' => $userId,
            'title' => $input['title'],
            'description' => $input['description'],
            'points' => (int) $input['points'],
            'rrule' => (string) $rrule,
            'anchor_at_local' => $anchor,
            'timezone' => $tz,
            'assignment_mode' => $input['assignment_mode'],
            'fixed_user_id' => $input['assignment_mode'] === 'fixed' ? (int) $input['fixed_user_id'] : null,
        ]);

        // Pool only applies to rotate mode; fixed mode clears any pool.
        $this->schedules->setParticipants(
            $sid,
            $input['assignment_mode'] === 'rotate' ? $this->extractParticipants($request, $hid) : [],
        );

        return (new Response())->redirect('/chores', 303);
    }

    #[Route('/chores/schedules/{id}', methods: ['GET'])]
    public function showEdit(Request $request): Response
    {
        $resolved = $this->resolveSchedule($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$schedule, $hid] = $resolved;

        $anchor = new \DateTimeImmutable((string) $schedule['anchor_at_local']);
        return $this->renderForm('edit', [], [
            'id' => $schedule['id'],
            'title' => $schedule['title'],
            'description' => $schedule['description'],
            'points' => $schedule['points'],
            'anchor_date' => $anchor->format('Y-m-d'),
            'due_time' => $anchor->format('H:i'),
            'assignment_mode' => $schedule['assignment_mode'],
            'fixed_user_id' => $schedule['fixed_user_id'],
            'participant_ids' => $this->schedules->listParticipantIds((int) $schedule['id']),
        ], $this->rrules->toForm($schedule['rrule']), $hid);
    }

    #[Route('/chores/schedules/{id}', methods: ['POST'])]
    public function handleUpdate(Request $request): Response
    {
        $resolved = $this->resolveSchedule($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$schedule, $hid] = $resolved;
        $tz = (string) $schedule['timezone'];

        $input = $this->readInput($request);
        $recurrence = $this->extractRecurrence($request);
        $errors = $this->validate($input, $recurrence, $hid);
        if ($errors !== []) {
            $input['id'] = $schedule['id'];
            return $this->renderForm('edit', $errors, $input, $recurrence, $hid, 422);
        }

        $anchor = $this->anchorSql($input['anchor_date'], $input['due_time']);
        $rrule = $this->rrules->fromForm($recurrence, new \DateTimeImmutable($anchor, new \DateTimeZone($tz)));
        $sid = (int) $schedule['id'];

        $this->schedules->update($sid, [
            'title' => $input['title'],
            'description' => $input['description'],
            'points' => (int) $input['points'],
            'rrule' => (string) $rrule,
            'anchor_at_local' => $anchor,
            'assignment_mode' => $input['assignment_mode'],
            'fixed_user_id' => $input['assignment_mode'] === 'fixed' ? (int) $input['fixed_user_id'] : null,
        ]);

        $this->schedules->setParticipants(
            $sid,
            $input['assignment_mode'] === 'rotate' ? $this->extractParticipants($request, $hid) : [],
        );

        // Refresh upcoming: drop not-yet-done future occurrences + rewind the
        // watermark to now so the next view regenerates them from the new rule
        // (and the new pool).
        $nowSql = (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('Y-m-d H:i:00');
        $this->chores->deleteFutureOpenForSchedule($sid, $nowSql);
        $this->schedules->setGeneratedThrough($sid, $nowSql);

        return (new Response())->redirect('/chores', 303);
    }

    #[Route('/chores/schedules/{id}/delete', methods: ['POST'])]
    public function handleDelete(Request $request): Response
    {
        $resolved = $this->resolveSchedule($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$schedule] = $resolved;
        $sid = (int) $schedule['id'];

        // No FK cascade: drop open generated instances, detach completed ones
        // (preserve history), then delete the template.
        $this->chores->detachAndDropForSchedule($sid);
        $this->schedules->delete($sid);

        return (new Response())->redirect('/chores', 303);
    }

    #[Route('/chores/schedules/{id}/pause', methods: ['POST'])]
    public function handlePause(Request $request): Response
    {
        $resolved = $this->resolveSchedule($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$schedule] = $resolved;
        $this->schedules->pause((int) $schedule['id']);
        return (new Response())->redirect('/chores', 303);
    }

    #[Route('/chores/schedules/{id}/resume', methods: ['POST'])]
    public function handleResume(Request $request): Response
    {
        $resolved = $this->resolveSchedule($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$schedule] = $resolved;
        $sid = (int) $schedule['id'];

        $this->schedules->resume($sid);
        // Forward-only: rewind the watermark to now so a long pause doesn't spawn a
        // backlog of missed occurrences on resume.
        $nowSql = (new \DateTimeImmutable('now', new \DateTimeZone((string) $schedule['timezone'])))->format('Y-m-d H:i:00');
        $this->schedules->setGeneratedThrough($sid, $nowSql);

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
     * @return Response|array{0: array<string, mixed>, 1: int}
     */
    private function resolveSchedule(Request $request): Response|array
    {
        $guard = $this->requireSession();
        if ($guard !== null) {
            return $guard;
        }
        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);

        $id = (int) ($request->routeParams()['id'] ?? 0);
        $schedule = $this->schedules->findById($id);
        if ($schedule === null || $schedule['household_id'] !== $hid) {
            return (new Response(404))->withBody('Not found');
        }
        return [$schedule, $hid];
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed> $schedule
     * @param array{preset: string, interval: int, byday: list<string>, monthly_day: int} $recurrence
     */
    private function renderForm(string $mode, array $errors, array $schedule, array $recurrence, int $hid, int $status = 200): Response
    {
        return (new Response($status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('chores/schedule_form.twig', [
                'mode' => $mode,
                'errors' => $errors,
                'schedule' => $schedule,
                'recurrence' => $recurrence,
                'participant_ids' => $schedule['participant_ids'] ?? [],
                'members' => $this->households->listMembers($hid),
                'household' => $this->households->findById($hid),
            ] + $this->nav->forCurrentUser()));
    }

    /**
     * Parse the participants[] pool, keeping only current members of the household.
     *
     * @return list<int>
     */
    private function extractParticipants(Request $request, int $hid): array
    {
        // Use the shared array-field reader so the form-urlencoded path
        // (real browser submits of `participants[]`) doesn't silently drop
        // the rotation pool. Pre-bugfix this method ONLY read the JSON body,
        // so production form submits ended up with an empty pool.
        $ids = [];
        foreach ($this->arrayField($request, 'participants') as $v) {
            if (preg_match('/^\d+$/', $v) === 1) {
                $ids[] = (int) $v;
            }
        }
        return array_values(array_filter(
            array_unique($ids),
            fn(int $id): bool => $this->households->isMember($id, $hid),
        ));
    }

    /**
     * @return array{title: string, description: string, points: string,
     *               anchor_date: string, due_time: string, assignment_mode: string, fixed_user_id: string}
     */
    private function readInput(Request $request): array
    {
        return [
            'title' => $this->str($request, 'title'),
            'description' => $this->str($request, 'description'),
            'points' => $this->str($request, 'points'),
            'anchor_date' => $this->str($request, 'anchor_date'),
            'due_time' => $this->str($request, 'due_time'),
            'assignment_mode' => $this->str($request, 'assignment_mode') === 'fixed' ? 'fixed' : 'rotate',
            'fixed_user_id' => $this->str($request, 'fixed_user_id'),
        ];
    }

    /**
     * @return array{preset: string, interval: int, byday: list<string>,
     *               monthly_day: int, monthly_mode: string,
     *               monthly_dow_position: int, monthly_dow_day: string}
     */
    private function extractRecurrence(Request $request): array
    {
        $preset = $this->str($request, 'recurrence_preset');
        $interval = max(1, (int) ($this->str($request, 'recurrence_interval') ?: '1'));
        $monthlyDay = (int) ($this->str($request, 'recurrence_monthly_day') ?: '1');

        $byday = $this->arrayField($request, 'recurrence_byday');
        if ($byday === []) {
            // Legacy CSV fallback (single-string `recurrence_byday=MO,WE,FR`)
            // for any test or API caller that doesn't use the bracketed form.
            $csv = $this->str($request, 'recurrence_byday');
            if ($csv !== '') {
                $byday = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn(string $v): bool => $v !== ''));
            }
        }

        // Monthly positional-day-of-week sub-mode (e.g., "first Friday").
        // RruleTranslator branches on monthly_mode and ignores the inactive
        // sub-mode's fields, so reading both unconditionally is safe.
        $monthlyMode = $this->str($request, 'recurrence_monthly_mode');
        if ($monthlyMode !== 'dow') {
            $monthlyMode = 'day';
        }
        $dowPosition = (int) ($this->str($request, 'recurrence_monthly_dow_position') ?: '1');
        $dowDay = strtoupper($this->str($request, 'recurrence_monthly_dow_day'));

        return [
            'preset' => $preset !== '' ? $preset : 'none',
            'interval' => $interval,
            'byday' => $byday,
            'monthly_day' => $monthlyDay >= 1 ? $monthlyDay : 1,
            'monthly_mode' => $monthlyMode,
            'monthly_dow_position' => $dowPosition,
            'monthly_dow_day' => $dowDay,
        ];
    }

    /**
     * Read an array-valued form field (e.g., name="X[]") from either the JSON
     * body (test harness convention) OR PHP's `$_POST` superglobal (real
     * browser form-urlencoded submits). karhu's `Request::post()` returns
     * `string` and throws a TypeError when the underlying $_POST value is an
     * array — this helper bypasses that constraint without needing a karhu
     * patch.
     *
     * @return list<string>
     */
    private function arrayField(Request $request, string $field): array
    {
        $body = $request->body();
        if (is_array($body)) {
            $candidate = $body[$field] ?? null;
            if (is_array($candidate)) {
                return array_values(array_filter(
                    array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $candidate),
                    static fn(string $v): bool => $v !== '',
                ));
            }
        }
        // Form-urlencoded path: real browser submission sets $_POST[$field]
        // to an array when name="X[]" with multiple values.
        if (isset($_POST[$field]) && is_array($_POST[$field])) {
            return array_values(array_filter(
                array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $_POST[$field]),
                static fn(string $v): bool => $v !== '',
            ));
        }
        return [];
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
     * @param array{preset: string} $recurrence
     * @return list<string>
     */
    private function validate(array $input, array $recurrence, int $hid): array
    {
        $errors = [];
        $title = trim((string) $input['title']);
        if ($title === '') {
            $errors[] = 'Title is required.';
        } elseif (mb_strlen($title) > 200) {
            $errors[] = 'Title is too long (max 200 characters).';
        }

        $points = (string) $input['points'];
        if ($points !== '') {
            if (preg_match('/^\d+$/', $points) !== 1) {
                $errors[] = 'Points must be a whole number.';
            } elseif ((int) $points > self::MAX_POINTS) {
                $errors[] = 'Points must be ' . self::MAX_POINTS . ' or fewer.';
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input['anchor_date']) !== 1) {
            $errors[] = 'Start date is invalid.';
        }
        if (preg_match('/^\d{2}:\d{2}$/', (string) $input['due_time']) !== 1) {
            $errors[] = 'Due time is invalid.';
        }

        if ($recurrence['preset'] === 'none') {
            $errors[] = 'Pick a repeat frequency (a one-off task is a plain chore).';
        }

        if ($input['assignment_mode'] === 'fixed') {
            $fixed = (string) $input['fixed_user_id'];
            if (preg_match('/^\d+$/', $fixed) !== 1 || !$this->households->isMember((int) $fixed, $hid)) {
                $errors[] = 'Pick a household member to assign this chore to.';
            }
        }

        return $errors;
    }

    private function anchorSql(string $date, string $time): string
    {
        return "{$date} {$time}:00";
    }
}
