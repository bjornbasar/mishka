<?php

declare(strict_types=1);

namespace App\Tests\Commands;

use App\Commands\PushWorkerCommand;
use App\Jobs\SendPushNotificationJob;
use App\Push\PushSubscriptionRepository;
use App\Tests\Fixtures\RecordingPushSender;
use Karhu\Db\Connection;
use Karhu\Queue\DatabaseQueue;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.0 — PushWorkerCommand consumes jobs + fan-out to each active
 * subscription. Recording sender captures calls; cleanup branches on
 * `dead` flag.
 */
final class PushWorkerCommandTest extends TestCase
{
    private Connection $db;
    private DatabaseQueue $queue;
    private PushSubscriptionRepository $subs;
    private RecordingPushSender $sender;
    private PushWorkerCommand $worker;
    private int $uid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();

        $this->queue = new DatabaseQueue($this->db);
        $this->subs = new PushSubscriptionRepository($this->db);
        $this->sender = new RecordingPushSender();
        $this->worker = new PushWorkerCommand($this->queue, $this->subs, $this->sender);

        $this->uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'T') RETURNING id",
            ['e' => 'u-' . bin2hex(random_bytes(3)) . '@example.com'],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_process_next_job_fans_out_to_all_active_subscriptions(): void
    {
        $this->subs->register($this->uid, 'https://fcm.example/a', 'pk-a', 'auth-a', null);
        $this->subs->register($this->uid, 'https://fcm.example/b', 'pk-b', 'auth-b', null);

        $this->queue->push(SendPushNotificationJob::NAME, SendPushNotificationJob::payload(
            userId: $this->uid,
            title: 'Test',
            body: 'Hello',
            url: '/calendar',
        ));

        $processed = $this->worker->processNextJob();

        self::assertTrue($processed);
        self::assertCount(2, $this->sender->sent);
        $endpoints = array_column($this->sender->sent, 'endpoint');
        self::assertContains('https://fcm.example/a', $endpoints);
        self::assertContains('https://fcm.example/b', $endpoints);
    }

    public function test_dead_subscription_is_marked_revoked(): void
    {
        $subId = $this->subs->register($this->uid, 'https://fcm.example/a', 'pk', 'auth', null);
        // Tell the sender the next send will report dead.
        $this->sender->nextResult = ['success' => false, 'dead' => true, 'reason' => 'Gone (410)'];

        $this->queue->push(SendPushNotificationJob::NAME, SendPushNotificationJob::payload(
            userId: $this->uid,
            title: 'Test',
            body: 'Hello',
        ));
        $this->worker->processNextJob();

        // Subscription should now be revoked (not in active list).
        $active = $this->subs->listActiveForUser($this->uid);
        self::assertCount(0, $active);
        // And the revoked_at column is set.
        $revoked = $this->db->fetchScalar(
            'SELECT revoked_at FROM push_subscriptions WHERE id = :id',
            ['id' => $subId],
        );
        self::assertNotNull($revoked);
    }

    public function test_successful_send_touches_last_used_at(): void
    {
        $subId = $this->subs->register($this->uid, 'https://fcm.example/a', 'pk', 'auth', null);

        $this->queue->push(SendPushNotificationJob::NAME, SendPushNotificationJob::payload(
            userId: $this->uid,
            title: 'Test',
            body: 'Hello',
        ));
        $this->worker->processNextJob();

        $sub = $this->subs->listActiveForUser($this->uid)[0];
        self::assertNotNull($sub['last_used_at']);
    }

    public function test_transient_failure_leaves_subscription_active(): void
    {
        $subId = $this->subs->register($this->uid, 'https://fcm.example/a', 'pk', 'auth', null);
        $this->sender->nextResult = ['success' => false, 'dead' => false, 'reason' => '500 Server Error'];

        $this->queue->push(SendPushNotificationJob::NAME, SendPushNotificationJob::payload(
            userId: $this->uid,
            title: 'Test',
            body: 'Hello',
        ));
        $this->worker->processNextJob();

        // Subscription stays active — the worker logs but doesn't punish
        // transient failures.
        self::assertCount(1, $this->subs->listActiveForUser($this->uid));
    }

    public function test_process_next_returns_false_when_queue_empty(): void
    {
        self::assertFalse($this->worker->processNextJob());
    }

    public function test_malformed_job_data_does_not_crash_the_worker(): void
    {
        // Push a job with no user_id — the handler logs + skips, no throw.
        $this->queue->push(SendPushNotificationJob::NAME, []);

        $processed = $this->worker->processNextJob();
        self::assertTrue($processed);
        self::assertSame([], $this->sender->sent);
    }
}
