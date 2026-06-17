<?php

declare(strict_types=1);

/**
 * Mishka Den — front controller.
 *
 * Wires the application via public/bootstrap.php (extracted in v0.6.16
 * for testability — see DOCS.md decision #57 and the regression guard
 * in tests/Smoke/BootstrapSmokeTest.php) and dispatches the current
 * request via $app->run().
 */

$app = require __DIR__ . '/bootstrap.php';
$app->run();
