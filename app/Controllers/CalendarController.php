<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Calendar\ConcurrentUpdateException;
use App\Calendar\EventRepository;
use App\Calendar\MonthGridBuilder;
use App\Household\HouseholdRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * Household calendar (v0.3.0 — one-off events only; recurrence + iCal in v0.3.1/v0.3.2).
 *
 * Every route gates on Session::has('user_id') + Session::has('active_household_id'),
 * then HouseholdAuthorizer::requireMember(). Stale sessions self-heal via karhu v0.1.1's
 * ForbiddenException(redirectTo='/household/setup').
 *
 * POST handlers whitelist allowed columns; the household timezone is forcibly applied
 * to every new event (never accepted from form input — that lands in v0.4+).
 */
final class CalendarController
{
    /** Whitelist for create+update body. Mirrors EventRepository's WRITABLE_COLUMNS. */
    private const WRITABLE = ['title', 'description', 'location', 'starts_at_local', 'ends_at_local', 'all_day'];

    public function __construct(
        private readonly EventRepository $events,
        private readonly HouseholdRepository $households,
        private readonly HouseholdAuthorizer $auth,
        private readonly MonthGridBuilder $grid,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/calendar', methods: ['GET'], name: 'calendar.month')]
    public function showMonth(Request $request): Response
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
        $tz = $household['timezone'] ?? 'Pacific/Auckland';

        [$year, $month] = $this->parseYearMonth($request->query('ym'), $tz);
        [$rangeStart, $rangeEnd] = $this->monthRange($year, $month, $tz);

        $rows = $this->events->findInRangeForHousehold($hid, $rangeStart, $rangeEnd);
        $occurrences = $this->rowsAsOccurrences($rows, $tz);
        $monthGrid = $this->grid->build($year, $month, $tz, $occurrences);

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('calendar/month.twig', [
                'year' => $year,
                'month' => $month,
                'month_label' => $rangeStart->format('F Y'),
                'prev_ym' => $rangeStart->modify('-1 month')->format('Y-m'),
                'next_ym' => $rangeStart->modify('+1 month')->format('Y-m'),
                'grid' => $monthGrid,
                'household' => $household,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/calendar/agenda', methods: ['GET'], name: 'calendar.agenda')]
    public function showAgenda(Request $request): Response
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
        $tz = $household['timezone'] ?? 'Pacific/Auckland';

        [$year, $month] = $this->parseYearMonth($request->query('ym'), $tz);
        [$rangeStart, $rangeEnd] = $this->monthRange($year, $month, $tz);

        $rows = $this->events->findInRangeForHousehold($hid, $rangeStart, $rangeEnd);

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('calendar/agenda.twig', [
                'year' => $year,
                'month' => $month,
                'month_label' => $rangeStart->format('F Y'),
                'events' => $rows,
                'household' => $household,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/calendar/events/new', methods: ['GET'])]
    public function showNew(Request $request): Response
    {
        $guard = $this->requireSession();
        if ($guard !== null) {
            return $guard;
        }

        $hid = (int) Session::get('active_household_id');
        $userId = (int) Session::get('user_id');
        $this->auth->requireMember($userId, $hid);

        $household = $this->households->findById($hid);
        $tz = new \DateTimeZone($household['timezone'] ?? 'Pacific/Auckland');
        // Suggest tomorrow 9am in the household tz so the form opens with sane defaults.
        $defaultStart = (new \DateTimeImmutable('tomorrow 09:00', $tz));
        $defaultEnd = $defaultStart->modify('+1 hour');

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('calendar/event_form.twig', [
                'mode' => 'create',
                'errors' => [],
                'event' => [
                    'title' => '', 'description' => '', 'location' => '',
                    'starts_at_local' => $defaultStart->format('Y-m-d\TH:i'),
                    'ends_at_local' => $defaultEnd->format('Y-m-d\TH:i'),
                    'all_day' => false,
                ],
                'household' => $household,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/calendar/events', methods: ['POST'])]
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
        $input = $this->readWhitelistedInput($request);
        $errors = $this->validate($input);

        if ($errors !== []) {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->view->render('calendar/event_form.twig', [
                    'mode' => 'create',
                    'errors' => $errors,
                    'event' => $input,
                    'household' => $household,
                ] + $this->nav->forCurrentUser()));
        }

        $eid = $this->events->create($input + [
            'household_id' => $hid,
            'created_by' => $userId,
            'timezone' => $household['timezone'] ?? 'Pacific/Auckland',
            'starts_at_local' => $this->datetimeLocalToSql((string) $input['starts_at_local']),
            'ends_at_local' => $this->datetimeLocalToSql((string) $input['ends_at_local']),
        ]);

        $ym = (new \DateTimeImmutable((string) $input['starts_at_local']))->format('Y-m');
        return (new Response())->redirect("/calendar?ym={$ym}", 303);
    }

    #[Route('/calendar/events/{id}', methods: ['GET'])]
    public function showEvent(Request $request): Response
    {
        $guard = $this->requireSession();
        if ($guard !== null) {
            return $guard;
        }

        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);

        $eid = (int) ($request->routeParams()['id'] ?? 0);
        $event = $this->events->findById($eid);
        if ($event === null || $event['household_id'] !== $hid) {
            return (new Response(404))->withBody('Not found');
        }

        $household = $this->households->findById($hid);

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('calendar/event_form.twig', [
                'mode' => 'edit',
                'errors' => [],
                'event' => $this->eventForForm($event),
                'household' => $household,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/calendar/events/{id}', methods: ['POST'])]
    public function handleUpdate(Request $request): Response
    {
        $guard = $this->requireSession();
        if ($guard !== null) {
            return $guard;
        }

        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);

        $eid = (int) ($request->routeParams()['id'] ?? 0);
        $event = $this->events->findById($eid);
        if ($event === null || $event['household_id'] !== $hid) {
            return (new Response(404))->withBody('Not found');
        }

        $household = $this->households->findById($hid);
        $input = $this->readWhitelistedInput($request);
        $expectedUpdatedAt = (string) $this->readField($request, '_expected_updated_at');
        $errors = $this->validate($input);

        if ($errors !== []) {
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->view->render('calendar/event_form.twig', [
                    'mode' => 'edit',
                    'errors' => $errors,
                    'event' => $input + ['id' => $eid, 'updated_at' => $event['updated_at']],
                    'household' => $household,
                ] + $this->nav->forCurrentUser()));
        }

        $writable = $input + [
            'starts_at_local' => $this->datetimeLocalToSql((string) $input['starts_at_local']),
            'ends_at_local' => $this->datetimeLocalToSql((string) $input['ends_at_local']),
        ];

        try {
            $this->events->update($eid, $writable, $expectedUpdatedAt);
        } catch (ConcurrentUpdateException $e) {
            // Stale data: someone else (or another tab) edited this event since the
            // form was rendered. Show the partial with a "View current event" link.
            $fresh = $this->events->findById($eid);
            return (new Response(409))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->view->render('calendar/_stale_data.twig', [
                    'event' => $fresh,
                    'household' => $household,
                ] + $this->nav->forCurrentUser()));
        }

        return (new Response())->redirect("/calendar/events/{$eid}", 303);
    }

    #[Route('/calendar/events/{id}/delete', methods: ['POST'])]
    public function handleDelete(Request $request): Response
    {
        $guard = $this->requireSession();
        if ($guard !== null) {
            return $guard;
        }

        $userId = (int) Session::get('user_id');
        $hid = (int) Session::get('active_household_id');
        $this->auth->requireMember($userId, $hid);

        $eid = (int) ($request->routeParams()['id'] ?? 0);
        $event = $this->events->findById($eid);
        if ($event === null || $event['household_id'] !== $hid) {
            return (new Response(404))->withBody('Not found');
        }

        $this->events->delete($eid);
        return (new Response())->redirect('/calendar', 303);
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

    /** @return array{0: int, 1: int} */
    private function parseYearMonth(string $ym, string $tz): array
    {
        if ($ym !== '' && preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) === 1) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            if ($mo >= 1 && $mo <= 12) {
                return [$y, $mo];
            }
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));
        return [(int) $now->format('Y'), (int) $now->format('n')];
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function monthRange(int $year, int $month, string $tz): array
    {
        $tzObj = new \DateTimeZone($tz);
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tzObj);
        $last = $first->modify('last day of this month')->setTime(23, 59, 59);
        return [$first, $last];
    }

    /**
     * Turn DB rows into MonthGridBuilder occurrence records.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{event: array<string, mixed>, occurrence: \DateTimeImmutable, occurrence_end: \DateTimeImmutable}>
     */
    private function rowsAsOccurrences(array $rows, string $tz): array
    {
        $tzObj = new \DateTimeZone($tz);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'event' => $row,
                'occurrence' => new \DateTimeImmutable((string) $row['starts_at_local'], $tzObj),
                'occurrence_end' => new \DateTimeImmutable((string) $row['ends_at_local'], $tzObj),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function readWhitelistedInput(Request $request): array
    {
        $body = $request->body();
        $bodyArr = is_array($body) ? $body : [];
        $out = [];
        foreach (self::WRITABLE as $key) {
            $val = $bodyArr[$key] ?? null;
            if (is_string($val)) {
                $out[$key] = $val;
            } elseif (is_bool($val) || is_int($val)) {
                $out[$key] = $val;
            } else {
                $post = $request->post($key);
                if ($post !== '') {
                    $out[$key] = $post;
                }
            }
        }
        // Provide safe defaults so the validator can run without isset noise
        $out += ['title' => '', 'description' => '', 'location' => '',
                 'starts_at_local' => '', 'ends_at_local' => '', 'all_day' => false];
        if (!is_bool($out['all_day'])) {
            $out['all_day'] = in_array($out['all_day'], [true, 1, '1', 'on', 'true'], true);
        }
        return $out;
    }

    private function readField(Request $request, string $key): string
    {
        $body = $request->body();
        if (is_array($body) && isset($body[$key]) && is_scalar($body[$key])) {
            return (string) $body[$key];
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
        if (trim((string) ($input['title'] ?? '')) === '') {
            $errors[] = 'Title is required.';
        } elseif (mb_strlen((string) $input['title']) > 200) {
            $errors[] = 'Title is too long (max 200 characters).';
        }
        if (!$this->isValidDatetimeLocal((string) ($input['starts_at_local'] ?? ''))) {
            $errors[] = 'Start time is invalid.';
        }
        if (!$this->isValidDatetimeLocal((string) ($input['ends_at_local'] ?? ''))) {
            $errors[] = 'End time is invalid.';
        }
        if ($errors === [] && $input['ends_at_local'] < $input['starts_at_local']) {
            $errors[] = 'End time must be at or after the start time.';
        }
        return $errors;
    }

    private function isValidDatetimeLocal(string $value): bool
    {
        // Browser <input type="datetime-local"> emits 'Y-m-d\TH:i' (no seconds, no tz).
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1;
    }

    /** Browser 'Y-m-d\TH:i' → DB 'Y-m-d H:i:00'. */
    private function datetimeLocalToSql(string $value): string
    {
        return str_replace('T', ' ', $value) . ':00';
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function eventForForm(array $event): array
    {
        return $event + [
            'starts_at_local' => $this->sqlToDatetimeLocal((string) $event['starts_at_local']),
            'ends_at_local' => $this->sqlToDatetimeLocal((string) $event['ends_at_local']),
        ];
    }

    private function sqlToDatetimeLocal(string $sql): string
    {
        // DB 'Y-m-d H:i:s' → browser 'Y-m-d\TH:i'
        return substr(str_replace(' ', 'T', $sql), 0, 16);
    }
}
