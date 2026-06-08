<?php

declare(strict_types=1);

namespace App\Commands;

use Karhu\Attributes\Command;
use Karhu\Db\Connection;
use Karhu\Queue\QueueInterface;

/**
 * v0.6.9 — jobs:unstick
 *
 * Cron-driven recovery for jobs stuck in 'processing' status. The
 * Karhu\Queue\Worker try/catch only catches handler exceptions; SIGKILL
 * (OOM, host reboot, manual docker kill) leaves the row stuck forever.
 * This command reclaims those rows so the worker picks them up again.
 *
 * Wired to a 10-minute cron on Nalle via ansible/host_vars/nalle.yml.
 * Offsets the existing push:scan 5-minute cron to minimise load collisions.
 *
 * Output is a single line:
 *   jobs:unstick: scanned N candidate(s), reset M job(s)
 * When N != M, a live worker completed a stuck-looking row between the
 * SELECT and the UPDATE — a useful "caught a slow-but-alive worker once"
 * signal. Equal counts (the common case) read cleanly.
 *
 * Threshold defaults to 300s (5 min); the only handler today
 * (SendPushNotificationJob) is <60s wall time so 5× safety holds. If a
 * future handler approaches 5 min, bump --older-than or use a separate
 * queue. Handler idempotency is the load-bearing contract — see
 * DOCS.md #50 + QueueInterface::unstick() docblock.
 */
final class JobsUnstickCommand
{
    /** Default threshold: rows older than this in 'processing' are considered stuck. */
    private const DEFAULT_THRESHOLD_SECONDS = 300;

    public function __construct(
        private readonly QueueInterface $queue,
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string, string|true> $args
     */
    #[Command('jobs:unstick', 'Reset jobs stuck in processing back to pending (SIGKILL recovery)')]
    public function handle(array $args): int
    {
        $threshold = self::DEFAULT_THRESHOLD_SECONDS;
        if (isset($args['older-than']) && is_string($args['older-than'])) {
            $parsed = (int) $args['older-than'];
            if ($parsed > 0) {
                $threshold = $parsed;
            }
        }
        $queueName = null;
        if (isset($args['queue']) && is_string($args['queue']) && $args['queue'] !== '') {
            $queueName = $args['queue'];
        }

        // Count candidates BEFORE the unstick so a live worker that completes
        // a stuck-looking row mid-cron shows up as `scanned 1, reset 0`.
        // Cutoff matches DatabaseQueue::unstick exactly so the counts are comparable.
        $cutoff = gmdate('Y-m-d H:i:s\Z', time() - $threshold);
        $sql = "SELECT COUNT(*) FROM jobs WHERE status = 'processing' AND updated_at < :cutoff";
        $params = ['cutoff' => $cutoff];
        if ($queueName !== null) {
            $sql .= ' AND queue = :queue';
            $params['queue'] = $queueName;
        }
        $scanned = (int) $this->db->fetchScalar($sql, $params);

        $reset = $this->queue->unstick($threshold, $queueName);

        $scannedLabel = $scanned === 1 ? 'candidate' : 'candidates';
        $resetLabel = $reset === 1 ? 'job' : 'jobs';
        fwrite(\STDOUT, "jobs:unstick: scanned {$scanned} {$scannedLabel}, reset {$reset} {$resetLabel}\n");

        return 0;
    }
}
