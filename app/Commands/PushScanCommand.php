<?php

declare(strict_types=1);

namespace App\Commands;

use App\Calendar\RangeExpander;
use App\Chores\ChoreRepository;
use App\Clock\ClockInterface;
use App\Household\HouseholdRepository;
use App\Jobs\SendPushNotificationJob;
use App\Push\NotificationDispatchRepository;
use App\Push\PushSubscriptionRepository;
use App\Push\UserNotificationPrefsRepository;
use Karhu\Attributes\Command;
use Karhu\Db\Connection;
use Karhu\Queue\QueueInterface;

/**
 * v0.6.0 — push:scan
 *
 * Fires every 5 min via cron. Three passes:
 *   1. Prune notification_dispatches older than 90 days (B4 — keeps the
 *      dedup ledger bounded).
 *   2. Event reminders — for each user with active subscriptions, for each
 *      of their households, scan upcoming event occurrences and enqueue a
 *      reminder push if the event starts within the user's chosen window.
 *   3. Overdue-chore digest — for each user with active subscriptions, in
 *      the 07:30–08:30 household-tz window, enqueue ONE digest push
 *      summarising overdue chores assigned to them.
 *
 * Dedup: claim(user_id, kind, ref_id) before enqueue. The unique constraint
 * makes this race-safe across concurrent cron ticks.
 *
 * Time-loop quirks documented in the v0.6 plan:
 *   - 5-min cron + per-user window: events get a 5-min lookahead buffer so
 *     a 15-min reminder doesn't drift to T-9:59 by the next tick.
 *   - per-user `event_reminder_minutes` is enforced as a hard threshold —
 *     the SELECT pulls events out to `max(prefs)`+5min, then each user
 *     gets their own filter.
 *   - membership is checked at scan time, never cached across ticks (B10).
 *   - clock injection means tests can pin time deterministically (B11).
 */
final class PushScanCommand
{
    /** Cron jitter buffer added to the event-reminder lookahead (5 min). */
    private const CRON_JITTER_BUFFER_MINUTES = 5;

    /** Digest fires when household-tz `HHmm` is in [DIGEST_START, DIGEST_END]. */
    private const DIGEST_WINDOW_START = 730;
    private const DIGEST_WINDOW_END = 830;

    /** Dispatch-ledger pruning horizon (90 days). */
    private const DISPATCH_RETENTION_DAYS = 90;

