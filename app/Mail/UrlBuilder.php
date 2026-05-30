<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * v0.5.0 — single source-of-truth for absolute URLs that go into emails.
 *
 * B1 (host-header injection in email links): an unauthenticated request to
 * `/password-reset` with a forged `Host: evil.com` header would, if we built
 * the URL from `$request->header('host')`, mint `https://evil.com/password-reset
 * /<token>` and SMTP it to the victim. Classic password-reset poisoning.
 *
 * Fix: ALL email-bound URLs run through this class, which reads ONLY the
 * boot-time `APP_URL` env var. `public/index.php` fails fast if APP_URL is
 * missing or malformed, so there's no way to construct one of these with an
 * attacker-controlled host.
 *
 * This class deliberately has no method that takes a `$request`.
 */
final class UrlBuilder
{
    public function __construct(private readonly string $appUrl) {}

    /**
     * Return the absolute URL for a path. Handles a trailing-slash mismatch
     * between `$appUrl` and `$path` so callers don't have to think about it.
     */
    public function absoluteUrl(string $path): string
    {
        return rtrim($this->appUrl, '/') . '/' . ltrim($path, '/');
    }
}
