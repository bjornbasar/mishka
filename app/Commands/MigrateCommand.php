<?php

declare(strict_types=1);

namespace App\Commands;

use Dotenv\Dotenv;
use Karhu\Attributes\Command;
use Karhu\Db\Connection;

/**
 * Applies db/schema.sql to the configured database.
 *
 * Idempotent — every CREATE / INSERT uses IF NOT EXISTS / ON CONFLICT.
 *
 * Builds its own Connection from .env: the CLI dispatcher uses a fresh
 * container so public/index.php's DI wiring isn't visible here.
 */
final class MigrateCommand
{
    /**
     * @param array<string, string|true> $args --schema=path overrides db/schema.sql
     */
    #[Command('migrate', 'Apply db/schema.sql to the configured database')]
    public function handle(array $args): int
    {
        $cwd = getcwd() ?: __DIR__;
        $schemaPath = is_string($args['schema'] ?? null)
            ? (string) $args['schema']
            : $cwd . '/db/schema.sql';

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

        try {
            $db = new Connection($dsn, $user, $pass);
            $db->pdo()->exec($schema);
        } catch (\PDOException $e) {
            fwrite(\STDERR, "Migration failed: {$e->getMessage()}\n");
            return 1;
        }

        fwrite(\STDOUT, "Schema applied to {$dsn}.\n");
        return 0;
    }
}
