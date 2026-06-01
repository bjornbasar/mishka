<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Push\PushSender;

/**
 * Test-side replacement for App\Push\PushSender. Records every sendTo() call
 * into a public array; never touches the real transport.
 *
 * Mirrors RecordingMailer (v0.5): ctor takes no args so the parent's readonly
 * WebPush dep stays unset (never accessed because sendTo is overridden).
 *
 * Set $nextResult to control what sendTo returns — defaults to success.
 * For the dead-subscription test, set ['success' => false, 'dead' => true].
 *
 * @phpstan-ignore-next-line — intentionally skipping parent::__construct().
 */
class RecordingPushSender extends PushSender
{
    /**
     * @var list<array{endpoint: string, payload: array<string, mixed>}>
     */
    public array $sent = [];

    /**
     * @var array{success: bool, dead: bool, reason: string}
     */
    public array $nextResult = ['success' => true, 'dead' => false, 'reason' => ''];

    public function __construct() {}

    public function sendTo(array $subscription, array $payload): array
    {
        $this->sent[] = [
            'endpoint' => (string) $subscription['endpoint'],
            'payload' => $payload,
        ];
        return $this->nextResult;
    }
}
