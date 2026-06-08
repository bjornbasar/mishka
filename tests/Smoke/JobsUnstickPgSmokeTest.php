<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Commands\JobsUnstickCommand;
use Karhu\Db\Connection;
use Karhu\Queue\DatabaseQueue;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.9 — PG-only smoke for jobs:unstick. SQLite tests can't catch
 * TIMESTAMPTZ comparison drift across PG session TimeZone settings.
 *
 * Confirms that the gmdate('Y-m-d H:i:s\Z', ...) cutoff format compares
 * correctly against `jobs.updated_at TIMESTAMPTZ` regardless of the
 * connection's TimeZone — i.e. the trailing 'Z' is what PG needs to
 * treat the literal as unambiguous UTC.
 *
 * SKIPS unless DB_DSN points at pgsql://. Uses an explicit transaction +
 * rollBack() so smoke-test-created jobs rows never leak into shareddb
 * (otherwise mishka-worker would pick them up).
 */
final class JobsUnstickPgSmokeTest extends TestCase
{
    private Connection $db;

    private DatabaseQueue $queue;

    private JobsUnstickCommand $cmd;

    protected function setUp(): void
    {
        $dsn = getenv('DB_DSN') ?: ($_ENV['DB_DSN'] ?? '');
        if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) {
            self::markTestSkipped('PG smoke tests require DB_DSN=pgsql:...');
        }

        $this->db = new Connection(
            $dsn,
            (string) (getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? '')),
            (string) (getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '')),
        );

        // Explicit transaction so rollBack() in tearDown wipes everything
        // this test inserted — critical for jobs rows because the live
        // mishka-worker would pop them otherwise.
        $this->db->pdo()->beginTransaction();
        $this->queue = new DatabaseQueue($this->db);
        $this->cmd = new JobsUnstickCommand($this->queue, $this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_unstick_resets_stuck_row_on_pg(): void
    {
        // 10-min-old processing row. Default threshold (300s) must catch it.
        $ts = gmdate('Y-m-d H:i:s\Z', time() - 600);
        $id = (int) $this->db->fetchScalar(
            "INSERT INTO jobs (queue, job, data, status, updated_at)
             VALUES ('default', 'SmokeJob', '{}', 'processing', :ts) RETURNING id",
            ['ts' => $ts],
        );

        $code = $this->cmd->handle([]);

        self::assertSame(0, $code);
        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $id]);
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
    }

    public function test_unstick_leaves_fresh_row_alone_on_pg(): void
    {
        // 30s-old processing row — must NOT be reset at the 300s default.
        $ts = gmdate('Y-m-d H:i:s\Z', time() - 30);
        $id = (int) $this->db->fetchScalar(
            "INSERT INTO jobs (queue, job, data, status, updated_at)
             VALUES ('default', 'SmokeJob', '{}', 'processing', :ts) RETURNING id",
            ['ts' => $ts],
        );

        $code = $this->cmd->handle([]);

        self::assertSame(0, $code);
        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $id]);
        self::assertSame('processing', $row['status']);
    }

    public function test_unstick_holds_under_non_utc_session_timezone(): void
    {
        // Force the PG session into a non-UTC TimeZone — the cutoff
        // literal MUST still be interpreted as UTC because of the trailing 'Z'.
        // Without the 'Z', PG would treat the literal as session-local
        // and the comparison would drift by the session offset.
        $this->db->run("SET TIME ZONE 'Pacific/Auckland'");

        $ts = gmdate('Y-m-d H:i:s\Z', time() - 600);
        $id = (int) $this->db->fetchScalar(
            "INSERT INTO jobs (queue, job, data, status, updated_at)
             VALUES ('default', 'SmokeJob', '{}', 'processing', :ts) RETURNING id",
            ['ts' => $ts],
        );

        $code = $this->cmd->handle([]);

        self::assertSame(0, $code);
        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $id]);
        self::assertSame('pending', $row['status'], 'reset must work under non-UTC session timezone');
    }
}
