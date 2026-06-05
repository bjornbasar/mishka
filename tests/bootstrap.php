<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Karhu\Db\Connection;

/**
 * Test bootstrap — applies the PostgreSQL production schema to an in-memory
 * SQLite database via regex substitution. One source of truth (`db/schema.sql`);
 * tests transform-on-load so every schema change propagates without duplication.
 *
 * The Connection is stored in $GLOBALS['test_db'] for AppTestCase to pick up.
 */

$db = new Connection('sqlite::memory:');
$db->pdo()->exec('PRAGMA foreign_keys = ON');

$schemaPath = __DIR__ . '/../db/schema.sql';
$schema = file_get_contents($schemaPath);
if ($schema === false) {
    fwrite(\STDERR, "Could not read {$schemaPath}\n");
    exit(1);
}

// v0.6.6: drop the PG-only forward-migration block. SQLite tests recreate the
// schema from scratch per test run, so the CREATE TABLE statements above
// already encode the latest column set + CHECK. SQLite doesn't support
// `ALTER TABLE ADD COLUMN IF NOT EXISTS` nor `ALTER TABLE DROP/ADD CONSTRAINT`,
// so the PG-only block would error out on `exec()`.
//
// Convention: ONE PG_ONLY block per file, at EOF. Non-greedy + /s flag handles
// one block per match; PHP's preg_replace replaces all non-overlapping matches
// by default, so multi-block schemas degrade gracefully.
$schema = preg_replace('/-- BEGIN PG_ONLY.*?-- END PG_ONLY/s', '', $schema);

// Translate PostgreSQL-only syntax to the SQLite-compatible subset.
// Order matters: strtr() with an array argument matches longest-key-first, so
// `TIMESTAMPTZ` is rewritten before the more general `TIMESTAMP` rule fires.
$schema = strtr($schema, [
    'SERIAL PRIMARY KEY' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'TIMESTAMPTZ'        => 'TEXT',
    'TIMESTAMP'          => 'TEXT',     // v0.3: events.starts_at_local / ends_at_local
    'DEFAULT NOW()'      => 'DEFAULT CURRENT_TIMESTAMP',
]);

$db->pdo()->exec($schema);

$GLOBALS['test_db'] = $db;
