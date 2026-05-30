<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Mail\Mailer;

/**
 * Test fixture for the SMTP-fail path (H2 — `sent_at IS NULL` ops signal +
 * round-4 H-4 timing-equalisation regression). Both public send-* methods
 * return false (NOT throw), mirroring the real Mailer's behaviour: SMTP
 * failures catch `TransportExceptionInterface` and return false; controllers
 * skip `markSent` on the token and the resend flow / banner pick up the
 * unsent-state for ops observability.
 *
 * Returning `false` (rather than throwing) lets us test the controller's
 * fail-soft branch without the test itself catching an exception.
 */
class ThrowingMailer extends Mailer
{
    /** @var list<array{kind: string, to: string, url: string, display_name: string}> */
    public array $attempted = [];

    public function __construct() {}

    public function sendVerification(string $toEmail, string $verifyUrl, string $displayName): bool
    {
        $this->attempted[] = [
            'kind' => 'verification',
            'to' => $toEmail,
            'url' => $verifyUrl,
            'display_name' => $displayName,
        ];
        return false;   // SMTP "failed"
    }

    public function sendPasswordReset(string $toEmail, string $resetUrl, string $displayName): bool
    {
        $this->attempted[] = [
            'kind' => 'password_reset',
            'to' => $toEmail,
            'url' => $resetUrl,
            'display_name' => $displayName,
        ];
        return false;
    }
}
