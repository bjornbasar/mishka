<?php

declare(strict_types=1);

/**
 * tools/check-docs.php — mishka#26's docs-drift gate for the hub.twobots.dev user guide.
 *
 * Reads karhu's own `#[Route]` attribute directly via reflection — `App\Controllers\*`,
 * read from config/controllers.php (the single source of truth the app itself already
 * maintains for its real router). Deliberately NOT going through
 * `Karhu\Http\Router::scanControllers()`/`routes()`: that pipeline compiles each path into
 * a match regex and does not expose the original `{param}`-form string back out, so
 * recovering a human-readable path from it means reconstructing it from the regex — more
 * moving parts than just reading `Route::$path` off the attribute instance directly, which
 * is exactly the literal string (e.g. `/calendar/events/{id}`) a docs table should claim.
 *
 * Keyed on PATH, not path+method — a GET/POST pair on the same route is one claim, per the
 * issue's own instruction, since a user guide describes a page/action, not an HTTP verb.
 *
 * Two things, each "an ERROR, not a note" (a silently-empty set must fail, never pass
 * vacuously):
 *   1. every real route path is claimed by some guide/*.md page   (undocumented surface)
 *   2. every claimed path still exists on disk                     (stale-documented surface)
 *
 * Claims only come from a STRUCTURED table whose header cell is an exact allowlisted name
 * (never a passing prose mention) — same anti-false-positive convention as the artio/
 * hartza/otso/datvi family of gates, adapted to routes.
 */

require __DIR__ . '/../vendor/autoload.php';

const ROUTE_TABLE_HEADERS = ['route', 'routes'];

function die_with(string $msg): never
{
    fwrite(STDERR, "✗ {$msg}\n");
    exit(1);
}

function log_line(string $msg): void
{
    echo "  {$msg}\n";
}

// ---------------------------------------------------------------- real route surface
$controllers = require __DIR__ . '/../config/controllers.php';

$realRoutes = [];
foreach ($controllers as $class) {
    $ref = new ReflectionClass($class);
    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        foreach ($method->getAttributes(\Karhu\Attributes\Route::class) as $attr) {
            /** @var \Karhu\Attributes\Route $route */
            $route = $attr->newInstance();
            $realRoutes[$route->path] = true;
        }
    }
}
$realRoutes = array_keys($realRoutes);

if (count($realRoutes) === 0) {
    die_with('found zero #[Route] attributes across config/controllers.php — the controller list or the walk is broken');
}

// ---------------------------------------------------------------- structured-table parsing
// Only a table row whose table's header cell matches a ROUTE_TABLE_HEADERS entry counts as
// a claim — a prose mention of a route anywhere else in the page is never enough.
function extractTableClaims(string $markdown): array
{
    $claims = [];
    $lines = explode("\n", $markdown);
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        if (!preg_match('/^\s*\|\s*([^|]+?)\s*\|/', $lines[$i], $m)) {
            continue;
        }
        if (!in_array(strtolower(trim($m[1])), ROUTE_TABLE_HEADERS, true)) {
            continue;
        }
        if (!isset($lines[$i + 1]) || !preg_match('/^\s*\|[\s:|-]+\|\s*$/', $lines[$i + 1])) {
            continue; // separator row
        }
        for ($j = $i + 2; $j < $count; $j++) {
            if (!str_starts_with(trim($lines[$j]), '|')) {
                break;
            }
            if (preg_match('/^\s*\|\s*`([^`]+)`\s*\|/', $lines[$j], $cm)) {
                $claims[] = $cm[1];
            }
        }
    }
    return $claims;
}

// NOT docs/ — that directory is already mishka's internal architecture-docs tree
// (ACCOUNT.md, SCHEMA.md, TRACKER-PLAN.md, etc. — same role as a DOCS.md elsewhere,
// just split across files). The public user guide lives in guide/ instead, and
// mkdocs.yml's docs_dir is set to match, so MkDocs never renders the internal files.
$guideDir = __DIR__ . '/../guide';
$docFiles = is_dir($guideDir) ? glob($guideDir . '/*.md') : [];
if (empty($docFiles)) {
    die_with('guide/ has no markdown files — the drift gate has nothing to check');
}

$claimedRoutes = []; // path => [files]
foreach ($docFiles as $file) {
    $text = file_get_contents($file);
    foreach (extractTableClaims($text) as $route) {
        $claimedRoutes[] = ['route' => $route, 'file' => basename($file)];
    }
}
if (count($claimedRoutes) === 0) {
    die_with('no guide/*.md page claims any route — the table parser is broken');
}

// ---------------------------------------------------------------- both directions
$realSet = array_flip($realRoutes);
$claimedSet = array_flip(array_column($claimedRoutes, 'route'));

$undocumented = array_filter($realRoutes, fn ($r) => !isset($claimedSet[$r]));
if (count($undocumented) > 0) {
    die_with('route(s) with no guide/*.md page claiming them: ' . implode(', ', $undocumented));
}

$stale = array_filter($claimedRoutes, fn ($c) => !isset($realSet[$c['route']]));
if (count($stale) > 0) {
    $msgs = array_map(fn ($c) => "{$c['file']} claims `{$c['route']}`, which no longer exists", $stale);
    die_with(implode('; ', $msgs));
}

log_line(sprintf(
    'every route documented, nothing stale (correct): %d routes, %d claims',
    count($realRoutes),
    count($claimedRoutes),
));

echo "✓ docs-check passed\n";
