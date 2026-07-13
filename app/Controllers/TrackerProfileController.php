<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Tracker\BmrCalculator;
use App\Tracker\TrackerProfileRepository;
use App\Tracker\WeightLogRepository;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.8.2 — /health/profile CRUD (upsert semantics, one row per user).
 *
 * Gates on active_household_id like every /health/* route — profile is
 * per-user but consistency > strict scoping (household provides TZ for
 * updated_at display).
 *
 * Base-activity input has the double-count-trap wording ("your normal
 * day EXCLUDING workouts"). See DOCS #72.
 */
final class TrackerProfileController
{
    public function __construct(
        private readonly TrackerProfileRepository $profiles,
        private readonly WeightLogRepository $weights,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/health/profile', methods: ['GET'], name: 'tracker.profile.form')]
    public function form(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId] = $ctx;
        $profile = $this->profiles->findByUserId($userId);
        $latestWeight = $this->weights->latestForUser($userId);
        $bmr = $this->computeBmrPreview($profile, $latestWeight);
        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/profile_form.twig', [
                'profile' => $profile,
                'latest_weight' => $latestWeight,
                'bmr_preview' => $bmr,
                'errors' => [],
            ] + $this->nav->forCurrentUser()));
    }

    #[Route('/health/profile', methods: ['POST'], name: 'tracker.profile.store')]
    public function store(Request $request): Response
    {
        $ctx = $this->requireContext();
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$userId] = $ctx;

        $sex = $request->post('sex');
        $birthYearRaw = $request->post('birth_year');
        $heightRaw = $request->post('height_cm');
        $baseActivityRaw = $request->post('base_activity');

        $errors = [];
        if (!in_array($sex, ['male', 'female'], true)) {
            $errors[] = 'Please select male or female.';
        }
        $birthYear = (int) $birthYearRaw;
        if ($birthYear < 1900 || $birthYear > (int) date('Y') - 5) {
            $errors[] = 'Birth year must be a full year (e.g. 1985) and at least 5 years old.';
        }
        $heightCm = (float) $heightRaw;
        if ($heightCm < 50 || $heightCm > 250) {
            $errors[] = 'Height must be between 50 and 250 cm.';
        }
        $baseActivity = (float) $baseActivityRaw;
        if ($baseActivity < 1.0 || $baseActivity > 2.5) {
            $errors[] = 'Base activity must be between 1.0 and 2.5.';
        }

        if ($errors !== []) {
            $latestWeight = $this->weights->latestForUser($userId);
            return (new Response(422))
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->view->render('tracker/profile_form.twig', [
                    'profile' => $this->profiles->findByUserId($userId),
                    'latest_weight' => $latestWeight,
                    'bmr_preview' => null,
                    'errors' => $errors,
                    'old' => ['sex' => $sex, 'birth_year' => $birthYearRaw, 'height_cm' => $heightRaw, 'base_activity' => $baseActivityRaw],
                ] + $this->nav->forCurrentUser()));
        }

        $this->profiles->upsert($userId, [
            'sex' => $sex,
            'birth_year' => $birthYear,
            'height_cm' => $heightCm,
            'base_activity' => $baseActivity,
        ]);
        Session::set('flash_success', 'Profile saved.');
        return (new Response())->redirect('/health', 303);
    }

    /**
     * @param array<string, mixed>|null $profile
     * @param array<string, mixed>|null $latestWeight
     */
    private function computeBmrPreview(?array $profile, ?array $latestWeight): ?int
    {
        if ($profile === null || $latestWeight === null) {
            return null;
        }
        return BmrCalculator::calculate(
            (string) $profile['sex'],
            (int) $profile['birth_year'],
            (float) $profile['height_cm'],
            (float) $latestWeight['weight_kg'],
        );
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
