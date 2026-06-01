<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Clock\ClockInterface;

/**
 * Test fixture for App\Clock\ClockInterface — returns whatever DateTimeImmutable
 * the test pins. Use to drive PushScanCommand's 07:30–08:30 household-tz
 * window without waiting for real wall-clock time.
 */
final class FixedClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $fixed) {}

    public function now(): \DateTimeImmutable
    {
        return $this->fixed;
    }

    public function set(\DateTimeImmutable $time): void
    {
        $this->fixed = $time;
    }
}
