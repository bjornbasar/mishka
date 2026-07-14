<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;

/**
 * v0.6.3: PWA manifest validity + cross-surface consistency.
 *
 * No DB, no HTTP. Pure file-on-disk assertions that catch:
 *   - manifest file missing or invalid JSON
 *   - declared icons missing or wrong dimensions
 *   - palette drift between layout.twig CSS vars, the <meta name="theme-color">
 *     in layout.twig, and the manifest theme_color / background_color
 *   - accidental routing of /manifest.webmanifest through index.php (which
 *     would needlessly start a session + emit Set-Cookie for the static asset)
 */
final class ManifestTest extends TestCase
{
    private const PUBLIC_DIR = __DIR__ . '/../../public';
    private const MANIFEST_PATH = self::PUBLIC_DIR . '/manifest.webmanifest';
    private const LAYOUT_PATH = __DIR__ . '/../../templates/layout.twig';

    public function test_manifest_file_is_valid_json_with_required_keys(): void
    {
        self::assertFileExists(self::MANIFEST_PATH, 'public/manifest.webmanifest is missing');

        $raw = file_get_contents(self::MANIFEST_PATH);
        self::assertIsString($raw);

        $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        foreach (['name', 'short_name', 'start_url', 'scope', 'display', 'icons'] as $key) {
            self::assertArrayHasKey($key, $manifest, "manifest missing required key '$key'");
            self::assertNotEmpty($manifest[$key], "manifest key '$key' is empty");
        }

        self::assertSame('standalone', $manifest['display']);
        self::assertSame('/', $manifest['start_url']);
        self::assertSame('/', $manifest['scope']);
    }

    public function test_manifest_icons_exist_on_disk_at_declared_sizes(): void
    {
        $manifest = json_decode(file_get_contents(self::MANIFEST_PATH), true, flags: JSON_THROW_ON_ERROR);

        self::assertNotEmpty($manifest['icons'], 'manifest.icons must not be empty');

        foreach ($manifest['icons'] as $icon) {
            $path = self::PUBLIC_DIR . $icon['src'];
            self::assertFileExists($path, "manifest icon {$icon['src']} not found on disk");

            $size = getimagesize($path);
            self::assertIsArray($size, "{$icon['src']} is not a readable image");
            $declared = $icon['sizes']; // "192x192"
            $actual = $size[0] . 'x' . $size[1];
            self::assertSame($declared, $actual, "{$icon['src']} declared $declared but is actually $actual");
        }
    }

    public function test_manifest_theme_and_background_colors_match_layout(): void
    {
        // 3-way consistency check: CSS var → <meta name="theme-color"> → manifest field.
        // If any one drifts, this test fires with a message naming all three so
        // the fix is obvious (update the two that disagree).
        $layout = file_get_contents(self::LAYOUT_PATH);
        $manifest = json_decode(file_get_contents(self::MANIFEST_PATH), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, preg_match('/--accent:\s*(#[0-9a-fA-F]{6})/', $layout, $accentMatch), 'could not find --accent in layout.twig');
        self::assertSame(1, preg_match('/--bg:\s*(#[0-9a-fA-F]{6})/', $layout, $bgMatch), 'could not find --bg in layout.twig');
        self::assertSame(1, preg_match('/<meta name="theme-color" content="(#[0-9a-fA-F]{6})">/', $layout, $metaMatch), 'could not find <meta name="theme-color"> in layout.twig');

        $cssAccent = strtolower($accentMatch[1]);
        $cssBg = strtolower($bgMatch[1]);
        $metaTheme = strtolower($metaMatch[1]);
        $manifestTheme = strtolower($manifest['theme_color']);
        $manifestBg = strtolower($manifest['background_color']);

        self::assertSame($cssAccent, $metaTheme, "layout.twig --accent ($cssAccent) and <meta name=theme-color> ($metaTheme) drifted; update one or the other");
        self::assertSame($cssAccent, $manifestTheme, "layout.twig --accent ($cssAccent), <meta name=theme-color> ($metaTheme), and manifest.theme_color ($manifestTheme) drifted; update all to match");
        self::assertSame($cssBg, $manifestBg, "layout.twig --bg ($cssBg) and manifest.background_color ($manifestBg) drifted; update one or the other");
    }

    public function test_htaccess_serves_existing_files_directly(): void
    {
        // Guards against a regression where someone removes the .htaccess
        // file-on-disk shortcut. Without that rule, /manifest.webmanifest +
        // every icon would route through index.php → start a session →
        // emit Set-Cookie on a static asset. The shortcut is what keeps the
        // manifest cheap to serve and session-cookie-free.
        $htaccess = file_get_contents(__DIR__ . '/../../public/.htaccess');
        self::assertIsString($htaccess);

        // The canonical short-circuit: if REQUEST_FILENAME exists on disk
        // (file or directory), end the rewrite chain with [L]. Match either
        // ordering of the two RewriteConds.
        self::assertMatchesRegularExpression(
            '/RewriteCond\s+%\{REQUEST_FILENAME\}\s+-[fd]/',
            $htaccess,
            '.htaccess must keep the file-exists short-circuit so static assets bypass index.php',
        );
    }

    // v0.8.4 — PWA shortcuts (Android/Chromium home-screen long-press).

    public function test_manifest_has_three_shortcuts_pointing_at_health_routes(): void
    {
        $manifest = $this->manifest();
        self::assertArrayHasKey('shortcuts', $manifest);
        self::assertIsArray($manifest['shortcuts']);
        self::assertCount(3, $manifest['shortcuts']);

        $urls = array_column($manifest['shortcuts'], 'url');
        self::assertSame(['/health/log/food', '/health/log/exercise', '/health'], $urls);

        foreach ($manifest['shortcuts'] as $sc) {
            self::assertArrayHasKey('name', $sc);
            self::assertArrayHasKey('short_name', $sc);
            self::assertArrayHasKey('description', $sc);
            self::assertArrayHasKey('icons', $sc);
            self::assertNotEmpty($sc['icons']);
        }
    }

    public function test_shortcut_icons_are_all_precached(): void
    {
        // Plan-agent SHOULD-FIX #10 fold — extend the existing
        // manifest-icons-must-be-precached invariant (asserted by
        // ServiceWorkerStructureTest::test_manifest_icons_match_precache
        // for top-level icons[]) to cover nested shortcuts[].icons[].
        // Prevents a v0.8.4.1 "distinct shortcut icons" work item from
        // silently adding an icon that isn't in the SW's PRECACHE_URLS.
        $manifest = $this->manifest();
        $precached = ['/icon-192.png', '/icon-512.png', '/icon-512-maskable.png', '/apple-touch-icon.png'];
        foreach ($manifest['shortcuts'] as $sc) {
            foreach ($sc['icons'] as $icon) {
                self::assertContains(
                    $icon['src'],
                    $precached,
                    "shortcut icon {$icon['src']} MUST be in PRECACHE_URLS or offline shortcuts break",
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $raw = file_get_contents(__DIR__ . '/../../public/manifest.webmanifest');
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
