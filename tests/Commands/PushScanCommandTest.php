<?php

declare(strict_types=1);

namespace App\Tests\Commands;

use App\Calendar\EventExceptionRepository;
use App\Calendar\EventRepository;
use App\Calendar\RangeExpander;
use App\Chores\ChoreRepository;
use App\Commands\PushScanCommand;
use App\Household\HouseholdRepository;
use App\Jobs\SendPushNotificationJob;
use App\Push\NotificationDispatchRepository;
use App\Push\PushSubscriptionRepository;
use App\Push\UserNotificationPrefsRepository;
use App\Tests\Fixtures\FixedClock;
use Karhu\Db\Connection;
use Karhu\Queue\DatabaseQueue;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.0 — PushScanCommand. FixedClock drives time deterministically (B11).
 * Verifies at-most-once dedup (B3 + claim() race-safety) and the per-user
 * `event_reminder_minutes` threshold + the 07:30–08:30 hh-tz digest window.
 */
final class PushScanCommandTest extends TestCase
{
    private Connection $db;
    private FixedClock $clock;
    private DatabaseQueue $queue;
    private HouseholdRepository $households;
    private UserNotificationPrefsRepository $prefsRepo;
    private PushSubscriptionRepository $subsRepo;
    private NotificationDispatchRepository $dispatchRepo;
    private EventRepository $events;
    private ChoreRepository $chores;
    private RangeExpander $expander;
    private PushScanCommand $cmd;

    private int $owner;
    private int $member;
    private int $hid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();

        $this->households = new HouseholdRepository($this->db);
        $this->prefsRepo = new UserNotificationPrefsRepository($this->db);
        $this->subsRepo = new PushSubscriptionRepository($this->db);
        $this->dispatchRepo = new NotificationDispatchRepository($this->db);
        $this->events = new EventRepository($this->db);
        $this->chores = new ChoreRepository($this->db);
        $exceptions = new EventExceptionRepository($this->db);
        $this->expander = new RangeExpander($this->events, $exceptions);
        $this->queue = new DatabaseQueue($this->db);
        // Default clock — overridden per test.
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-06-15 10:00:00', new \DateTimeZone('UTC')));

        $this->cmd = new PushScanCommand(
            $this->db, $this->subsRepo, $this->prefsRepo, $this->dispatchRepo,
            $this->households, $this->expander, $this->chores, $this->queue, $this->clock,
        );

        // Fixture household with two members, both subscribed.
        $this->owner = $this->insertUser('owner@example.com');
        $this->member = $this->insertUser('member@example.com');
        $this->hid = $this->households->createForOwner('Den', $this->owner);
        $this->households->addMember($this->hid, $this->member);
        $this->subsRepo->register($this->owner, 'https://fcm.example/o', 'pk', 'auth', null);
        $this->subsRepo->register($this->member, 'https://fcm.example/m', 'pk', 'auth', null);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_event_reminder_fires_once_across_multiple_cron_runs(): void
    {
        // Owner pref: 15 min. Event 10 min from now → in window.
        $this->prefsRepo->setFor($this->owner, ['event_reminder_minutes' => 15, 'overdue_chore_digest' => false]);
        $this->prefsRepo->setFor($this->member, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => false]);

        // 'now' = 2026-06-15 10:00 UTC = 22:00 NZST. The event is at 22:10 NZST
        // (= 10:10 UTC) — within the 15-min window.
        $startsLocal = '2026-06-15 22:10:00';
        $this->insertEvent($startsLocal);

        $this->assertEnqueuedCount(0);
        $this->cmd->handle([]);
        $this->assertEnqueuedCount(1);

