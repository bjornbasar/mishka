<?php

declare(strict_types=1);

namespace App\Tests\Commands;

use App\Commands\MigrateCommand;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.7.1 — MigrateCommand unit tests.
 *
 * Each test uses a file-backed temp sqlite DB (NOT sqlite::memory:)
 * because MigrateCommand creates its own Connection inside handle();
 * each call would otherwise see a fresh in-memory DB and the hash-check
 * across two handle() invocations would never see prior state. File-
 * backed sqlite gives persistence across the two PDO connections.
 *
 * Each test seeds a minimal schema.sql via tmpfile so the apply step is
 * cheap (no full mishka schema) and the hash is deterministic.
 *
 * Tests bypass tests/bootstrap.php's $GLOBALS['test_db'] entirely — the
 * MigrateCommand path under test is independent of the wider AppTestCase
 * DI container.
 */
final class MigrateCommandTest extends TestCase
{
    private string $dbPath;
    private string $schemaPath;
    private MigrateCommand $cmd;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'mishka-migrate-test-');
        $this->schemaPath = tempnam(sys_get_temp_dir(), 'mishka-schema-test-');
        $this->cmd = new MigrateCommand();

        $_ENV['DB_DSN'] = 'sqlite:' . $this->dbPath;
        $_ENV['DB_USER'] = '';
        $_ENV['DB_PASS'] = '';

        // Minimal schema: just the schema_versions table itself.
        // SQLite-friendly variant (SERIAL → INTEGER, TIMESTAMPTZ → TEXT,
        // DEFAULT NOW() → DEFAULT CURRENT_TIMESTAMP) mirroring the
        // tests/bootstrap.php translator.
        file_put_contents($this->schemaPath, <<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_versions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                applied_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                schema_hash TEXT NOT NULL,
                applied_by  TEXT NOT NULL DEFAULT 'manual'
            );
            CREATE INDEX IF NOT EXISTS idx_schema_versions_applied_at
                ON schema_versions(applied_at DESC);
            SQL);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
        @unlink($this->schemaPath);
        unset($_ENV['DB_DSN'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
    }

    public function test_first_run_applies_schema_and_records_row(): void
    {
        $exit = $this->cmd->handle(['schema' => $this->schemaPath]);
        self::assertSame(0, $exit);

        $db = new Connection($_ENV['DB_DSN'], '', '');
        $rows = $db->fetchAll('SELECT * FROM schema_versions');
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', file_get_contents($this->schemaPath)), $rows[0]['schema_hash']);
        self::assertSame('manual', $rows[0]['applied_by']);
    }

    public function test_second_run_with_unchanged_schema_is_noop(): void
    {
        $this->cmd->handle(['schema' => $this->schemaPath]);
        $exit = $this->cmd->handle(['schema' => $this->schemaPath]);
        self::assertSame(0, $exit);

        $db = new Connection($_ENV['DB_DSN'], '', '');
        $rows = $db->fetchAll('SELECT * FROM schema_versions');
        self::assertCount(1, $rows, 'second run should be a no-op; only one audit row');
    }

    public function test_changed_schema_applies_and_records_new_row(): void
    {
        $this->cmd->handle(['schema' => $this->schemaPath]);

        // Modify schema (append a harmless extra index that's idempotent).
        $orig = file_get_contents($this->schemaPath);
        file_put_contents(
            $this->schemaPath,
            $orig . "\nCREATE INDEX IF NOT EXISTS idx_test_extra ON schema_versions(schema_hash);\n",
        );

        $exit = $this->cmd->handle(['schema' => $this->schemaPath]);
        self::assertSame(0, $exit);

        $db = new Connection($_ENV['DB_DSN'], '', '');
        $rows = $db->fetchAll('SELECT schema_hash FROM schema_versions ORDER BY id');
        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]['schema_hash'], $rows[1]['schema_hash']);
    }

    public function test_force_flag_applies_even_when_hash_matches(): void
    {
        $this->cmd->handle(['schema' => $this->schemaPath]);
        $exit = $this->cmd->handle(['schema' => $this->schemaPath, 'force' => true]);
        self::assertSame(0, $exit);

        $db = new Connection($_ENV['DB_DSN'], '', '');
        $rows = $db->fetchAll('SELECT * FROM schema_versions');
        self::assertCount(2, $rows, 'force should apply even on hash match');
    }

    public function test_applied_by_flag_is_recorded(): void
    {
        $exit = $this->cmd->handle([
            'schema' => $this->schemaPath,
            'applied-by' => 'ci-deploy',
        ]);
        self::assertSame(0, $exit);

        $db = new Connection($_ENV['DB_DSN'], '', '');
        $rows = $db->fetchAll('SELECT applied_by FROM schema_versions');
        self::assertSame('ci-deploy', $rows[0]['applied_by']);
    }

    public function test_empty_applied_by_falls_back_to_manual(): void
    {
        // Round-3 C4: --applied-by= parses to '' (empty string); guard MUST
        // fall back to 'manual' to prevent empty audit-row labels.
        $exit = $this->cmd->handle([
            'schema' => $this->schemaPath,
            'applied-by' => '',
        ]);
        self::assertSame(0, $exit);

        $db = new Connection($_ENV['DB_DSN'], '', '');
        $rows = $db->fetchAll('SELECT applied_by FROM schema_versions');
        self::assertSame('manual', $rows[0]['applied_by']);
    }
}
