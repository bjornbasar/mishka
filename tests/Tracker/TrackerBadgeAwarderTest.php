<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Chores\BadgeAwardRepository;
use App\Tracker\ExerciseLogRepository;
use App\Tracker\ExerciseRepository;
use App\Tracker\TrackerBadgeAwarder;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.8.3 — TrackerBadgeAwarder integration tests.
 *
 * Extends PHPUnit\Framework\TestCase directly (repo-adjacent, no HTTP).
 * Reuses the ambient $GLOBALS['test_db'] Connection from tests/bootstrap.php.
 */
final class TrackerBadgeAwarderTest extends TestCase
{
    private Connection $db;
    private BadgeAwardRepository $badges;
    private ExerciseLogRepository $log;
    private ExerciseRepository $exercises;
    private TrackerBadgeAwarder $awarder;
    private int $hid;
    private int $uid;
    private int $durationExerciseId;
    private int $strengthExerciseId;
    private \DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->badges = new BadgeAwardRepository($this->db);
        $this->log = new ExerciseLogRepository($this->db);
        $this->exercises = new ExerciseRepository($this->db);
        $this->awarder = new TrackerBadgeAwarder($this->badges, $this->log);

        $this->hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
        $this->uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('u@x', 'x', 'User') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'owner')",
            ['hid' => $this->hid, 'uid' => $this->uid],
        );
        $this->durationExerciseId = $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $this->strengthExerciseId = $this->exercises->create(null, ['name' => 'Squats', 'type' => 'strength', 'met' => 5.0, 'default_rom_m' => 0.5, 'source' => 'compendium'], null);
        $this->tz = new \DateTimeZone('Pacific/Auckland');
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    // --- guard clauses ---

    public function test_zero_household_id_is_a_noop(): void
    {
        $this->awarder->evaluateAndGrant(0, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        self::assertCount(0, $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    public function test_zero_user_id_is_a_noop(): void
    {
        $this->awarder->evaluateAndGrant($this->hid, 0, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        self::assertCount(0, $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    // --- count-based thresholds ---

    public function test_first_workout_awarded_after_one_entry(): void
    {
        $this->logDuration(50.0, '2026-07-14');
        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        self::assertContains('first_workout', $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    public function test_ten_workouts_awarded_after_ten_entries(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->logDuration(50.0, '2026-07-14');
        }
        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        $codes = $this->badges->listCodesForUser($this->hid, $this->uid);
        self::assertContains('first_workout', $codes);
        self::assertContains('ten_workouts', $codes);
    }

    public function test_fifty_workouts_awarded_after_fifty_entries(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->logDuration(50.0, '2026-07-14');
        }
        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        self::assertContains('fifty_workouts', $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    public function test_count_threshold_fires_on_strength_only_entries(): void
    {
        // Strength entries have met_minutes=NULL but still count toward COUNT.
        for ($i = 0; $i < 10; $i++) {
            $this->logStrength('2026-07-14');
        }
        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        self::assertContains('ten_workouts', $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    // --- MET-minute thresholds ---

    public function test_five_hundred_met_minutes_awarded(): void
    {
        // 3 × 200 = 600 MET-min → crosses 500.
        $this->logDuration(200.0, '2026-07-12');
        $this->logDuration(200.0, '2026-07-13');
        $this->logDuration(200.0, '2026-07-14');
        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        self::assertContains('five_hundred_met_minutes', $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    public function test_five_thousand_met_minutes_awarded_after_crossing_threshold(): void
    {
        // 25 × 200 = 5000. Log 26 to be safe.
        for ($i = 0; $i < 26; $i++) {
            $this->logDuration(200.0, '2026-07-14');
        }
        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        self::assertContains('five_thousand_met_minutes', $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    // --- active_week + weekly-effort streak ---

    public function test_active_week_awarded_at_150_met_minutes_in_one_week(): void
    {
        // Single 150 MET-min entry — hits WHO baseline exactly.
        $this->logDuration(150.0, '2026-07-14');
        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        self::assertContains('active_week', $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    public function test_four_week_effort_streak_awarded_from_four_active_weeks(): void
    {
        // 4 consecutive weeks ending "this week" 2026-07-13..07-19 (ISO 2026-W29).
        // Weekly buckets: 2026-W29 (13..19), W28 (06..12), W27 (Jun 29..Jul 5), W26 (Jun 22..28).
        $this->logDuration(200.0, '2026-07-15');   // W29
        $this->logDuration(200.0, '2026-07-08');   // W28
        $this->logDuration(200.0, '2026-07-01');   // W27
        $this->logDuration(200.0, '2026-06-24');   // W26

        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-15 10:00', $this->tz));
        self::assertContains('four_week_effort_streak', $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    public function test_four_week_effort_streak_NOT_awarded_when_gap(): void
    {
        // 3 weeks active, then miss, then 1 more → streak = 1 (current week only).
        $this->logDuration(200.0, '2026-07-15');   // W29 (this week)
        // MISS W28 entirely
        $this->logDuration(200.0, '2026-07-01');   // W27
        $this->logDuration(200.0, '2026-06-24');   // W26

        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-15 10:00', $this->tz));
        self::assertNotContains('four_week_effort_streak', $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    // --- daily-activity streaks ---

    public function test_seven_day_activity_streak_awarded_after_seven_consecutive_days(): void
    {
        for ($d = 8; $d <= 14; $d++) {
            $this->logDuration(50.0, sprintf('2026-07-%02d', $d));
        }
        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        self::assertContains('seven_day_activity_streak', $this->badges->listCodesForUser($this->hid, $this->uid));
    }

    public function test_thirty_day_activity_streak_awarded_after_thirty_consecutive_days(): void
    {
        // 30 days ending 2026-07-14: 2026-06-15 .. 2026-07-14
        $start = new \DateTimeImmutable('2026-06-15', $this->tz);
        for ($i = 0; $i < 30; $i++) {
            $this->logDuration(50.0, $start->modify("+{$i} days")->format('Y-m-d'));
        }
        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, new \DateTimeImmutable('2026-07-14 10:00', $this->tz));
        $codes = $this->badges->listCodesForUser($this->hid, $this->uid);
        self::assertContains('seven_day_activity_streak', $codes);
        self::assertContains('thirty_day_activity_streak', $codes);
    }

    // --- idempotency ---

    public function test_re_award_attempt_is_silent_no_op(): void
    {
        $this->logDuration(150.0, '2026-07-14');
        $now = new \DateTimeImmutable('2026-07-14 10:00', $this->tz);

        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, $now);
        $countAfterFirst = count($this->badges->listForUser($this->hid, $this->uid));

        // Second call with same state — must be a no-op (UNIQUE constraint).
        $this->awarder->evaluateAndGrant($this->hid, $this->uid, $this->tz, $now);
        $countAfterSecond = count($this->badges->listForUser($this->hid, $this->uid));

        self::assertSame($countAfterFirst, $countAfterSecond);
    }

    // --- pure-function unit tests for computeWeeklyMetStreak ---

    public function test_compute_weekly_met_streak_empty_returns_zero(): void
    {
        self::assertSame(0, TrackerBadgeAwarder::computeWeeklyMetStreak([], '2026-W29', 150));
    }

    public function test_compute_weekly_met_streak_single_week_at_threshold_is_one(): void
    {
        $daily = ['2026-07-14' => 150.0];
        self::assertSame(1, TrackerBadgeAwarder::computeWeeklyMetStreak($daily, '2026-W29', 150));
    }

    public function test_compute_weekly_met_streak_below_threshold_this_week_is_zero(): void
    {
        $daily = ['2026-07-14' => 100.0];
        self::assertSame(0, TrackerBadgeAwarder::computeWeeklyMetStreak($daily, '2026-W29', 150));
    }

    public function test_compute_weekly_met_streak_four_consecutive_weeks_is_four(): void
    {
        // Weeks 2026-W26, W27, W28, W29 each with ≥150 MET-min.
        $daily = [
            '2026-06-24' => 200.0,   // W26
            '2026-07-01' => 200.0,   // W27
            '2026-07-08' => 200.0,   // W28
            '2026-07-15' => 200.0,   // W29
        ];
        self::assertSame(4, TrackerBadgeAwarder::computeWeeklyMetStreak($daily, '2026-W29', 150));
    }

    public function test_compute_weekly_met_streak_gap_breaks_at_gap(): void
    {
        // W29 + W27 + W26 active; W28 missing.
        $daily = [
            '2026-06-24' => 200.0,   // W26
            '2026-07-01' => 200.0,   // W27
            // W28 MISSING
            '2026-07-15' => 200.0,   // W29
        ];
        self::assertSame(1, TrackerBadgeAwarder::computeWeeklyMetStreak($daily, '2026-W29', 150));
    }

    public function test_iso_week_key_year_boundary(): void
    {
        // 2027-01-01 (Fri) belongs to ISO week 2026-W53.
        self::assertSame('2026-W53', TrackerBadgeAwarder::isoWeekKey('2027-01-01'));
        // 2028-01-01 (Sat) belongs to ISO week 2027-W52.
        self::assertSame('2027-W52', TrackerBadgeAwarder::isoWeekKey('2028-01-01'));
    }

    // --- helpers ---

    private function logDuration(float $metMinutes, string $day): int
    {
        return $this->log->create(
            $this->hid, $this->uid, $this->durationExerciseId,
            'duration', 'Running',
            minutes: $metMinutes / 9.8, sets: null, reps: null, loadKg: null,
            metMinutes: $metMinutes, kcalSnapshot: (int) round($metMinutes * 0.85), loggedOn: $day,
        );
    }

    private function logStrength(string $day): int
    {
        return $this->log->create(
            $this->hid, $this->uid, $this->strengthExerciseId,
            'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: null, loggedOn: $day,
        );
    }
}
