<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Commands\PushWorkerCommand;
use App\Push\PushSender;
use App\Push\VapidConfig;
use Karhu\Cli\CommandDispatcher;
use Karhu\Container\Container;
use Karhu\Db\Connection;
use Karhu\Queue\DatabaseQueue;
use Karhu\Queue\QueueInterface;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.18 — worker bootstrap regression guard. Completes the DOCS #57
 * follow-up that deferred the worker side of the v0.6.16 web bootstrap
 * regression guards (tests/Smoke/BootstrapSmokeTest).
 *
 * The worker path (mishka-worker container running
 * `php vendor/bjornbasar/karhu/bin/karhu push:worker`) is NOT a single
 * extractable bootstrap file — it's split across
 * vendor/bjornbasar/karhu/bin/karhu (autoload + container-config load +
 * CommandDispatcher scan + dispatch) and mishka's config/container.php
 * (5 DI factories) + config/commands.php (6-class registry) +
 * app/Commands/PushWorkerCommand.php.
 *
 * This test replays what bin/karhu does up to (but NOT including)
 * dispatch(), because dispatching on push:worker would invoke
 * $worker->run() which blocks forever. The load-bearing assertion is
 * that every command class in config/commands.php auto-wires through
 * the container without throwing — proving all ctor dependency chains
 * resolve cleanly. A namespace collision in container.php, a renamed
 * class in commands.php, or a broken ctor on any command surfaces here.
 *
 * NOT #[RunInSeparateProcess]: unlike public/bootstrap.php which
 * registers a process-global karhu ExceptionHandler at line 84, the
 * worker boot path NEVER registers that handler (verified across
 * bin/karhu + container.php + PushWorkerCommand). No process-global
 * side effect to isolate. Saves ~1s overhead per CI run.
 *
 * Reuses v0.6.17 .env stub touch+unlink pattern: config/container.php
 * line 40 calls Dotenv::safeLoad which emits an E_WARNING on missing
 * .env (the GitHub-hosted CI runner doesn't have one). With
 * failOnWarning="true" in phpunit.xml.dist, the warning fails the test.
 * The idempotent touch+unlink fixes it the same way BootstrapSmokeTest
 * already does.
 *
 * VAPID test fixtures: same real-shape keys as BootstrapSmokeTest —
 * generated 2026-06-16 via
 * `vendor/bjornbasar/karhu/bin/karhu push:generate-keys`, never used to
 * sign real pushes. WebPush::__construct (instantiated by
 * config/container.php's PushSender factory) calls VAPID::validate
 * which asserts strlen===65 (public) and strlen===32 (private) after
 * base64url-decode.
 */
final class WorkerBootstrapSmokeTest extends TestCase
{
    private const TEST_VAPID_PUBLIC_KEY = 'BI-s9cp1HaRA16kLlgb92qUc9qggidQ6UywM_LXqd5nBbo3q1MW5C1mRYdHdD8ZsuSqMU9aDj3_4Ke-WaPnMhWI';
    private const TEST_VAPID_PRIVATE_KEY = '_BKitMOcB-Lcpr9Iog6YJwE8ZeJfkHm6-gvS-DTj0V8';

    public function test_cli_bootstrap_resolves_all_commands_without_throwing(): void
    {
        $_ENV['DB_DSN'] = 'sqlite::memory:';
        $_ENV['VAPID_PUBLIC_KEY'] = self::TEST_VAPID_PUBLIC_KEY;
        $_ENV['VAPID_PRIVATE_KEY'] = self::TEST_VAPID_PRIVATE_KEY;
        $_ENV['VAPID_SUBJECT'] = 'mailto:test@example.com';
        // v0.7.5 — the Mailer factory in config/container.php validates
        // MAIL_FROM_ADDRESS with FILTER_VALIDATE_EMAIL at factory-call
        // time. MailTestCommand's ctor takes Mailer, so resolving the
        // command through the container triggers the factory. Without
        // these stubs the smoke test errors on Mailer construction.
        // Same pattern as BootstrapSmokeTest lines 66-68.
        $_ENV['MAIL_FROM_ADDRESS'] = 'test@example.com';
        $_ENV['MAIL_FROM_NAME'] = 'Mishka Den Test';
        $_ENV['MAILER_DSN'] = 'null://null';

        $cwd = dirname(__DIR__, 2);
        $envPath = $cwd . '/.env';
        $envWeCreated = false;
        if (!file_exists($envPath)) {
            touch($envPath);
            $envWeCreated = true;
        }

        $prevCwd = getcwd();
        chdir($cwd);

        try {
            // Mirror bin/karhu lines 38-48: load the container config and
            // apply it to a fresh Container.
            $configured = require $cwd . '/config/container.php';
            self::assertIsCallable(
                $configured,
                'config/container.php must return a callable(Container)',
            );

            $container = new Container();
            $configured($container);

            // Verify the 5 explicit factory bindings resolve. Redundant
            // with the per-command loop below (which would transitively
            // construct these) but kept for clearer failure messages if
            // a factory itself breaks.
            self::assertInstanceOf(Connection::class, $container->get(Connection::class));
            self::assertInstanceOf(DatabaseQueue::class, $container->get(QueueInterface::class));
            self::assertInstanceOf(VapidConfig::class, $container->get(VapidConfig::class));
            self::assertInstanceOf(PushSender::class, $container->get(PushSender::class));

            // Mirror bin/karhu lines 58-64: load the commands registry and
            // resolve every entry via the container. This is the
            // load-bearing assertion — a namespace bug, renamed class, or
            // broken ctor anywhere in the command registry throws here.
            $commands = require $cwd . '/config/commands.php';
            self::assertIsArray($commands);
            self::assertNotEmpty($commands);

            foreach ($commands as $commandClass) {
                $instance = $container->get($commandClass);
                self::assertInstanceOf(
                    $commandClass,
                    $instance,
                    "Command {$commandClass} should auto-wire via container",
                );
            }

            // Explicitly assert PushWorkerCommand — the eponymous worker
            // target. Catches any future refactor that decouples it from
            // the auto-wirable path.
            self::assertInstanceOf(
                PushWorkerCommand::class,
                $container->get(PushWorkerCommand::class),
            );

            // CommandDispatcher + scanCommands mirror bin/karhu lines
            // 50-64. scanCommands does attribute reflection on each
            // class (verified lazy for instantiation, eager for attribute
            // discovery in karhu v0.1.3) — a broken #[Command(...)]
            // attribute surfaces here. Do NOT call dispatch() — push:worker
            // would block on $worker->run() forever.
            $dispatcher = new CommandDispatcher($container);
            $dispatcher->scanCommands($commands);
            self::assertNotEmpty(
                $dispatcher->commands(),
                'scanCommands should populate the dispatcher registry',
            );
            self::assertContains(
                'push:worker',
                array_keys($dispatcher->commands()),
                'push:worker command should be registered in the dispatcher',
            );
        } finally {
            if ($prevCwd !== false) {
                chdir($prevCwd);
            }
            if ($envWeCreated && file_exists($envPath)) {
                unlink($envPath);
            }
        }
    }
}
