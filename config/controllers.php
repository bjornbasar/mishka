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
];
