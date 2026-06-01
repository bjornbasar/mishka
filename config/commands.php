<?php

declare(strict_types=1);

/**
 * CLI command registry. Each class must have a #[Command(...)] method.
 * Discovered by Karhu\Cli\CommandDispatcher at bin/karhu invocation time.
 */
return [
    App\Commands\MigrateCommand::class,
    // v0.6.0 — web push
    App\Commands\PushGenerateKeysCommand::class,
    App\Commands\PushScanCommand::class,
    App\Commands\PushWorkerCommand::class,
];
