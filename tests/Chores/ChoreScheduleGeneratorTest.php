<?php

declare(strict_types=1);

namespace App\Tests\Chores;

use App\Chores\ChoreRepository;
use App\Chores\ChoreScheduleGenerator;
use App\Chores\ChoreScheduleRepository;
use App\Household\HouseholdRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The heart of v0.4.1. Verifies the clamped rolling-horizon generation (B1),
 * the pure-function rotation cursor (B2), and idempotency (B3).
 *
 * A fixed "now" is injected into every call so the horizon math is deterministic
 * and the tests don't drift with wall-clock time.
 */
final class ChoreScheduleGeneratorTest extends TestCase
{
    private Connection $db;
    private ChoreScheduleRepository $schedules;
    private ChoreRepository $chores;
    private HouseholdRepository $households;
    private ChoreScheduleGenerator $gen;
    private string $tz = 'Pacific/Auckland';

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->schedules = new ChoreScheduleRepository($this->db);
        $this->chores = new ChoreRepository($this->db);
        $this->households = new HouseholdRepository($this->db);
        $this->gen = new ChoreScheduleGenerator($this->schedules, $this->chores, $this->households);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_daily_schedule_generates_only_within_lookahead(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->households->createForOwner('Den', $uid, $this->tz);
        $now = new \DateTimeImmutable('2026-06-10 08:00:00', new \DateTimeZone($this->tz));
        // Anchor today; daily. Horizon = now+14d, backfill 14d → window ~[now-14, now+14].
        $sid = $this->makeSchedule($hid, $uid, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-10 09:00:00']);

        $created = $this->gen->generateForSchedule($this->schedules->findById($sid), $now);

        // genFrom = max(anchor 06-10 09:00, now-14d) = 06-10 09:00; genTo = now+14d = 06-24 08:00.
        // Occurrences strictly after genFrom up to <= genTo: 06-11 09:00 .. 06-23 09:00 = 13.
        // (06-24 09:00 exceeds genTo 08:00, so it's excluded.)
        self::assertSame(13, $created);
        self::assertSame(13, $this->countGenerated($sid));
    }

    public function test_far_past_anchor_backfills_at_most_backfill_window_not_whole_history(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->households->createForOwner('Den', $uid, $this->tz);
        $now = new \DateTimeImmutable('2026-06-10 08:00:00', new \DateTimeZone($this->tz));
        // Daily anchored TWO YEARS ago. Naive expansion would be ~700 rows; must clamp.
        $sid = $this->makeSchedule($hid, $uid, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2024-06-10 09:00:00']);

        $created = $this->gen->generateForSchedule($this->schedules->findById($sid), $now);

        // Window clamps to [now-14d, now+14d] = 28-day span → ≤ 28 rows, never 700.
        self::assertLessThanOrEqual(28, $created);
        self::assertGreaterThan(0, $created);
    }

    public function test_generation_is_idempotent(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->households->createForOwner('Den', $uid, $this->tz);
        $now = new \DateTimeImmutable('2026-06-10 08:00:00', new \DateTimeZone($this->tz));
        $sid = $this->makeSchedule($hid, $uid, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-10 09:00:00']);

        $first = $this->gen->generateForSchedule($this->schedules->findById($sid), $now);
        // Second run at the SAME now must create nothing (watermark + UNIQUE).
        $second = $this->gen->generateForSchedule($this->schedules->findById($sid), $now);

        self::assertGreaterThan(0, $first);
        self::assertSame(0, $second);
        self::assertSame($first, $this->countGenerated($sid));
    }

    public function test_rotation_cycles_members_in_join_order(): void
    {
        $a = $this->insertUser('a@example.com', 'A');
        $b = $this->insertUser('b@example.com', 'B');
        $c = $this->insertUser('c@example.com', 'C');
        $hid = $this->households->createForOwner('Den', $a, $this->tz);
        $this->households->addMember($hid, $b);
        $this->households->addMember($hid, $c);
        $now = new \DateTimeImmutable('2026-06-10 08:00:00', new \DateTimeZone($this->tz));
        // Daily, anchored 3 days ago so exactly 3 occurrences fall in (genFrom, genTo]? Use a tight horizon by anchoring today and reading the first 3.
        $sid = $this->makeSchedule($hid, $a, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-10 09:00:00']);

        $this->gen->generateForSchedule($this->schedules->findById($sid), $now);

        $assignees = $this->generatedAssigneesInOrder($sid);
        // Oldest-first rotation across [A,B,C] join order → A,B,C,A,B,C,...
        self::assertSame([$a, $b, $c, $a], array_slice($assignees, 0, 4));
        // Cursor lands on the last assignee.
        self::assertSame(end($assignees), $this->schedules->findById($sid)['last_assigned_user_id']);
    }

    public function test_rotation_continues_across_separate_runs_without_skipping(): void
    {
        $a = $this->insertUser('a@example.com', 'A');
        $b = $this->insertUser('b@example.com', 'B');
        $hid = $this->households->createForOwner('Den', $a, $this->tz);
        $this->households->addMember($hid, $b);
        $tz = new \DateTimeZone($this->tz);
        $sid = $this->makeSchedule($hid, $a, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-10 09:00:00']);

        // Two real horizons: run 1 at now1, run 2 three days later. The cursor +
        // watermark must make the second batch continue the rotation seamlessly.
        $now1 = new \DateTimeImmutable('2026-06-11 08:00:00', $tz);
        $now2 = $now1->modify('+3 days');
        $this->gen->generateForSchedule($this->schedules->findById($sid), $now1);
        $firstBatch = $this->generatedAssigneesInOrder($sid);
        $this->gen->generateForSchedule($this->schedules->findById($sid), $now2);
        $allAssignees = $this->generatedAssigneesInOrder($sid);

        // The combined sequence must alternate A,B,A,B,... with no repeats at the seam.
        $expected = [];
        $members = [$a, $b];
        foreach (array_keys($allAssignees) as $i) {
            $expected[] = $members[$i % 2];
        }
        self::assertSame($expected, $allAssignees, 'rotation must not skip or repeat across runs');
        self::assertGreaterThan(count($firstBatch), count($allAssignees));
    }

    public function test_rotation_skips_no_one_when_last_assignee_removed(): void
    {
        $a = $this->insertUser('a@example.com', 'A');
        $b = $this->insertUser('b@example.com', 'B');
        $c = $this->insertUser('c@example.com', 'C');
        $hid = $this->households->createForOwner('Den', $a, $this->tz);
        $this->households->addMember($hid, $b);
        $this->households->addMember($hid, $c);
        $tz = new \DateTimeZone($this->tz);
        $sid = $this->makeSchedule($hid, $a, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-10 09:00:00']);

        // Set the cursor to C, then remove C from the household.
        $this->schedules->setRotation($sid, $c);
        $this->db->run('DELETE FROM household_members WHERE household_id = :h AND user_id = :u', ['h' => $hid, 'u' => $c]);

        $this->gen->generateForSchedule($this->schedules->findById($sid), new \DateTimeImmutable('2026-06-11 08:00:00', $tz));

        $assignees = $this->generatedAssigneesInOrder($sid);
        // C is gone; the pure-function cursor falls through to the head of the current roster [A,B].
        self::assertNotContains($c, $assignees);
        self::assertContains($a, $assignees);
    }

    public function test_fixed_mode_assigns_pinned_user_every_time(): void
    {
        $a = $this->insertUser('a@example.com', 'A');
        $b = $this->insertUser('b@example.com', 'B');
        $hid = $this->households->createForOwner('Den', $a, $this->tz);
        $this->households->addMember($hid, $b);
        $tz = new \DateTimeZone($this->tz);
        $sid = $this->makeSchedule($hid, $a, [
            'rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-10 09:00:00',
            'assignment_mode' => 'fixed', 'fixed_user_id' => $b,
        ]);

        $this->gen->generateForSchedule($this->schedules->findById($sid), new \DateTimeImmutable('2026-06-11 08:00:00', $tz));

        foreach ($this->generatedAssigneesInOrder($sid) as $assignee) {
            self::assertSame($b, $assignee);
        }
    }

    public function test_fixed_mode_with_removed_pinned_user_assigns_null(): void
    {
        $a = $this->insertUser('a@example.com', 'A');
        $b = $this->insertUser('b@example.com', 'B');
        $hid = $this->households->createForOwner('Den', $a, $this->tz);
        $this->households->addMember($hid, $b);
        $tz = new \DateTimeZone($this->tz);
        $sid = $this->makeSchedule($hid, $a, [
            'rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-10 09:00:00',
            'assignment_mode' => 'fixed', 'fixed_user_id' => $b,
        ]);
        // B leaves the household (membership row only).
        $this->db->run('DELETE FROM household_members WHERE household_id = :h AND user_id = :u', ['h' => $hid, 'u' => $b]);

        $this->gen->generateForSchedule($this->schedules->findById($sid), new \DateTimeImmutable('2026-06-11 08:00:00', $tz));

        foreach ($this->generatedRows($sid) as $row) {
            self::assertNull($row['assigned_to']);
        }
    }

    public function test_rotate_with_zero_members_assigns_null(): void
    {
        // An owner-less household is impossible via createForOwner, so simulate by
        // removing all members after creating the schedule.
        $a = $this->insertUser('a@example.com', 'A');
        $hid = $this->households->createForOwner('Den', $a, $this->tz);
        $tz = new \DateTimeZone($this->tz);
        $sid = $this->makeSchedule($hid, $a, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-10 09:00:00']);
        $this->db->run('DELETE FROM household_members WHERE household_id = :h', ['h' => $hid]);

        $this->gen->generateForSchedule($this->schedules->findById($sid), new \DateTimeImmutable('2026-06-11 08:00:00', $tz));

        foreach ($this->generatedRows($sid) as $row) {
            self::assertNull($row['assigned_to']);
        }
    }

    public function test_dst_daily_occurrences_stay_at_local_wall_clock(): void
    {
        // NZ DST ends ~2026-04-05 (NZDT→NZST). A daily 09:00 schedule must keep
        // every generated occurrence at 09:00 local across the transition.
        $uid = $this->insertUser('a@example.com');
        $hid = $this->households->createForOwner('Den', $uid, $this->tz);
        $tz = new \DateTimeZone($this->tz);
        $sid = $this->makeSchedule($hid, $uid, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-04-01 09:00:00']);

        $this->gen->generateForSchedule($this->schedules->findById($sid), new \DateTimeImmutable('2026-04-08 08:00:00', $tz));

        $rows = $this->generatedRows($sid);
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertStringEndsWith('09:00:00', (string) $row['due_at_local'], 'every occurrence stays 9am wall-clock across DST');
        }
    }

    public function test_weekly_byday_generates_only_named_days(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->households->createForOwner('Den', $uid, $this->tz);
        $tz = new \DateTimeZone($this->tz);
        // Weekly Tuesdays. 2026-06-02 is a Tuesday.
        $sid = $this->makeSchedule($hid, $uid, ['rrule' => 'FREQ=WEEKLY;BYDAY=TU', 'anchor_at_local' => '2026-06-02 09:00:00']);

        $this->gen->generateForSchedule($this->schedules->findById($sid), new \DateTimeImmutable('2026-06-20 08:00:00', $tz));

        foreach ($this->generatedRows($sid) as $row) {
            $dow = (new \DateTimeImmutable((string) $row['due_at_local'], $tz))->format('N');
            self::assertSame('2', $dow, 'only Tuesdays generated');
        }
    }

    public function test_generate_for_household_runs_all_active_schedules(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->households->createForOwner('Den', $uid, $this->tz);
        $tz = new \DateTimeZone($this->tz);
        $this->makeSchedule($hid, $uid, ['title' => 'S1', 'rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-10 09:00:00']);
        $this->makeSchedule($hid, $uid, ['title' => 'S2', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO', 'anchor_at_local' => '2026-06-08 18:00:00']);

        $created = $this->gen->generateForHousehold($hid, new \DateTimeImmutable('2026-06-12 08:00:00', $tz));

        self::assertGreaterThan(0, $created);
    }

    // --- helpers ---

    private function insertUser(string $email, string $name = 'Test'): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name) VALUES (:e, :h, :n) RETURNING id',
            ['e' => $email, 'h' => 'unused', 'n' => $name],
        );
    }

    /** @param array<string, mixed> $overrides */
    private function makeSchedule(int $hid, int $uid, array $overrides): int
    {
        return $this->schedules->create($overrides + [
            'household_id' => $hid,
            'created_by' => $uid,
            'title' => 'Take out bins',
            'description' => '',
            'points' => 5,
            'rrule' => 'FREQ=DAILY',
            'anchor_at_local' => '2026-06-10 09:00:00',
            'timezone' => $this->tz,
            'assignment_mode' => 'rotate',
            'fixed_user_id' => null,
        ]);
    }

    private function countGenerated(int $scheduleId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chores WHERE schedule_id = :s',
            ['s' => $scheduleId],
        );
    }

    /** @return list<array<string, mixed>> */
    private function generatedRows(int $scheduleId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM chores WHERE schedule_id = :s ORDER BY occurrence_date ASC, id ASC',
            ['s' => $scheduleId],
        );
    }

    /** @return list<int> assignees in occurrence order (nulls dropped) */
    private function generatedAssigneesInOrder(int $scheduleId): array
    {
        $out = [];
        foreach ($this->generatedRows($scheduleId) as $row) {
            if ($row['assigned_to'] !== null) {
                $out[] = (int) $row['assigned_to'];
            }
        }
        return $out;
    }
}
