<?php

declare(strict_types=1);

namespace App\Commands;

use Karhu\Attributes\Command;
use Minishlink\WebPush\VAPID;

/**
 * v0.6.0 — push:generate-keys
 *
 * One-shot. Generates a VAPID keypair via minishlink/web-push's helper +
 * prints three lines ready to paste into `.env`:
 *
 *   VAPID_PUBLIC_KEY=...
 *   VAPID_PRIVATE_KEY=...
 *   VAPID_SUBJECT=mailto:bjorn@minified.work
 *
 * The user runs this once at deploy time, copies the output, and the boot
 * guard takes it from there. The subject is a sensible default; the user
 * should change it to their real operator-contact address.
 */
final class PushGenerateKeysCommand
{
    /**
     * @param array<string, string|true> $args
     */
    #[Command('push:generate-keys', 'Generate a VAPID keypair and print .env-ready lines')]
    public function handle(array $args): int
    {
        $keys = VAPID::createVapidKeys();
        fwrite(\STDOUT, "# Paste these into .env (replace existing VAPID_* lines):\n");
        fwrite(\STDOUT, "VAPID_PUBLIC_KEY={$keys['publicKey']}\n");
        fwrite(\STDOUT, "VAPID_PRIVATE_KEY={$keys['privateKey']}\n");
        fwrite(\STDOUT, "VAPID_SUBJECT=mailto:bjorn@minified.work\n");
        return 0;
    }
}
