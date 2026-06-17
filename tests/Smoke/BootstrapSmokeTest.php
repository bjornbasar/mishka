<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Karhu\App;
use Karhu\Db\Connection;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.16 — bootstrap regression guard. Closes the DOCS #56 follow-up
 * from the v0.6.15 hotfix.
 *
 * Asserts that public/bootstrap.php loads without throwing. Would catch
 * the class of namespace-collision bug that latent-shipped in v0.6.13
 * (`new App\Chores\X` resolving to `Karhu\App\Chores\X` due to
 * `use Karhu\App;` shadowing un-imported relative names) — the autoloader
 * fails at the `new` site and the require throws an Error, which this
 * test catches as a test failure with the literal error message.
 *
 * Complementary to phpstan.neon.dist's `public/` path coverage (added
 * together in v0.6.16): the static analyser catches name-resolution at
 * analyse-time; this runtime test catches anything that passes static
 * analysis but throws at boot (constructor exceptions, env validation,
 * DI ordering, scanControllers reflection failures, argon2id misconfig).
 *
 * Runs in a separate process so ExceptionHandler::register() (a process-
 * global PHP side effect at public/bootstrap.php:83) doesn't leak into
 * the rest of the test suite. PHPUnit re-runs tests/bootstrap.php in
 * the child but that bootstrap doesn't mutate $_ENV — the pre-seed
 * below is preserved by Dotenv::safeLoad()'s immutable semantics.
 *
 * Boot is intentionally slow (~50-200ms) because of the argon2id hash
 * at public/bootstrap.php:250 (the dummyHash factory for timing-attack
 * defence on AuthController + PasswordResetController). Worth the cost
 * — argon2id misconfiguration (missing libsodium etc.) is part of the
 * invariant this test covers. $_SESSION is NOT touched by boot —
 * Session middleware is piped but only the omitted $app->run() would
 * trigger session_start().
 */
final class BootstrapSmokeTest extends TestCase
{
    /**
     * Real-shape VAPID keys generated via `vendor/bjornbasar/karhu/bin/karhu
     * push:generate-keys` on 2026-06-16 — TEST-ONLY, never used to sign
     * real pushes. WebPush::__construct calls VAPID::validate which
     * base64url-decodes the public key and asserts strlen===65 bytes
     * (private===32) — arbitrary stub strings would throw \ErrorException
     * at boot and fail this test for the wrong reason. These literals
     * pass validation cleanly.
     */
    private const TEST_VAPID_PUBLIC_KEY = 'BI-s9cp1HaRA16kLlgb92qUc9qggidQ6UywM_LXqd5nBbo3q1MW5C1mRYdHdD8ZsuSqMU9aDj3_4Ke-WaPnMhWI';
    private const TEST_VAPID_PRIVATE_KEY = '_BKitMOcB-Lcpr9Iog6YJwE8ZeJfkHm6-gvS-DTj0V8';

    #[RunInSeparateProcess]
    public function test_bootstrap_returns_configured_app_without_throwing(): void
    {
        // Seed env BEFORE the require. Dotenv::safeLoad() preserves
        // already-set $_ENV entries (immutable semantics — verified
        // against vlucas/phpdotenv source) so these win even if the
        // project root has a real .env with different values.
        $_ENV['DB_DSN'] = 'sqlite::memory:';
        $_ENV['APP_URL'] = 'http://localhost';
        $_ENV['MAIL_FROM_ADDRESS'] = 'test@example.com';
        $_ENV['MAIL_FROM_NAME'] = 'Mishka Den Test';
        $_ENV['MAILER_DSN'] = 'null://null';
        $_ENV['VAPID_PUBLIC_KEY'] = self::TEST_VAPID_PUBLIC_KEY;
        $_ENV['VAPID_PRIVATE_KEY'] = self::TEST_VAPID_PRIVATE_KEY;
        $_ENV['VAPID_SUBJECT'] = 'mailto:test@example.com';

        // Touch a stub .env if none exists. bootstrap.php registers the
        // karhu ExceptionHandler at line 84 BEFORE calling
        // `Dotenv::createImmutable(...)->safeLoad()` at line 90. safeLoad
        // catches `InvalidPathException` for missing files BUT the
        // underlying `file_get_contents` still emits an E_WARNING, which
        // the karhu handler promotes to ErrorException — bypassing
        // safeLoad's catch. Production always has .env (provisioned by
        // deploy env); local dev has .env; GitHub-hosted CI runners do
        // NOT (correctly — secrets aren't committed). A stub empty .env
        // satisfies `file_get_contents`; the pre-seeded $_ENV above wins
        // anyway (immutable semantics).
        $envPath = dirname(__DIR__, 2) . '/.env';
        $envWeCreated = false;
        if (!file_exists($envPath)) {
            touch($envPath);
            $envWeCreated = true;
        }

        try {
            // The require returns whatever bootstrap.php returns — that
            // is, the configured Karhu\App after all 31 container
            // bindings + middleware pipe + route scan. If the require
            // throws (namespace collision at the `new` site, env
            // validation fail, VAPID validation fail, scanControllers
            // reflection fail), the test fails with the original message.
            $app = require dirname(__DIR__, 2) . '/public/bootstrap.php';

            self::assertInstanceOf(App::class, $app);
            self::assertNotNull($app->router(), 'router should be non-null after scanControllers');
            self::assertInstanceOf(
                Connection::class,
                $app->container()->get(Connection::class),
                'Connection should be pre-set in the container — proves DB-wiring section completed',
            );
        } finally {
            if ($envWeCreated && file_exists($envPath)) {
                unlink($envPath);
            }
        }
    }
}
