<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Household\HouseholdRepository;
use App\Tracker\LocalDay;
use App\Tracker\WeightLogRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.8.1 — user weight tracking.
 *
 * Weight is a per-user time series (no household_id). Household TZ
 * drives measured_on. Gates on active_household_id like other /health/*
 * routes — user must be in a household so the timezone is defined.
 * See DOCS #71.
 */
final class WeightController
{
    public function __construct(
        private readonly WeightLogRepository $weights,
        private readonly HouseholdRepository $households,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/health/weight', methods: ['GET'], name: 'tracker.weight.form')]
    public function form(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId] = $ctx;
        $latest = $this->weights->latestForUser($userId);
        $history = $this->weights->listForUser($userId, limit: 10);
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/weight_form.twig', [
                'latest' => $latest,
                'history' => $history,
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/health/weight', methods: ['POST'], name: 'tracker.weight.store')]
    public function store(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId, $hid] = $ctx;

        $weightKg = (float) $request->post('weight_kg');
        if ($weightKg < 20.0 || $weightKg > 300.0) {
            Session::set('flash_error', 'Weight must be between 20 and 300 kg.');
            return (new Response())->redirect('/health/weight', 303);
        }
        $measuredOnRaw = trim($request->post('measured_on'));
        if ($measuredOnRaw !== '') {
            // User-supplied date — validate shape.
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredOnRaw)) {
                Session::set('flash_error', 'Date must be YYYY-MM-DD.');
                return (new Response())->redirect('/health/weight', 303);
            }
            $measuredOn = $measuredOnRaw;
        } else {
            $household = $this->households->findById($hid);
            $tz = new \DateTimeZone((string) $household['timezone']);
            $measuredOn = LocalDay::today($tz);
        }

        $this->weights->create($userId, $weightKg, $measuredOn);
        Session::set('flash_success', 'Weight recorded.');
        return (new Response())->redirect('/health/weight', 303);
    }

    #[Route('/health/weight/{id}/delete', methods: ['POST'], name: 'tracker.weight.delete')]
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
        $affected = $this->weights->deleteOwnedById($id, $userId);
        if ($affected === 0) {
            Session::set('flash_error', 'Not your entry to remove.');
        } else {
            Session::set('flash_success', 'Removed.');
        }
        return (new Response())->redirect('/health/weight', 303);
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
