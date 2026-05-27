<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Chores\ChoreRepository;
use App\Chores\ChoreScheduleGenerator;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * Landing page.
 *
 * - Anonymous: pitch + Register/Sign-in CTAs.
 * - Logged in WITHOUT an active household: redirect to /household/setup. This
 *   covers (a) new registrations that haven't completed onboarding, (b) v0.1
 *   users whose accounts pre-date the household model, and (c) the kicked-user
 *   self-heal path (NavContext returns null active when the session id no
 *   longer matches a membership row).
 * - Logged in WITH an active household: render home.twig showing the active
 *   household name + a link to the settings page. Calendar / chores will fill
 *   this view further in v0.3+.
 */
final class HomeController
{
    public function __construct(
        private readonly TwigAdapter $view,
        private readonly NavContext $nav,
        private readonly ChoreRepository $chores,
        private readonly ChoreScheduleGenerator $generator,
    ) {}

    #[Route('/', name: 'home')]
    public function index(Request $request): Response
    {
        $ctx = $this->nav->forCurrentUser();

        if (!Session::has('user_id')) {
            // Anonymous visitor — render the pitch.
            return (new Response())
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->view->render('home.twig', $ctx));
        }

        if ($ctx['active_household'] === null) {
            // Logged in but no household yet (or the session id is stale).
            // NavContext's freshness check has already handled the stale-id case
            // by returning null; either way, /household/setup is the destination.
            return (new Response())->redirect('/household/setup', 302);
        }

        $hid = (int) $ctx['active_household']['id'];
        // Materialise due recurring occurrences so the home counts stay fresh.
        // Bounded + idempotent (watermark makes this a near no-op on re-view);
        // best-effort so it never 500s the landing page.
        try {
            $this->generator->generateForHousehold($hid);
        } catch (\Throwable) {
            // ignore; render whatever exists
        }
        $chores = $this->chores->listForHousehold($hid);
        $openCount = 0;
        $overdueCount = 0;
        foreach ($chores as $chore) {
            if ($chore['is_done']) {
                continue;
            }
            $openCount++;
            if ($this->isOverdue($chore)) {
                $overdueCount++;
            }
        }

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('home.twig', [
                'chore_tally' => $this->chores->pointsTallyForHousehold($hid),
                'chore_open_count' => $openCount,
                'chore_overdue_count' => $overdueCount,
            ] + $ctx));
    }

    /** @param array<string, mixed> $chore */
    private function isOverdue(array $chore): bool
    {
        if ($chore['due_at_local'] === null || $chore['is_done']) {
            return false;
        }
        $tz = new \DateTimeZone((string) $chore['timezone']);
        return new \DateTimeImmutable((string) $chore['due_at_local'], $tz) < new \DateTimeImmutable('now', $tz);
    }
}
