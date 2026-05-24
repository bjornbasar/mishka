<?php

declare(strict_types=1);

/**
 * CLI command registry. Each class must have a #[Command(...)] method.
 * Discovered by Karhu\Cli\CommandDispatcher at bin/karhu invocation time.
 */
return [
    App\Commands\MigrateCommand::class,
];
