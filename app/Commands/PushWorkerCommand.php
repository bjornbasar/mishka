<?php

declare(strict_types=1);

namespace App\Commands;

use App\Jobs\SendPushNotificationJob;
use App\Push\PushSender;
use App\Push\PushSubscriptionRepository;
use Karhu\Attributes\Command;
use Karhu\Queue\QueueInterface;
use Karhu\Queue\Worker;

/**
 * v0.6.0 — push:worker
 *
 * Long-lived consumer of the karhu-queue `jobs` table. Runs as a separate
 * container (`mishka-worker` in docker-compose, profile `mishka`, restart:
 * unless-stopped). One job kind handled: `SendPushNotification`.
 *
 * Per-job flow:
 *   1. Pull subscription rows for the user (filtered to active).
 *   2. For each, call PushSender::sendTo.
 *   3. dead=true  → markRevoked the subscription (HTTP 410 from push svc).
 *      success=true → touch (last_used_at).
 *      success=false, dead=false → log to STDERR + leave alone (H1).
 *
 * Stuck-job semantics: a SIGKILL'd worker leaves the row in `processing`
 * forever. Documented as at-most-once-by-design (B9 — v0.6.1 candidate to
 * add `karhu jobs:unstick`).
 *
 * Public-facing test entry: `processNextJob()` runs ONE pop+handle then
 * returns, used by tests (the real worker calls `run()` which loops).
 */
final class PushWorkerCommand
{
    private Worker $worker;

    public function __construct(
        private readonly QueueInterface $queue,
        private readonly PushSubscriptionRepository $subs,
        private readonly PushSender $sender,
    ) {
        $this->worker = new Worker($this->queue);
        $this->worker->register(SendPushNotificationJob::NAME, function (array $data): void {
            $this->handlePush($data);
        });
    }

    /**
     * Run the worker forever. CLI entry — `push:worker` blocks the calling
     * process. The mishka-worker container exits → restart: unless-stopped
     * cycles it.
     *
     * @param array<string, string|true> $args
     */
    #[Command('push:worker', 'Run the push notification worker (long-lived)')]
    public function handle(array $args): int
    {
        fwrite(\STDOUT, "push:worker starting (jobs queue: default)\n");
        $this->worker->run();
        return 0;
    }

    /**
     * Test-only entry: process exactly one job, return true if a job was
     * processed (false if the queue was empty). Mirrors Worker::processNext.
     */
    public function processNextJob(): bool
    {
        return $this->worker->processNext();
    }

    /**
     * Fan-out the job to every active subscription for the user. Each send
     * is independent; one device's 410 doesn't stop others from getting
     * the push.
     *
     * @param array<string, mixed> $data payload from SendPushNotificationJob::payload()
     */
    private function handlePush(array $data): void
    {
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            error_log('mishka push:worker: malformed job — missing user_id');
            return;
        }

        $title = (string) ($data['title'] ?? 'Mishka');
        $body = (string) ($data['body'] ?? '');
        $url = (string) ($data['url'] ?? '/');

        foreach ($this->subs->getForSend($userId) as $sub) {
            $result = $this->sender->sendTo($sub, [
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ]);

            if ($result['success']) {
                $this->subs->touch($sub['id']);
                continue;
            }

            if ($result['dead']) {
                // HTTP 410 — subscription is permanently gone. Soft-delete
                // so the next push:scan skips it.
                $this->subs->markRevoked($sub['id']);
                error_log("mishka push:worker: subscription {$sub['id']} dead — revoking");
                continue;
            }

            // Transient failure (5xx / network / VAPID hiccup). Log it; the
            // next push for this user gets a clean retry.
            error_log("mishka push:worker: transient send failure for sub {$sub['id']}: {$result['reason']}");
        }
    }
}
