<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * v0.6.0 — payload shape for the karhu-queue `SendPushNotification` job.
 *
 * Just a constant + a builder helper. The actual data lives in the
 * karhu-queue `jobs.data` JSON column; this class is the contract producers
 * and consumers agree on:
 *
 *   PushScanCommand   ::push  → ['user_id', 'title', 'body', 'url']
 *   PushWorkerCommand ::handle ← ['user_id', 'title', 'body', 'url']
 *
 * Title is capped to 100 chars + body to 200 chars at the PushSender layer
 * (H5); producers can pass any string and trust it'll be truncated cleanly.
 */
final class SendPushNotificationJob
{
    /** karhu-queue job name (the producer pushes this string). */
    public const NAME = 'SendPushNotification';

    /**
     * @return array{user_id: int, title: string, body: string, url: string}
     */
    public static function payload(int $userId, string $title, string $body, string $url = '/'): array
    {
        return [
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ];
    }
}
