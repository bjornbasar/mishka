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
     * v0.6.11 — send the email-change confirmation link to the NEW address.
     * Asymmetric with sendVerification: send-failure here surfaces as a
     * flash_error in the controller (the user explicitly initiated, needs to
     * retry — decision #52). The token row still records sent_at on success.
     */
    public function sendEmailChange(string $toEmail, string $confirmUrl, string $displayName): bool
    {
        return $this->send(
            $toEmail,
            'Confirm your new email — Mishka Den',
            'mail/change_email.txt.twig',
            'mail/change_email.html.twig',
            ['url' => $confirmUrl, 'display_name' => $displayName],
        );
    }

    /**
     * v0.6.11 — send the security-alert notification to the OLD email when
     * the user requests an email change. Defence against session-hijack
     * silently changing the address: legitimate user sees the alert in their
     * OLD inbox even if attacker has the session.
     *
     * Deliberately minimal: NO token, NO full new-email (masked elsewhere),
     * NO cancel link. If the OLD mailbox is compromised, the attacker should
     * not gain leverage to complete or cancel the swap — remediation goes
     * through /me/password (force-PW-change kicks the session).
     */
    public function sendEmailChangeNotification(
        string $toOldEmail,
        string $newEmailMasked,
        string $displayName,
    ): bool {
        return $this->send(
            $toOldEmail,
            'Security alert: email change requested — Mishka Den',
            'mail/change_email_notification.txt.twig',
            'mail/change_email_notification.html.twig',
            ['new_email_masked' => $newEmailMasked, 'display_name' => $displayName],
        );
    }

    /**
     * v0.6.12 — courtesy notification AFTER an account is deleted.
     *
     * Fire-and-forget: the user row is already gone, so SMTP failure can't
     * unwind. This is a detection signal for unauthorised deletion — the
     * legitimate user receives this and can react via support, even though
     * the account itself cannot be restored (no soft-delete; decision #53).
     *
     * Takes $deletedAt as a pre-formatted UTC string so the body can render
     * the exact moment of deletion — useful if the user re-registers with
     * the same email post-delete and receives this courtesy email about
     * the OLD account (round-2 R22 / decision #53).
     *
     * Deliberately minimal: no link, no token, no "click to recover" path.
     * The subject + body are the audit trail.
     */
    public function sendAccountDeletedNotification(
        string $toEmail,
        string $displayName,
        string $deletedAt,
    ): bool {
        return $this->send(
            $toEmail,
            'Your account was deleted — Mishka Den',
            'mail/account_deleted.txt.twig',
            'mail/account_deleted.html.twig',
            ['display_name' => $displayName, 'deleted_at' => $deletedAt],
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
