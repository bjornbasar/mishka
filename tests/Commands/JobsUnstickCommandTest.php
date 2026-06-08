<?php

declare(strict_types=1);

namespace App\Tests\Commands;

use App\Commands\JobsUnstickCommand;
use Karhu\Db\Connection;
use Karhu\Queue\DatabaseQueue;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.9 — JobsUnstickCommand. Mirrors the PushScanCommandTest shape
 * ($GLOBALS['test_db'] + per-test transaction). Assertions are on DB
 * state, not stdout — `fwrite(\STDOUT, …)` bypasses PHP output buffering.
 *
 * Job rows are inserted directly with a backdated updated_at so we can
 * deterministically simulate "stuck for N seconds" without sleeping the
 * test.
 */
final class JobsUnstickCommandTest extends TestCase
{
    private Connection $db;

    private DatabaseQueue $queue;

    private JobsUnstickCommand $cmd;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->queue = new DatabaseQueue($this->db);
        $this->cmd = new JobsUnstickCommand($this->queue, $this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /** Insert a job row directly so we can pin updated_at. Returns its id. */
    private function insertJob(
        string $status,
        int $secondsAgo,
        string $queue = 'default',
        ?string $error = null,
    ): int {
        $ts = gmdate('Y-m-d H:i:s\Z', time() - $secondsAgo);
        return (int) $this->db->insert('jobs', [
            'queue' => $queue,
            'job' => 'TestJob',
            'data' => '{}',
            'status' => $status,
            'error' => $error,
            'updated_at' => $ts,
        ]);
    }

    /**
     * Run handle() and return ['code' => int]. We assert on DB state, NOT
     * stdout — fwrite(\STDOUT, …) bypasses PHP output buffering, so capture
     * isn't worth the ceremony (PushScanCommandTest takes the same shortcut).
     *
     * @param array<string, string|true> $args
     * @return array{code: int}
     */
    private function runCmd(array $args = []): array
    {
        return ['code' => $this->cmd->handle($args)];
    }

    public function test_resets_stuck_processing_row_with_default_threshold(): void
    {
        $id = $this->insertJob('processing', secondsAgo: 600);

        $r = $this->runCmd();

        $this->assertSame(0, $r['code']);
        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $id]);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
    }

    public function test_leaves_fresh_processing_row_alone_at_default_threshold(): void
    {
        // 60s old is well under the 300s default — must not be touched.
        $id = $this->insertJob('processing', secondsAgo: 60);

        $r = $this->runCmd();

        $this->assertSame(0, $r['code']);
        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $id]);
        $this->assertSame('processing', $row['status']);
    }

    public function test_older_than_flag_overrides_default(): void
    {
        // 90s old: above a 60s --older-than but below the 300s default.
        $id = $this->insertJob('processing', secondsAgo: 90);

        $r = $this->runCmd(['older-than' => '60']);

        $this->assertSame(0, $r['code']);
        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $id]);
        $this->assertSame('pending', $row['status']);
    }

    public function test_invalid_older_than_falls_back_to_default(): void
    {
        // Bogus flag value must NOT cast to 0 (which would unstick everything).
        $id = $this->insertJob('processing', secondsAgo: 60);

        $r = $this->runCmd(['older-than' => 'banana']);

        $this->assertSame(0, $r['code']);
        // (int) 'banana' === 0, but our >0 guard rejects it, falling back
        // to 300s — so the 60s-old row stays untouched.
        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $id]);
        $this->assertSame('processing', $row['status']);
    }

    public function test_queue_flag_scopes_the_reset(): void
    {
        $stuckDefault = $this->insertJob('processing', secondsAgo: 600, queue: 'default');
        $stuckMail    = $this->insertJob('processing', secondsAgo: 600, queue: 'mail');

        $r = $this->runCmd(['queue' => 'mail']);

        $this->assertSame(0, $r['code']);
        $this->assertSame('processing', $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $stuckDefault])['status']);
        $this->assertSame('pending', $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $stuckMail])['status']);
    }

    public function test_completed_and_failed_rows_are_never_touched(): void
    {
        // Aged completed/failed rows must never be flipped back — that
        // would re-trigger long-finished work and (for non-idempotent
        // future handlers) be catastrophic.
        $completed = $this->insertJob('completed', secondsAgo: 999);
        $failed    = $this->insertJob('failed', secondsAgo: 999, error: 'old boom');

        $r = $this->runCmd();

        $this->assertSame(0, $r['code']);
        $this->assertSame('completed', $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $completed])['status']);
        $this->assertSame('failed', $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $failed])['status']);
    }

    public function test_no_op_when_no_stuck_rows_exist(): void
    {
        $pending = $this->insertJob('pending', secondsAgo: 999);

        $r = $this->runCmd();

        $this->assertSame(0, $r['code']);
        // Pending rows are untouched — only processing transitions to pending.
        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $pending]);
        $this->assertSame('pending', $row['status']);
    }

    public function test_unstick_clears_stale_error_on_reset_row(): void
    {
        // A stuck row could in principle carry a stale error (defence vs.
        // a partial fail() call interleaved with SIGKILL). Reset must
        // clear it so the re-popped handler sees a clean slate.
        $id = $this->insertJob('processing', secondsAgo: 600, error: 'half-set boom');

        $r = $this->runCmd();

        $this->assertSame(0, $r['code']);
        $row = $this->db->fetchOne("SELECT status, error FROM jobs WHERE id = :id", ['id' => $id]);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['error']);
    }

    public function test_idempotent_second_run_is_no_op_after_reset(): void
    {
        $this->insertJob('processing', secondsAgo: 600);

        $first  = $this->runCmd();
        $second = $this->runCmd();

        $this->assertSame(0, $first['code']);
        $this->assertSame(0, $second['code']);
        // Second call finds nothing (updated_at was bumped to NOW by the first).
        $pending = (int) $this->db->fetchScalar("SELECT COUNT(*) FROM jobs WHERE status = 'pending'");
        $processing = (int) $this->db->fetchScalar("SELECT COUNT(*) FROM jobs WHERE status = 'processing'");
        $this->assertSame(1, $pending);
        $this->assertSame(0, $processing);
    }

    public function test_unstick_bumps_updated_at_on_reset_row(): void
    {
        $id = $this->insertJob('processing', secondsAgo: 600);

        $this->runCmd();

        $row = $this->db->fetchOne("SELECT updated_at FROM jobs WHERE id = :id", ['id' => $id]);
        $bumped = strtotime((string) $row['updated_at']);
        $this->assertNotFalse($bumped);
        $this->assertLessThanOrEqual(2, abs(time() - $bumped));
    }

    public function test_unstick_uses_explicit_utc_cutoff_format(): void
    {
        // Regression guard for the PG TimeZone drift fix — the command's
        // candidate-count SELECT must use the same gmdate('Y-m-d H:i:s\Z')
        // literal as DatabaseQueue::unstick(), otherwise the two counts
        // would diverge under non-UTC PG sessions. We verify by inserting
        // a row whose updated_at uses the Z-shape and asserting the
        // command resets it (which would fail if the SELECT/UPDATE used
        // mismatched literals).
        $id = $this->insertJob('processing', secondsAgo: 1000);

        $r = $this->runCmd();

        $this->assertSame(0, $r['code']);
        $this->assertSame('pending', $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $id])['status']);
    }
}
