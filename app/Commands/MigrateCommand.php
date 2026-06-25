<?php

declare(strict_types=1);

namespace App\Commands;

use Dotenv\Dotenv;
use Karhu\Attributes\Command;
use Karhu\Db\Connection;

/**
 * Applies db/schema.sql to the configured database with hash-tracking
 * via the `schema_versions` audit table (v0.7.1, DOCS #63).
 *
 * Idempotent — every CREATE / INSERT in schema.sql uses IF NOT EXISTS /
 * ON CONFLICT. The hash-check on top makes re-runs cheap: a no-op when
 * the schema.sql byte-content matches the last recorded apply.
 *
 * Builds its own Connection from .env: the CLI dispatcher uses a fresh
 * container so public/index.php's DI wiring isn't visible here.
 *
 * Exit codes:
 *   0 — schema applied (or already-current no-op)
 *   1 — full failure (DB_DSN missing, schema file missing, apply errored)
 *   2 — schema applied but audit INSERT failed (partial success;
 *       operator must check schema_versions table state)
 *
 * Flags:
 *   --schema=path        override db/schema.sql location
 *   --applied-by=name    audit-row label (default: 'manual'; CI passes
 *                        'ci-deploy')
 *   --force              skip the hash check; apply schema even if
 *                        schema_versions already has a matching row
 */
final class MigrateCommand
{
    /**
     * @param array<string, string|true> $args
     */
    #[Command('migrate', 'Apply db/schema.sql to the configured database (idempotent + tracked)')]
    public function handle(array $args): int
    {
        $cwd = getcwd() ?: __DIR__;
        $schemaPath = is_string($args['schema'] ?? null)
            ? (string) $args['schema']
            : $cwd . '/db/schema.sql';

        $appliedBy = is_string($args['applied-by'] ?? null) ? (string) $args['applied-by'] : 'manual';
        if ($appliedBy === '') {
            // Round-3 C4: --applied-by= (empty value) parses to '' via
            // CommandDispatcher and would land an empty string in the audit
            // row. Guard against it.
            $appliedBy = 'manual';
        }
        $force = ($args['force'] ?? null) === true;

        // Load .env from the project root so DB_DSN / DB_USER / DB_PASS are available.
        Dotenv::createImmutable($cwd)->safeLoad();

        $dsn = $_ENV['DB_DSN'] ?? getenv('DB_DSN');
        if (!is_string($dsn) || $dsn === '') {
            fwrite(\STDERR, "DB_DSN not set in environment or .env\n");
            return 1;
        }

        $user = (string) ($_ENV['DB_USER'] ?? getenv('DB_USER') ?: '');
        $pass = (string) ($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '');

        $schema = @file_get_contents($schemaPath);
        if ($schema === false) {
            fwrite(\STDERR, "Schema file not found: {$schemaPath}\n");
            return 1;
        }

        $schemaHash = hash('sha256', $schema);

        try {
            $db = new Connection($dsn, $user, $pass);
        } catch (\PDOException $e) {
            fwrite(\STDERR, "DB connect failed: {$e->getMessage()}\n");
            return 1;
        }

        // Hash-check: if schema_versions latest row matches the current
        // schema.sql hash, skip the (expensive) apply step. try/catch handles
        // the "schema_versions table doesn't exist yet" case for the FIRST
        // ever run (pre-v0.7.1 DB).
        if (!$force) {
            try {
                $row = $db->fetchOne(
                    'SELECT schema_hash FROM schema_versions ORDER BY applied_at DESC LIMIT 1',
                );
                if ($row !== null && $row['schema_hash'] === $schemaHash) {
                    fwrite(\STDOUT, "Schema already current (hash={$schemaHash}).\n");
                    return 0;
                }
            } catch (\PDOException $e) {
                // Table doesn't exist yet (first run pre-v0.7.1 or fresh DB).
                // Fall through to apply.
            }
        }

        // Apply
        try {
            $db->pdo()->exec($schema);
        } catch (\PDOException $e) {
            fwrite(\STDERR, "Migration failed: {$e->getMessage()}\n");
            return 1;
        }

        // Record audit row. Use Connection::run so it works on both PG +
        // SQLite. Round-2 C3 (user-confirmed): audit-insert failure exits
        // with code 2 (distinct non-fatal "schema applied but audit broken
        // — needs operator attention"). CI fails loudly so the operator
        // investigates.
        try {
            $db->run(
                'INSERT INTO schema_versions (schema_hash, applied_by) VALUES (:hash, :by)',
                ['hash' => $schemaHash, 'by' => $appliedBy],
            );
        } catch (\PDOException $e) {
            fwrite(\STDERR, "Warning: schema apply succeeded but audit insert failed: {$e->getMessage()}\n");
            fwrite(\STDOUT, "Schema applied to {$dsn}. Hash: {$schemaHash}. Audit NOT recorded.\n");
            return 2;
        }

        fwrite(\STDOUT, "Schema applied to {$dsn}. Hash: {$schemaHash}. Recorded by: {$appliedBy}.\n");
        return 0;
    }
}
