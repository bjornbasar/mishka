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
    // v0.6.9 — SIGKILL recovery
    App\Commands\JobsUnstickCommand::class,
    // v0.6.13 — badge persistence
    App\Commands\BadgesBackfillCommand::class,
    // v0.7.5 — outbound-mail smoke test
    App\Commands\MailTestCommand::class,
    // v0.8.0 — tracker food library seed
    App\Commands\TrackerSeedFoodsCommand::class,
    // v0.8.1 — tracker exercise catalog seed
    App\Commands\TrackerSeedExercisesCommand::class,
    // v0.8.3 — tracker effort badges backfill
    App\Commands\TrackerBadgesBackfillCommand::class,
];
