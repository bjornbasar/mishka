<?php

declare(strict_types=1);

namespace App\Push;

/**
 * v0.6.0 — VAPID keypair + operator-contact subject, boot-built once.
 *
 * The values come from `.env` (validated by public/index.php at startup) and
 * are passed to:
 *   - PushSender — uses publicKey + privateKey + subject to authenticate
 *     pushes to FCM / Mozilla / Apple / Microsoft via minishlink/web-push.
 *   - NotificationsController — exposes publicKey on /me/notifications so
 *     the client `pushManager.subscribe()` call can present it as
 *     applicationServerKey.
 *
 * The PRIVATE key never leaves the server. The PUBLIC key is shared with
 * every subscribed browser by design (that's how the protocol works).
 *
 * Subject must match `^(mailto:[^@\s]+@[^@\s]+|https?://)` per RFC 8292 §2.1
 * (boot-validated upstream). Push services email this address if your
 * endpoint behaves badly — keep it monitored.
 */
final class VapidConfig
{
    public function __construct(
        public readonly string $publicKey,
        public readonly string $privateKey,
        public readonly string $subject,
    ) {}

    /**
     * Shape used by minishlink/web-push v9: an inner `VAPID` array with the
     * three keys. Pass this to `new WebPush(['VAPID' => $vapid->forWebPush()])`.
     *
     * @return array{subject: string, publicKey: string, privateKey: string}
     */
    public function forWebPush(): array
    {
        return [
            'subject' => $this->subject,
            'publicKey' => $this->publicKey,
            'privateKey' => $this->privateKey,
        ];
    }
}
