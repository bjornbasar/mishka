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
];
