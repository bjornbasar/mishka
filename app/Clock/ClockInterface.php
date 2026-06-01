<?php

declare(strict_types=1);

namespace App\Clock;

/**
 * v0.6.0 — testable wall-clock.
 *
 * The PushScanCommand's 07:30–08:30 household-tz digest window is
 * impossible to unit-test against `new DateTimeImmutable('now')` — the test
 * would have to run between those times to assert the in-window path. With
 * an injected clock, FixedClock returns the timestamp the test wants and
 * the production wiring just constructs SystemClock.
 *
 * Returns DateTimeImmutable so callers control timezone explicitly via
 * setTimezone() — the clock itself doesn't pick a zone.
 */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
