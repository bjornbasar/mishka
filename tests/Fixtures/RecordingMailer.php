<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Mail\Mailer;

/**
 * Test-side replacement for `App\Mail\Mailer`. Records every call into a
 * public array instead of dispatching through Symfony's transport.
 *
 * Round-4 H-3 made `App\Mail\Mailer` non-final specifically so this class can
 * extend it. Tests assert against `$mailer->sent` for both routes:
 *
 *   $mailer->sent[0] === [
 *     'kind' => 'verification' | 'password_reset',
 *     'to' => 'user@example.com',
 *     'url' => 'http://localhost:8080/verify-email/abc...',
 *     'display_name' => 'Bjorn',
 *   ];
 *
 * The ctor takes no args — we override both public send-* methods BEFORE they
 * touch any of the parent's private readonly properties, so it's safe to skip
 * `parent::__construct()`. The properties stay unset; nothing reads them.
 *
 * @phpstan-ignore-next-line — intentionally skipping parent::__construct(),
 *   the parent properties are never read because we override both public methods.
 */
class RecordingMailer extends Mailer
{
    /**
     * Recorded calls, in order. Each entry is one of:
     *   ['kind' => 'verification'|'password_reset'|'email_change'|'email_change_notification',
     *    'to' => string, 'url' => string, 'display_name' => string]
     *
     * For 'email_change_notification', 'url' holds the masked new email
     * (e.g. 'j***@example.com'); the kind disambiguates.
     *
     * @var list<array{kind: string, to: string, url: string, display_name: string}>
     */
    public array $sent = [];

    // No ctor — parent's `private readonly` properties remain unset. The two
    // overridden public methods never call parent::send(), so the unset
    // properties never get read.
    public function __construct() {}

    public function sendVerification(string $toEmail, string $verifyUrl, string $displayName): bool
    {
        $this->sent[] = [
            'kind' => 'verification',
            'to' => $toEmail,
            'url' => $verifyUrl,
            'display_name' => $displayName,
        ];
        return true;
    }

    public function sendPasswordReset(string $toEmail, string $resetUrl, string $displayName): bool
    {
        $this->sent[] = [
            'kind' => 'password_reset',
            'to' => $toEmail,
            'url' => $resetUrl,
            'display_name' => $displayName,
        ];
        return true;
    }

    public function sendEmailChange(string $toEmail, string $confirmUrl, string $displayName): bool
    {
        $this->sent[] = [
            'kind' => 'email_change',
            'to' => $toEmail,
            'url' => $confirmUrl,
            'display_name' => $displayName,
        ];
        return true;
    }

    public function sendEmailChangeNotification(
        string $toOldEmail,
        string $newEmailMasked,
        string $displayName,
    ): bool {
        // 'url' holds the masked new email here — the kind disambiguates.
        $this->sent[] = [
            'kind' => 'email_change_notification',
            'to' => $toOldEmail,
            'url' => $newEmailMasked,
            'display_name' => $displayName,
        ];
        return true;
    }

    public function sendAccountDeletedNotification(
        string $toEmail,
        string $displayName,
        string $deletedAt,
    ): bool {
        // 'url' holds the deleted_at timestamp; the kind disambiguates.
        $this->sent[] = [
            'kind' => 'account_deleted',
            'to' => $toEmail,
            'url' => $deletedAt,
            'display_name' => $displayName,
        ];
        return true;
    }
}
