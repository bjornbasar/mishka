<?php

declare(strict_types=1);

namespace App\Mail;

use Karhu\View\TwigAdapter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * v0.5.0 — the application-level mail sender.
 *
 * Wraps Symfony's `MailerInterface` with two contract guarantees the rest of
 * the app depends on:
 *
 *   1. **Never throws to the caller.** SMTP failures (MailHog down, Postmark
 *      hiccup, etc.) catch `TransportExceptionInterface` and return `false`.
 *      Registration and password-reset request must always complete from the
 *      user's perspective — the token row carries `sent_at IS NULL` for ops
 *      observability (verify) or the always-200 + 1500ms-floor pattern hides
 *      the failure entirely (reset).
 *
 *   2. **Renders both `text/plain` and `text/html` parts.** Plain-text twin
 *      keeps the email accessible in low-fi clients and improves deliverability
 *      (most SMTP reputation systems penalise HTML-only).
 *
 * NON-FINAL (round-4 H-3) because `RecordingMailer` extends it in tests and
 * overrides `sendVerification` / `sendPasswordReset` to skip the transport.
 * A `final class` would force tests to construct full Symfony plumbing or
 * mock the interface manually; subclassing is cleaner here.
 */
class Mailer
{
    public function __construct(
        private readonly MailerInterface $transport,
        private readonly TwigAdapter $twig,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {}

    /**
     * Send the email-verification link. Returns true iff SMTP succeeded —
     * controllers use this to decide whether to call `markSent` on the token.
     */
    public function sendVerification(string $toEmail, string $verifyUrl, string $displayName): bool
    {
        return $this->send(
            $toEmail,
            'Verify your email — Mishka Den',
            'mail/verify_email.txt.twig',
            'mail/verify_email.html.twig',
            ['url' => $verifyUrl, 'display_name' => $displayName],
        );
    }

    /**
     * Send the password-reset link. Always-200 controller pattern doesn't care
     * about the return value (timing floor + body equalisation hide the
     * outcome from the user) but we still log the failure for ops.
     */
    public function sendPasswordReset(string $toEmail, string $resetUrl, string $displayName): bool
    {
        return $this->send(
            $toEmail,
            'Reset your password — Mishka Den',
            'mail/password_reset.txt.twig',
            'mail/password_reset.html.twig',
            ['url' => $resetUrl, 'display_name' => $displayName],
        );
    }

    /**
     * Single send path. Builds the multipart message and dispatches; catches
     * any TransportException and logs it via `error_log` (good enough for v0.5;
     * a real logger DI lands in v0.6+ when the LoggerInterface is plumbed).
     *
     * @param array<string, mixed> $vars
     */
    protected function send(
        string $to,
        string $subject,
        string $textTemplate,
        string $htmlTemplate,
        array $vars,
    ): bool {
        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($to)
            ->subject($subject)
            ->text($this->twig->render($textTemplate, $vars))
            ->html($this->twig->render($htmlTemplate, $vars));

        try {
            $this->transport->send($email);
            return true;
        } catch (TransportExceptionInterface $e) {
            error_log('mishka mailer: SMTP send failed: ' . $e->getMessage());
            return false;
        }
    }
}
