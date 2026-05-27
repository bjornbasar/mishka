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
];
