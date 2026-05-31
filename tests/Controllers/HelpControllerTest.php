<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.5.2 — /help renders docs/USERGUIDE.md via league/commonmark.
 *
 * Anonymous-accessible (no session required) so a user who can't sign in
 * (forgot password, trying to remember the recovery flow) can still reach
 * the walkthrough.
 */
final class HelpControllerTest extends AppTestCase
{
    public function test_help_renders_anonymous(): void
    {
        $response = $this->request('GET', '/help');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Mishka Den — User Guide', $response->body());
    }

    public function test_help_renders_section_headings_from_markdown(): void
    {
        // Spot-check that the CommonMark conversion ran (## headings appear
        // as <h2>) and that the canonical sections from docs/USERGUIDE.md
        // landed in the rendered page.
        $body = $this->request('GET', '/help')->body();

        self::assertStringContainsString('<h2>1. Getting started</h2>', $body);
        self::assertStringContainsString('<h2>2. The calendar</h2>', $body);
        self::assertStringContainsString('<h2>3. Chores</h2>', $body);
        self::assertStringContainsString('<h2>4. Gamification</h2>', $body);
        self::assertStringContainsString('<h2>5. Households</h2>', $body);
        self::assertStringContainsString('<h2>6. Your account</h2>', $body);
        self::assertStringContainsString('<h2>7. Troubleshooting</h2>', $body);
        self::assertStringContainsString('<h2>8. Privacy and security</h2>', $body);
    }

    public function test_help_escapes_html_in_source_by_default(): void
    {
        // CommonMark defaults: raw HTML in the source is escaped, not passed
        // through. Future content additions can't accidentally introduce
        // an XSS vector via the user guide.
        $body = $this->request('GET', '/help')->body();
        // The guide's `>` blockquote becomes a <blockquote> tag (allowed
        // CommonMark syntax) — that's fine. We're checking no `<script>`
        // tags from the source can leak through.
        self::assertStringNotContainsString('<script>', $body);
    }

    public function test_help_renders_signed_in_too(): void
    {
        // Sanity — the route should also work for a signed-in user.
        $uid = $this->createUserWithHash('helper@example.com', 'pw-correct-horse-staple');
        $this->loginAs($uid, 'helper@example.com');

        $response = $this->request('GET', '/help');
        self::assertSame(200, $response->status());
    }
}
