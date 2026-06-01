<?php

declare(strict_types=1);

namespace App\Clock;

/**
 * v0.6.0 — production ClockInterface. Returns the actual wall-clock time
 * (UTC, like the rest of mishka's storage convention). Callers convert to
 * household tz at the boundary via DateTimeImmutable::setTimezone().
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
