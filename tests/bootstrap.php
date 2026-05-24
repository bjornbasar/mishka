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

// Translate PostgreSQL-only syntax to the SQLite-compatible subset.
// Order matters: SERIAL substitution must precede the NOT NULL pass so we
// don't double-process the resulting INTEGER PRIMARY KEY.
$schema = strtr($schema, [
    'SERIAL PRIMARY KEY' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'TIMESTAMPTZ'        => 'TEXT',
    'DEFAULT NOW()'      => "DEFAULT CURRENT_TIMESTAMP",
]);

$db->pdo()->exec($schema);

$GLOBALS['test_db'] = $db;