        // Re-run the scan — same event, dedup should hold.
        $this->cmd->handle([]);
        $this->assertEnqueuedCount(1);
    }

    public function test_event_reminder_respects_per_user_threshold(): void
    {
        // Owner: 60-min window. Member: 5-min window.
        // Event in 20 min → owner gets it, member doesn't.
        $this->prefsRepo->setFor($this->owner, ['event_reminder_minutes' => 60, 'overdue_chore_digest' => false]);
        $this->prefsRepo->setFor($this->member, ['event_reminder_minutes' => 5, 'overdue_chore_digest' => false]);

        $startsLocal = '2026-06-15 22:20:00';  // 20 min from 22:00 NZST
        $this->insertEvent($startsLocal);

        $this->cmd->handle([]);

        $jobs = $this->popAll();
        self::assertCount(1, $jobs);
        self::assertSame($this->owner, $jobs[0]['data']['user_id']);
    }

    public function test_event_reminder_skipped_when_minutes_is_zero(): void
    {
        $this->prefsRepo->setFor($this->owner, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => false]);
        $this->prefsRepo->setFor($this->member, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => false]);

        $this->insertEvent('2026-06-15 22:10:00');
        $this->cmd->handle([]);

        $this->assertEnqueuedCount(0);
    }

    public function test_digest_skipped_outside_window(): void
    {
        // 'now' is 10:00 UTC = 22:00 NZST — way outside the 07:30–08:30 window.
        $this->prefsRepo->setFor($this->owner, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => true]);
        $this->insertOverdueChoreFor($this->owner);

        $this->cmd->handle([]);

        $this->assertEnqueuedCount(0);
    }

    public function test_digest_fires_once_inside_window(): void
    {
        // 19:30 UTC = 07:30 NZST (in NZ winter, June). Inside the window.
        $this->clock->set(new \DateTimeImmutable('2026-06-15 19:30:00', new \DateTimeZone('UTC')));
        $this->prefsRepo->setFor($this->owner, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => true]);
        $this->insertOverdueChoreFor($this->owner);

        $this->cmd->handle([]);
        $this->assertEnqueuedCount(1);

        // Second run same day — dedup holds.
        $this->cmd->handle([]);
        $this->assertEnqueuedCount(1);
    }

    public function test_digest_skipped_when_user_disabled(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-06-15 19:30:00', new \DateTimeZone('UTC')));
        $this->prefsRepo->setFor($this->owner, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => false]);
        $this->insertOverdueChoreFor($this->owner);

        $this->cmd->handle([]);
        $this->assertEnqueuedCount(0);
    }

    public function test_digest_skipped_when_no_overdue_chores(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-06-15 19:30:00', new \DateTimeZone('UTC')));
        $this->prefsRepo->setFor($this->owner, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => true]);
        // No chore inserted.

        $this->cmd->handle([]);
        $this->assertEnqueuedCount(0);
    }

    public function test_prune_drops_dispatch_rows_older_than_90_days(): void
    {
        $this->prefsRepo->setFor($this->owner, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => false]);
        $this->prefsRepo->setFor($this->member, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => false]);

        // Insert a dispatch row 100 days ago.
        $this->dispatchRepo->claim($this->owner, 'event_reminder', 999);
        $past = gmdate('Y-m-d H:i:s', time() - 100 * 86400);
        $this->db->run(
            'UPDATE notification_dispatches SET dispatched_at = :p WHERE ref_id = 999',
            ['p' => $past],
        );

        $this->cmd->handle([]);

        $remaining = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM notification_dispatches WHERE ref_id = 999'
        );
        self::assertSame(0, $remaining);
    }

    public function test_dispatch_table_dedup_after_prune_releases_old_event_id(): void
    {
        // The schema doesn't FK ref_id to events. If a dispatch row from
        // 100 days ago is pruned and a NEW event happens to get the same id
        // (impossible on PG SERIAL but conceptually safe), re-claiming the
        // ref_id is OK because the prune removed the conflicting row.
        $this->dispatchRepo->claim($this->owner, 'event_reminder', 42);
        $past = gmdate('Y-m-d H:i:s', time() - 100 * 86400);
        $this->db->run(
            'UPDATE notification_dispatches SET dispatched_at = :p WHERE ref_id = 42',
            ['p' => $past],
        );
        $this->prefsRepo->setFor($this->owner, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => false]);
        $this->prefsRepo->setFor($this->member, ['event_reminder_minutes' => 0, 'overdue_chore_digest' => false]);

        $this->cmd->handle([]);

        // After prune, claim() for the same ref_id should succeed again.
        self::assertTrue($this->dispatchRepo->claim($this->owner, 'event_reminder', 42));
    }

    // ----- helpers -----

    private function insertUser(string $email): int
    {
        return (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'T') RETURNING id",
            ['e' => 'u-' . bin2hex(random_bytes(3)) . '-' . $email],
        );
    }

    private function insertEvent(string $startsAtLocal): int
    {
        return $this->events->create([
            'household_id' => $this->hid,
            'created_by' => $this->owner,
            'title' => 'Sample event',
            'description' => '',
            'location' => '',
            'starts_at_local' => $startsAtLocal,
            'ends_at_local' => (new \DateTimeImmutable($startsAtLocal))->modify('+1 hour')->format('Y-m-d H:i:s'),
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);
    }

    private function insertOverdueChoreFor(int $uid): void
    {
        $this->chores->create([
            'household_id' => $this->hid,
            'created_by' => $this->owner,
            'title' => 'Overdue chore',
            'description' => '',
            'points' => 5,
            'due_at_local' => '2026-01-01 09:00:00',
            'assigned_to' => $uid,
            'timezone' => 'Pacific/Auckland',
        ]);
    }

    /** @return list<array{job: string, data: array<string, mixed>}> */
    private function popAll(): array
    {
        $out = [];
        while (($j = $this->queue->pop()) !== null) {
            $out[] = [
                'job' => $j['job'],
                'data' => $j['data'],
            ];
        }
        return $out;
    }

    private function assertEnqueuedCount(int $expected): void
    {
        $count = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM jobs WHERE job = :j AND status = 'pending'",
            ['j' => SendPushNotificationJob::NAME],
        );
        self::assertSame($expected, $count);
    }
}
