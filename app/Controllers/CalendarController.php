<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Calendar\ConcurrentUpdateException;
use App\Calendar\EventRepository;
use App\Calendar\MonthGridBuilder;
use App\Calendar\RangeExpander;
use App\Calendar\RruleTranslator;
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
        private readonly RangeExpander $expander,
        private readonly RruleTranslator $rrules,
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

        // Expand recurring + one-off events through RangeExpander so the month
        // grid shows every occurrence of a recurring series (not just the first).
        $occurrences = $this->expander->expandForHousehold($hid, $rangeStart, $rangeEnd);
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

        // Agenda also expands through RangeExpander so recurring series + their
        // overrides + cancellations all surface correctly.
        $occurrences = $this->expander->expandForHousehold($hid, $rangeStart, $rangeEnd);

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('calendar/agenda.twig', [
                'year' => $year,
                'month' => $month,
                'month_label' => $rangeStart->format('F Y'),
                'occurrences' => $occurrences,
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
                'recurrence' => $this->rrules->toForm(null),
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
                    'recurrence' => $this->extractRecurrenceForm($input),
                    'household' => $household,
                ] + $this->nav->forCurrentUser()));
        }

        $startsAtSql = $this->datetimeLocalToSql((string) $input['starts_at_local']);
        $tzString = (string) ($household['timezone'] ?? 'Pacific/Auckland');
        $rrule = $this->rrules->fromForm(
            $this->extractRecurrenceForm($input),
            new \DateTimeImmutable($startsAtSql, new \DateTimeZone($tzString)),
        );

        $eid = $this->events->create($input + [
            'household_id' => $hid,
            'created_by' => $userId,
            'timezone' => $tzString,
            'starts_at_local' => $startsAtSql,
            'ends_at_local' => $this->datetimeLocalToSql((string) $input['ends_at_local']),
            'rrule' => $rrule,
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
                'recurrence' => $this->rrules->toForm($event['rrule']),
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
                    'recurrence' => $this->extractRecurrenceForm($input),
                    'household' => $household,
                ] + $this->nav->forCurrentUser()));
        }

        $startsAtSql = $this->datetimeLocalToSql((string) $input['starts_at_local']);
        $tzString = (string) ($household['timezone'] ?? 'Pacific/Auckland');
        $rrule = $this->rrules->fromForm(
            $this->extractRecurrenceForm($input),
            new \DateTimeImmutable($startsAtSql, new \DateTimeZone($tzString)),
        );

        $writable = $input + [
            'starts_at_local' => $startsAtSql,
            'ends_at_local' => $this->datetimeLocalToSql((string) $input['ends_at_local']),
            'rrule' => $rrule,
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

        // Recurrence-form sub-keys (v0.3.1+) — independent of the WRITABLE
        // whitelist because they translate to `rrule` server-side rather than
        // landing in events.* directly. The translator owns the mapping.
        $out['recurrence'] = [
            'preset' => $this->stringFromBody($bodyArr, $request, 'recurrence_preset', 'none'),
            'interval' => (int) $this->stringFromBody($bodyArr, $request, 'recurrence_interval', '1'),
            'byday' => $this->bydayFromBody($bodyArr, $request),
            'monthly_day' => (int) $this->stringFromBody($bodyArr, $request, 'recurrence_monthly_day', '1'),
        ];

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringFromBody(array $body, Request $request, string $key, string $default): string
    {
        $val = $body[$key] ?? null;
        if (is_string($val)) {
            return $val;
        }
        if (is_scalar($val)) {
            return (string) $val;
        }
        $post = $request->post($key);
        return $post !== '' ? $post : $default;
    }

    /**
     * Parse the BYDAY checkbox payload, which arrives as `byday[]=MO&byday[]=TU`
     * (array) from a browser, or as `['byday' => ['MO','TU']]` from JSON tests.
     *
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function bydayFromBody(array $body, Request $request): array
    {
        $candidate = $body['recurrence_byday'] ?? $body['byday'] ?? null;
        if (is_array($candidate)) {
            return array_values(array_filter(
                array_map(fn(mixed $v): string => is_string($v) ? $v : '', $candidate),
                fn(string $v): bool => $v !== '',
            ));
        }
        // Form-urlencoded fallback — karhu's Request::post() returns a single
        // string per name. Browser checkboxes come in as `recurrence_byday[]=MO`;
        // PHP's $_POST collapses these to an array, so the JSON path above
        // covers the typical case. As a defensive fallback, accept a CSV.
        $csv = $request->post('recurrence_byday');
        if ($csv !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $csv)), fn(string $v): bool => $v !== ''));
        }
        return [];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{preset: string, interval: int, byday: list<string>, monthly_day: int}
     */
    private function extractRecurrenceForm(array $input): array
    {
        $r = $input['recurrence'] ?? [];
        if (!is_array($r)) {
            return ['preset' => 'none', 'interval' => 1, 'byday' => [], 'monthly_day' => 1];
        }
        return [
            'preset' => isset($r['preset']) && is_string($r['preset']) ? $r['preset'] : 'none',
            'interval' => isset($r['interval']) ? max(1, (int) $r['interval']) : 1,
            'byday' => isset($r['byday']) && is_array($r['byday'])
                ? array_values(array_filter($r['byday'], 'is_string'))
                : [],
            'monthly_day' => isset($r['monthly_day']) ? (int) $r['monthly_day'] : 1,
        ];
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
