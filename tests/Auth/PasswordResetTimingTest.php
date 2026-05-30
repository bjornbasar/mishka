<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Controllers\PasswordResetController;
use App\Tests\AppTestCase;
use Karhu\Http\Request;

/**
 * Round-4 H-4 regression — the production 1.5s timing floor.
 *
 * The bulk of PasswordResetControllerTest runs against a 50ms floor (set in
 * AppTestCase) to keep the suite fast. This test specifically constructs a
 * controller with the PRODUCTION floor (1.5s) and verifies that both the hit
 * AND miss paths spend at least ~1.4s of wall-clock time, closing the timing
 * enumeration channel (B4).
 *
 * Only runs 2 assertions; total runtime ~3 seconds.
 */
final class PasswordResetTimingTest extends AppTestCase
{
    public function test_post_request_takes_at_least_1400ms_on_miss_path(): void
    {
        $controller = $this->buildProductionFloorController();

        $start = hrtime(true);
        $controller->handleRequest($this->buildPostRequest(['email' => 'ghost@example.com']));
        $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);

        // Floor is 1500ms; allow a small budget for clock skew + scheduling.
        self::assertGreaterThanOrEqual(1400, $elapsedMs,
            'Miss-path response was suspiciously fast — B4 floor may be broken.');
    }

    public function test_post_request_takes_at_least_1400ms_on_hit_path(): void
    {
        $this->createUserWithHash('user@example.com', 'old-password-correct-horse', 'User');
        $controller = $this->buildProductionFloorController();

        $start = hrtime(true);
        $controller->handleRequest($this->buildPostRequest(['email' => 'user@example.com']));
        $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);

        self::assertGreaterThanOrEqual(1400, $elapsedMs,
            'Hit-path response was suspiciously fast — B4 floor may be broken.');
    }

    private function buildProductionFloorController(): PasswordResetController
    {
        // Pull the shared deps from the AppTestCase wiring + override the
        // floor with the production value (1_500_000 μs = 1.5s).
        return new PasswordResetController(
            $this->resetTokenRepo,
            $this->userRepo,
            $this->hasher,
            $this->mailer,
            new \App\Mail\UrlBuilder('http://localhost:8080'),
            $this->sendAttemptRepo,
            $this->app->container()->get(\Karhu\View\TwigAdapter::class),
            new \App\View\NavContext($this->householdRepo),
            timingFloorMicros: 1_500_000,
            dummyHash: '',
        );
    }

    /**
     * @param array<string, string> $body
     */
    private function buildPostRequest(array $body): Request
    {
        return new Request(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/password-reset'],
            get: [],
            post: $body,
            body: '',
            headers: ['content-type' => 'application/x-www-form-urlencoded'],
        );
    }
}
