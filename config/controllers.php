<?php

declare(strict_types=1);

/**
 * Controller registry — every class with #[Route(...)] methods.
 * Karhu reflects these at boot to build the router. Add new controllers here.
 */
return [
    App\Controllers\HomeController::class,
    App\Controllers\AuthController::class,
    App\Controllers\HouseholdController::class,
    App\Controllers\CalendarController::class,
    App\Controllers\IcalFeedController::class,
    // ChoreSchedulesController MUST precede ChoresController — the router matches
    // sequentially and `/chores/{id}` would otherwise capture `/chores/schedules`.
    App\Controllers\ChoreSchedulesController::class,
    App\Controllers\ChoresController::class,
    // v0.5.0 — account + email-dependent flows
    App\Controllers\AccountController::class,
    App\Controllers\PasswordResetController::class,
    App\Controllers\EmailVerificationController::class,
    // v0.5.2 — in-product user guide
    App\Controllers\HelpController::class,
    // v0.6.0 — push notifications
    App\Controllers\NotificationsController::class,
    // v0.6.7 — /offline shell precached by the service worker
    App\Controllers\OfflineController::class,
    // v0.6.8 — /csrf-token JSON endpoint for inline refresh script
    App\Controllers\CsrfTokenController::class,
    // v0.6.13 — persistent badges + /badges page
    App\Controllers\BadgesController::class,
    // v0.7.0 — per-device session revoke UI
    App\Controllers\SessionsController::class,
    // v0.8.0 — Tracker Phase 1: /health (Today) + food logging + library.
    // Intra-class route ordering (literals before {id}) is documented in
    // each controller's class-level docblock; cross-class order here is
    // stable because /health/log/food/*, /health/foods/*, and the v0.8.1
    // exercise + weight paths don't collide.
    App\Controllers\TrackerController::class,
    App\Controllers\FoodLogController::class,
    App\Controllers\FoodLibraryController::class,
    // v0.8.1 — Tracker Phase 2: exercise catalog + logging + weight.
    App\Controllers\ExerciseLogController::class,
    App\Controllers\ExerciseCatalogController::class,
    App\Controllers\WeightController::class,
    // v0.8.2 — Tracker Phase 3: profile + BMR.
    App\Controllers\TrackerProfileController::class,
];