    public function __construct(
        private readonly Connection $db,
        private readonly PushSubscriptionRepository $subs,
        private readonly UserNotificationPrefsRepository $prefs,
        private readonly NotificationDispatchRepository $dispatches,
        private readonly HouseholdRepository $households,
        private readonly RangeExpander $events,
        private readonly ChoreRepository $chores,
        private readonly QueueInterface $queue,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param array<string, string|true> $args
     */
    #[Command('push:scan', 'Scan for upcoming events and overdue chores; enqueue push notifications')]
    public function handle(array $args): int
    {
        // 1. Prune the dispatch ledger (B4).
        $this->dispatches->prune(self::DISPATCH_RETENTION_DAYS);

        $now = $this->clock->now();
        $userIds = $this->subs->listUserIdsWithActiveSubscriptions();

        $enqueued = 0;
        foreach ($userIds as $uid) {
            $prefs = $this->prefs->getFor($uid);
            $households = $this->households->listForUser($uid);

            foreach ($households as $hhMembership) {
                $hh = $this->households->findById($hhMembership['id']);
                if ($hh === null) {
                    continue;
                }
                $tz = new \DateTimeZone($hh['timezone']);

                // 2. Event reminders (only if the user has opted in).
                if ($prefs['event_reminder_minutes'] > 0) {
                    $enqueued += $this->scanEventReminders(
                        $uid, $hh['id'], $prefs['event_reminder_minutes'], $now, $tz,
                    );
                }

                // 3. Overdue-chore digest (only in the 07:30–08:30 hh-tz window).
                if ($prefs['overdue_chore_digest'] && $this->isInDigestWindow($now, $tz)) {
                    $enqueued += $this->scanOverdueDigest($uid, $hh['id'], $now, $tz);
                }
            }
        }

        fwrite(\STDOUT, "push:scan enqueued {$enqueued} notification(s)\n");
        return 0;
    }

    /**
     * Look for events in [now, now + minutes + jitter] for this household;
     * for each, claim(uid, 'event_reminder', event.id) and enqueue.
     *
     * RangeExpander returns occurrences (one-off + recurring expanded), each
     * with `occurrence` (DateTimeImmutable in household-tz).
     */
    private function scanEventReminders(
        int $uid,
        int $hid,
        int $reminderMinutes,
        \DateTimeImmutable $nowUtc,
        \DateTimeZone $tz,
    ): int {
        // The SELECT window includes the cron jitter buffer so an event
        // exactly N min away isn't dropped to T-9:59 by the next 5-min tick.
        $lookaheadMinutes = $reminderMinutes + self::CRON_JITTER_BUFFER_MINUTES;
        $rangeStart = $nowUtc->setTimezone($tz);
        $rangeEnd = $rangeStart->modify("+{$lookaheadMinutes} minutes");

        $occurrences = $this->events->expandForHousehold($hid, $rangeStart, $rangeEnd);

        $enqueued = 0;
        foreach ($occurrences as $occ) {
            $eventId = (int) ($occ['event']['id'] ?? 0);
            if ($eventId <= 0) {
                continue;
            }
            // Per-user threshold: the SELECT used max-of-everyone's-prefs
            // (plus jitter) but each user only wants their N-min window.
            $secondsAway = $occ['occurrence']->getTimestamp() - $nowUtc->getTimestamp();
            if ($secondsAway < 0 || $secondsAway > ($reminderMinutes * 60)) {
                continue;
            }

            // Claim + enqueue in one transaction (B3 — a crash between the
            // claim and the queue push would lose the push permanently).
            $pdo = $this->db->pdo();
            $started = false;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            try {
                if ($this->dispatches->claim($uid, 'event_reminder', $eventId)) {
                    $this->queue->push(SendPushNotificationJob::NAME, SendPushNotificationJob::payload(
                        userId: $uid,
                        title: 'Coming up: ' . (string) ($occ['event']['title'] ?? 'Mishka event'),
                        body: 'Starts at ' . $occ['occurrence']->format('H:i') . '.',
                        url: '/calendar',
                    ));
                    $enqueued++;
                }
                if ($started) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($started) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return $enqueued;
    }

    /**
     * One digest push per (user, household, date) if they have any overdue
     * chores assigned to them. ref_id is YYYYMMDD-in-hh-tz so different days
     * don't collide.
     */
    private function scanOverdueDigest(
        int $uid,
        int $hid,
        \DateTimeImmutable $nowUtc,
        \DateTimeZone $tz,
    ): int {
        $nowLocal = $nowUtc->setTimezone($tz)->format('Y-m-d H:i:s');
        $missed = $this->chores->missedCountsForHousehold($hid, $nowLocal);
        $count = $missed[$uid] ?? 0;
        if ($count <= 0) {
            return 0;
        }

        $todayYmd = (int) $nowUtc->setTimezone($tz)->format('Ymd');

        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            if (!$this->dispatches->claim($uid, 'overdue_digest', $todayYmd)) {
                if ($started) {
                    $pdo->commit();
                }
                return 0;
            }
            $body = $count === 1
                ? 'You have 1 overdue chore — open Mishka to catch up.'
                : "You have {$count} overdue chores — open Mishka to catch up.";
            $this->queue->push(SendPushNotificationJob::NAME, SendPushNotificationJob::payload(
                userId: $uid,
                title: '🐻 Daily chore catch-up',
                body: $body,
                url: '/chores',
            ));
            if ($started) {
                $pdo->commit();
            }
            return 1;
        } catch (\Throwable $e) {
            if ($started) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function isInDigestWindow(\DateTimeImmutable $nowUtc, \DateTimeZone $tz): bool
    {
        $hhmm = (int) $nowUtc->setTimezone($tz)->format('Hi');
        return $hhmm >= self::DIGEST_WINDOW_START && $hhmm <= self::DIGEST_WINDOW_END;
    }
}
