<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;

/**
 * v0.6.7 — service worker structure smoke.
 *
 * No DB, no HTTP. Pure file-on-disk inspection (peer of ManifestTest).
 *
 * Catches refactors that:
 *   - delete the install/activate/fetch handlers
 *   - forget to bump SW_VERSION on a release that touched cached assets
 *   - mistype a precache path
 *   - drop the dev-hostname escape hatch
 *   - regress the v0.6.0 push handler
 *   - add a manifest icon without adding it to PRECACHE_URLS
 *
 * Behavioural tests (offline rendering, version-bump cycle, dev escape hatch
 * actually serving network-only) are manual in TESTPLAN § 5.10 — SW lifecycle
 * isn't PHPUnit-testable.
 */
final class ServiceWorkerStructureTest extends TestCase
{
    private const SW_PATH = __DIR__ . '/../../public/service-worker.js';
    private const PUBLIC_DIR = __DIR__ . '/../../public';
    private const README_PATH = __DIR__ . '/../../README.md';
    private const CONTROLLERS_DIR = __DIR__ . '/../../app/Controllers';
    private const MANIFEST_PATH = __DIR__ . '/../../public/manifest.webmanifest';

    private const EXPECTED_PRECACHE = [
        '/offline',
        '/apple-touch-icon.png',
        '/icon-192.png',
        '/icon-512.png',
        '/icon-512-maskable.png',
        '/manifest.webmanifest',
        '/push-subscribe.js',
    ];

    public function test_sw_file_exists_and_has_version_constant(): void
    {
        self::assertFileExists(self::SW_PATH, 'public/service-worker.js is missing');
        $sw = (string) file_get_contents(self::SW_PATH);
        self::assertSame(1, preg_match(
            "/const\\s+SW_VERSION\\s*=\\s*'mishka-v(\\d+\\.\\d+\\.\\d+)'/",
            $sw,
            $match,
        ), 'SW_VERSION constant missing or malformed');
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $match[1]);
    }

    public function test_sw_version_matches_release(): void
    {
        // Scope-bound regex per adversarial round-2 S1: anchored to `## Status`
        // section so historical "What works in v0.6.0"-style lines elsewhere
        // in README don't false-match.
        $readme = (string) file_get_contents(self::README_PATH);
        self::assertSame(1, preg_match(
            '/## Status\s+\*\*v(\d+\.\d+\.\d+)\*\*/',
            $readme,
            $readmeMatch,
        ), '`## Status` block must begin with `**vX.Y.Z**` in README.md');

        $sw = (string) file_get_contents(self::SW_PATH);
        self::assertSame(1, preg_match(
            "/const\\s+SW_VERSION\\s*=\\s*'mishka-v(\\d+\\.\\d+\\.\\d+)'/",
            $sw,
            $swMatch,
        ));

        self::assertSame(
            $readmeMatch[1],
            $swMatch[1],
            "SW_VERSION ({$swMatch[1]}) must match README ## Status version ({$readmeMatch[1]}). "
            . 'Bump public/service-worker.js SW_VERSION on releases that change precached assets.',
        );
    }

    public function test_cache_name_derives_from_version(): void
    {
        $sw = (string) file_get_contents(self::SW_PATH);
        self::assertMatchesRegularExpression(
            "/const\\s+CACHE_NAME\\s*=\\s*'mishka-cache-'\\s*\\+\\s*SW_VERSION/",
            $sw,
            'CACHE_NAME must derive from SW_VERSION so a version bump cleans old caches',
        );
    }

    public function test_install_handler_present_with_skipwaiting(): void
    {
        $sw = (string) file_get_contents(self::SW_PATH);
        self::assertStringContainsString("addEventListener('install'", $sw);
        self::assertStringContainsString('skipWaiting(', $sw);
    }

    public function test_activate_handler_present_with_clients_claim(): void
    {
        $sw = (string) file_get_contents(self::SW_PATH);
        self::assertStringContainsString("addEventListener('activate'", $sw);
        self::assertStringContainsString('clients.claim(', $sw);
    }

    public function test_fetch_handler_present(): void
    {
        $sw = (string) file_get_contents(self::SW_PATH);
        self::assertStringContainsString("addEventListener('fetch'", $sw);
    }

    public function test_precache_list_includes_expected_assets(): void
    {
        $sw = (string) file_get_contents(self::SW_PATH);
        self::assertSame(1, preg_match(
            '/const\s+PRECACHE_URLS\s*=\s*\[(.*?)\];/s',
            $sw,
            $block,
        ), 'PRECACHE_URLS array literal missing');
        foreach (self::EXPECTED_PRECACHE as $url) {
            self::assertStringContainsString(
                "'$url'",
                $block[1],
                "PRECACHE_URLS must include '$url'",
            );
        }
    }

    public function test_precached_assets_exist_on_disk_or_route(): void
    {
        // Per adversarial round-2 S9: assert the loop ran for all 7 entries.
        $checked = 0;
        foreach (self::EXPECTED_PRECACHE as $url) {
            $checked++;
            if ($url === '/offline') {
                // Route-discovered: grep all controllers for the route attribute.
                $found = false;
                foreach (glob(self::CONTROLLERS_DIR . '/*.php') as $controller) {
                    if (str_contains((string) file_get_contents($controller), "Route('/offline'")) {
                        $found = true;
                        break;
                    }
                }
                self::assertTrue($found, '/offline route handler not found in app/Controllers/');
                continue;
            }
            // Static file: must exist under public/.
            self::assertFileExists(self::PUBLIC_DIR . $url, "Precached asset $url missing from public/");
        }
        self::assertSame(7, $checked, 'Expected to verify exactly 7 precache entries');
    }

    public function test_push_handler_preserved(): void
    {
        // v0.6.7 must not regress the v0.6.0 push functionality.
        $sw = (string) file_get_contents(self::SW_PATH);
        self::assertStringContainsString("addEventListener('push'", $sw);
        self::assertStringContainsString("addEventListener('notificationclick'", $sw);
    }

    public function test_dev_hostname_escape_hatch_present(): void
    {
        // Per adversarial round-2 S8: assert all three pieces — the constant,
        // the IS_DEV variable, and the fetch-handler bail-out. An over-zealous
        // cleanup that drops any one of these breaks the dev workflow.
        $sw = (string) file_get_contents(self::SW_PATH);
        self::assertMatchesRegularExpression('/const\s+DEV_HOSTNAME_RE\s*=\s*\//', $sw);
        self::assertMatchesRegularExpression('/const\s+IS_DEV\s*=/', $sw);
        self::assertMatchesRegularExpression('/if\s*\(\s*IS_DEV\s*\)\s*return/', $sw);
    }

    public function test_manifest_icons_match_precache(): void
    {
        // Per adversarial round-1 S2: every manifest icon src must appear in
        // PRECACHE_URLS, so a future icon addition doesn't silently drop
        // network-only because someone forgot to update the precache list.
        $manifest = json_decode(
            (string) file_get_contents(self::MANIFEST_PATH),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest['icons']);
        foreach ($manifest['icons'] as $icon) {
            $src = $icon['src'];
            self::assertContains(
                $src,
                self::EXPECTED_PRECACHE,
                "Manifest icon $src not in PRECACHE_URLS (test would also need updating)",
            );
        }
    }
}
