<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Mail\UrlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * B1 — host-header injection guard.
 *
 * UrlBuilder must build URLs from a SINGLE configured `APP_URL` and never
 * touch request headers. These tests pin the contract.
 */
final class UrlBuilderTest extends TestCase
{
    public function test_builds_absolute_url_from_app_url(): void
    {
        $builder = new UrlBuilder('http://localhost:8080');
        self::assertSame('http://localhost:8080/verify-email/abc', $builder->absoluteUrl('/verify-email/abc'));
    }

    public function test_handles_trailing_slash_on_app_url(): void
    {
        $builder = new UrlBuilder('http://localhost:8080/');
        self::assertSame('http://localhost:8080/verify-email/abc', $builder->absoluteUrl('/verify-email/abc'));
    }

    public function test_handles_missing_leading_slash_on_path(): void
    {
        $builder = new UrlBuilder('http://localhost:8080');
        self::assertSame('http://localhost:8080/verify-email/abc', $builder->absoluteUrl('verify-email/abc'));
    }

    public function test_handles_both_normalisations_together(): void
    {
        $builder = new UrlBuilder('http://localhost:8080/');
        self::assertSame('http://localhost:8080/verify-email/abc', $builder->absoluteUrl('verify-email/abc'));
    }

    public function test_https_app_url_round_trips_unchanged(): void
    {
        $builder = new UrlBuilder('https://mishka.minified.work');
        self::assertSame(
            'https://mishka.minified.work/password-reset/abc123',
            $builder->absoluteUrl('/password-reset/abc123'),
        );
    }
}
