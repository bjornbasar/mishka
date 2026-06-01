<?php

declare(strict_types=1);

/**
 * v0.6.0 — CLI container bindings.
 *
 * Loaded by karhu's bin/karhu (v0.1.2+) BEFORE the dispatcher runs. Mirrors
 * the DI wiring in public/index.php but only for the deps CLI commands
 * (push:scan, push:worker, migrate, push:generate-keys) actually use.
 *
 * Returns a callable(Container): void so the container is constructed by
 * karhu's bin/karhu — keeps the contract narrow + lets karhu add Container
 * features (like decorators) without touching every host app's config.
 *
 * Auto-wired classes (repos, controllers — anything with a concrete-class
 * ctor) don't need bindings here: the v0.1.2 fallback resolver finds them
 * via auto-wiring. Only register what auto-wire can't figure out:
 *   - interfaces (QueueInterface, ClockInterface)
 *   - value objects built from .env (Connection, VapidConfig)
 *   - 3rd-party concretes that need scalar args (WebPush, PushSender)
 */

use App\Clock\ClockInterface;
use App\Clock\SystemClock;
use App\Push\PushSender;
use App\Push\VapidConfig;
use Dotenv\Dotenv;
use Karhu\Container\Container;
use Karhu\Db\Connection;
use Karhu\Queue\DatabaseQueue;
use Karhu\Queue\QueueInterface;
use Minishlink\WebPush\WebPush;

return static function (Container $container): void {
    // .env load — bin/karhu doesn't load it itself; host owns the .env path
    // convention. cwd is the mishka project root when called from `composer
    // karhu …` or `vendor/bjornbasar/karhu/bin/karhu …`.
    $cwd = getcwd() ?: __DIR__ . '/..';
    Dotenv::createImmutable($cwd)->safeLoad();

    // Factories receive the Container as the first arg (karhu v0.1.3+).
    // Avoids `use ($container)` capture noise — same pattern as PHP-DI /
    // league/container. Connection + VapidConfig don't need the injected
    // container (no recursive get()s), but take it for signature consistency.

    // Connection — every command that touches the DB autowires this.
    $container->factory(Connection::class, static function (Container $_c) {
        $dsn = $_ENV['DB_DSN'] ?? getenv('DB_DSN');
        if (!is_string($dsn) || $dsn === '') {
            throw new \RuntimeException('DB_DSN not set in environment or .env');
        }
        return new Connection(
            $dsn,
            (string) ($_ENV['DB_USER'] ?? getenv('DB_USER') ?: ''),
            (string) ($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: ''),
        );
    });

    // Interface bindings — these can't auto-wire from a type hint alone.
    $container->factory(
        QueueInterface::class,
        static fn(Container $c) => new DatabaseQueue($c->get(Connection::class)),
    );
    $container->factory(
        // Some callers type-hint the concrete; map both to the same instance.
        DatabaseQueue::class,
        static fn(Container $c) => $c->get(QueueInterface::class),
    );
    $container->bind(ClockInterface::class, SystemClock::class);

    // VAPID config — boot-built from .env, mirrors public/index.php.
    $container->factory(VapidConfig::class, static function (Container $_c) {
        return new VapidConfig(
            (string) ($_ENV['VAPID_PUBLIC_KEY'] ?? ''),
            (string) ($_ENV['VAPID_PRIVATE_KEY'] ?? ''),
            (string) ($_ENV['VAPID_SUBJECT'] ?? ''),
        );
    });

    // PushSender — wraps a WebPush configured with the VAPID keypair.
    $container->factory(PushSender::class, static function (Container $c) {
        /** @var VapidConfig $vapid */
        $vapid = $c->get(VapidConfig::class);
        return new PushSender(new WebPush(['VAPID' => $vapid->forWebPush()], timeout: 5));
    });
};
