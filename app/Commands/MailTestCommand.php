<?php

declare(strict_types=1);

namespace App\Commands;

use App\Mail\Mailer;
use Karhu\Attributes\Command;

/**
 * v0.7.5 — mail:test --to=<email>
 *
 * Smoke-test the outbound SMTP transport end-to-end. Dispatches a minimal
 * "delivery test" message through App\Mail\Mailer::sendTest to the address
 * given via --to.
 *
 * Use post-deploy after swapping MAILER_DSN or after DNS changes, to
 * confirm real-world delivery WITHOUT registering a user + walking the
 * verification link. See DOCS.md decision #67.
 *
 * Exit codes:
 *   0  Mailer accepted the send (delivered to the relay; not the same as
 *      delivered to the recipient — bounces surface only in Workspace
 *      admin console → Reports → Email Log Search).
 *   1  Mailer::send returned false — a Symfony transport exception was
 *      caught and error_log-ed. Check `docker logs mishka-app --since 2m`.
 *   2  --to missing or not a valid email (input validation, no send attempted).
 *
 * Sending capacity is bounded by Workspace's 10k/day/domain limit and by
 * the IP-allowlist on the relay rule. Any operator with shell on Bosco can
 * invoke this — not for use in scripts that take unsanitised --to input.
 */
final class MailTestCommand
{
    public function __construct(private readonly Mailer $mailer) {}

    /** @param array<string, string|true> $args */
    #[Command('mail:test', 'Smoke-test outbound SMTP by sending a test message to --to=<email>')]
    public function handle(array $args): int
    {
        // --to is required; the karhu CLI parser gives us $args['to'] as a
        // string for --to=foo@example.com. Bare --to (no value) comes in as
        // `true` — handled by the is_string() guard.
        $to = is_string($args['to'] ?? null) ? (string) $args['to'] : '';
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            fwrite(\STDERR, "mail:test: missing or invalid --to=<email> (required)\n");
            return 2;
        }

        if ($this->mailer->sendTest($to)) {
            fwrite(\STDOUT, "mail:test: sent to {$to}\n");
            return 0;
        }

        fwrite(\STDERR, "mail:test: FAILED to {$to} — check container logs for Symfony transport error\n");
        return 1;
    }
}
