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

        $this->schedules->create([
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

        // Refresh upcoming: drop not-yet-done future occurrences + rewind the
        // watermark to now so the next view regenerates them from the new rule.
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
                'members' => $this->households->listMembers($hid),
                'household' => $this->households->findById($hid),
            ] + $this->nav->forCurrentUser()));
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
     * @return array{preset: string, interval: int, byday: list<string>, monthly_day: int}
     */
    private function extractRecurrence(Request $request): array
    {
        $body = $request->body();
        $bodyArr = is_array($body) ? $body : [];

        $preset = $this->str($request, 'recurrence_preset');
        $interval = max(1, (int) ($this->str($request, 'recurrence_interval') ?: '1'));
        $monthlyDay = (int) ($this->str($request, 'recurrence_monthly_day') ?: '1');

        $byday = [];
        $candidate = $bodyArr['recurrence_byday'] ?? $bodyArr['byday'] ?? null;
        if (is_array($candidate)) {
            $byday = array_values(array_filter(
                array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $candidate),
                static fn(string $v): bool => $v !== '',
            ));
        } else {
            $csv = $request->post('recurrence_byday');
            if ($csv !== '') {
                $byday = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn(string $v): bool => $v !== ''));
            }
        }

        return [
            'preset' => $preset !== '' ? $preset : 'none',
            'interval' => $interval,
            'byday' => $byday,
            'monthly_day' => $monthlyDay >= 1 ? $monthlyDay : 1,
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
