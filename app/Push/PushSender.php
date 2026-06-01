<?php

declare(strict_types=1);

namespace App\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * v0.6.0 — application-level wrapper around minishlink/web-push.
 *
 * Contract:
 *   - sendTo(subscription, payload) returns a small SendResult-shaped array:
 *     {success: bool, dead: bool}.
 *   - `dead = true` means HTTP 410 from the push service — the subscription
 *     is permanently gone and the worker should `markRevoked` it.
 *   - `success = false, dead = false` is a transient error (5xx / network);
 *     worker logs + leaves the row alone.
 *
 * NOT `final` (round-4 H-3 pattern, same as v0.5's Mailer) — RecordingPushSender
 * extends this in tests so the recording fake can replace the real transport
 * without mocking Minishlink internals.
 *
 * Payload caps (H5):
 *   - title truncated to 100 chars
 *   - body  truncated to 200 chars
 *   - url is the click-action target (already short)
 *   These defend against user-supplied long titles blowing past the 4KB push
 *   payload limit + keep notifications readable on phone lock screens.
 */
class PushSender
{
    /** Title/body truncation limits (H5). */
    private const TITLE_MAX = 100;
    private const BODY_MAX = 200;

    public function __construct(
        private readonly WebPush $webPush,
    ) {}

    /**
     * Send a single notification. Returns a small shape the worker uses to
     * decide markRevoked vs touch vs log.
     *
     * @param array{endpoint: string, p256dh: string, auth: string} $subscription
     * @param array{title: string, body: string, url?: string} $payload
     * @return array{success: bool, dead: bool, reason: string}
     */
    public function sendTo(array $subscription, array $payload): array
    {
        $sub = Subscription::create([
            'endpoint' => $subscription['endpoint'],
            'publicKey' => $subscription['p256dh'],
            'authToken' => $subscription['auth'],
        ]);

        $payloadJson = json_encode([
            'title' => mb_substr($payload['title'], 0, self::TITLE_MAX),
            'body'  => mb_substr($payload['body'],  0, self::BODY_MAX),
            'url'   => (string) ($payload['url'] ?? '/'),
        ], JSON_THROW_ON_ERROR);

        try {
            $report = $this->webPush->sendOneNotification($sub, $payloadJson);
        } catch (\Throwable $e) {
            // Transport-level failure (network, malformed VAPID, etc.). Surface
            // as transient — worker logs + retries next cron tick if it cares.
            return ['success' => false, 'dead' => false, 'reason' => $e->getMessage()];
        }

        if ($report->isSuccess()) {
            return ['success' => true, 'dead' => false, 'reason' => ''];
        }

        // Push service rejected. Subscription-expired (HTTP 410) means the
        // browser has uninstalled / revoked / cleared site data; nothing
        // will ever make the endpoint work again. Mark dead.
        return [
            'success' => false,
            'dead' => $report->isSubscriptionExpired(),
            'reason' => $report->getReason(),
        ];
    }
}
