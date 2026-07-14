<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tracker\ExerciseLogRepository;
use App\Tracker\ExerciseRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

final class ExerciseLogRepositoryTest extends TestCase
{
    private Connection $db;
    private ExerciseRepository $exercises;
    private ExerciseLogRepository $log;
    private int $hid;
    private int $uid;
    private int $durationExerciseId;
    private int $strengthExerciseId;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->exercises = new ExerciseRepository($this->db);
        $this->log = new ExerciseLogRepository($this->db);
        $this->hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
        $this->uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('u@x', 'x', 'User') RETURNING id",
        );
        $this->durationExerciseId = $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $this->strengthExerciseId = $this->exercises->create(null, ['name' => 'Squats', 'type' => 'strength', 'met' => 5.0, 'default_rom_m' => 0.5, 'source' => 'compendium'], null);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_duration_branch_create_works(): void
    {
        $id = $this->log->create(
            $this->hid, $this->uid, $this->durationExerciseId,
            'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 294.0, kcalSnapshot: 257,
            loggedOn: '2026-07-13',
        );
        self::assertGreaterThan(0, $id);
    }

    public function test_strength_branch_create_works(): void
    {
        $id = $this->log->create(
            $this->hid, $this->uid, $this->strengthExerciseId,
            'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: 4,
            loggedOn: '2026-07-13',
        );
        self::assertGreaterThan(0, $id);
    }

    public function test_duration_branch_rejects_missing_minutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: null, sets: null, reps: null, loadKg: null,
            metMinutes: null, kcalSnapshot: null, loggedOn: '2026-07-13');
    }

    public function test_duration_branch_rejects_strength_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: 3, reps: 10, loadKg: null,
            metMinutes: 294.0, kcalSnapshot: null, loggedOn: '2026-07-13');
    }

    public function test_strength_branch_rejects_missing_sets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: null, sets: null, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: 4, loggedOn: '2026-07-13');
    }

    public function test_strength_branch_rejects_non_null_minutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: 30.0, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: 4, loggedOn: '2026-07-13');
    }

    public function test_strength_branch_rejects_non_null_met_minutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: 15.0, kcalSnapshot: 4, loggedOn: '2026-07-13');
    }

    public function test_list_for_user_day_returns_mixed_branches(): void
    {
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 294.0, kcalSnapshot: 257, loggedOn: '2026-07-13');
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: 4, loggedOn: '2026-07-13');

        $entries = $this->log->listForUserDay($this->uid, $this->hid, '2026-07-13');
        self::assertCount(2, $entries);
        self::assertSame('duration', $entries[0]['exercise_type']);
        self::assertSame('strength', $entries[1]['exercise_type']);
        self::assertNull($entries[1]['met_minutes']);
        self::assertSame(3, $entries[1]['sets']);
    }

    public function test_list_survives_exercise_deletion(): void
    {
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 294.0, kcalSnapshot: 257, loggedOn: '2026-07-13');
        $this->exercises->delete($this->durationExerciseId);
        $entries = $this->log->listForUserDay($this->uid, $this->hid, '2026-07-13');
        self::assertCount(1, $entries);
        self::assertSame('Running', $entries[0]['exercise_name'], 'snapshot preserves name after delete');
        self::assertNull($entries[0]['exercise_id']);
    }

    public function test_exercise_kcal_for_user_day_sums_own_entries(): void
    {
        // v0.8.2 per-user aggregation for the Today widget. kcal_snapshot
        // IS nullable on exercise_log (strength w/o ROM); COALESCE(NULL, 0)
        // treats those as 0 contribution.
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 294.0, kcalSnapshot: 250, loggedOn: '2026-07-14');
        // Strength with unknown ROM → kcal_snapshot NULL → contributes 0.
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: null, loggedOn: '2026-07-14');

        self::assertSame(250, $this->log->exerciseKcalForUserDay($this->uid, $this->hid, '2026-07-14'));
    }

    public function test_exercise_kcal_scopes_to_user_and_household(): void
    {
        $otherUser = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('bb@x', 'x', 'BB') RETURNING id",
        );
        // Different user, same household — should NOT count.
        $this->log->create($this->hid, $otherUser, $this->durationExerciseId, 'duration', 'Running',
            minutes: 60.0, sets: null, reps: null, loadKg: null,
            metMinutes: 588.0, kcalSnapshot: 999, loggedOn: '2026-07-14');
        // Same user, correct household — SHOULD count.
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 15.0, sets: null, reps: null, loadKg: null,
            metMinutes: 147.0, kcalSnapshot: 111, loggedOn: '2026-07-14');

        self::assertSame(111, $this->log->exerciseKcalForUserDay($this->uid, $this->hid, '2026-07-14'));
    }

    public function test_daily_totals_sums_met_minutes_and_kcal(): void
    {
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 294.0, kcalSnapshot: 257, loggedOn: '2026-07-13');
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: 4, loggedOn: '2026-07-13');

        $totals = $this->log->dailyTotalsForHousehold($this->hid, '2026-07-13');
        self::assertArrayHasKey($this->uid, $totals);
        // met_minutes: 294 + NULL → 294 (COALESCE treats strength as 0 contribution).
        self::assertEqualsWithDelta(294.0, (float) $totals[$this->uid]['total_met_minutes'], 0.01);
        // kcal: 257 + 4 = 261.
        self::assertSame(261, $totals[$this->uid]['total_kcal']);
        self::assertSame(2, $totals[$this->uid]['entries']);
    }

    public function test_delete_owned_by(): void
    {
        $id = $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 294.0, kcalSnapshot: 257, loggedOn: '2026-07-13');
        self::assertSame(1, $this->log->deleteOwnedById($id, $this->uid));
        self::assertCount(0, $this->log->listForUserDay($this->uid, $this->hid, '2026-07-13'));
    }

    // ================================================================
    // v0.8.3 — leaderboard SQL + cumulative + streak-feed methods.
    // ================================================================

    /** Add uid as an owner-member of hid. Leaderboard SQL JOINs on household_members. */
    private function joinHousehold(int $uid, int $hid, string $role = 'owner'): void
    {
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, :role)",
            ['hid' => $hid, 'uid' => $uid, 'role' => $role],
        );
    }

    public function test_weekly_leaderboard_zero_effort_member_appears_with_muted_row(): void
    {
        $this->joinHousehold($this->uid, $this->hid);
        $rows = $this->log->weeklyLeaderboardForHousehold($this->hid, '2026-07-13', '2026-07-20');
        self::assertCount(1, $rows);
        self::assertSame($this->uid, $rows[0]['user_id']);
        self::assertEqualsWithDelta(0.0, (float) $rows[0]['week_met_minutes'], 0.01);
        self::assertSame(0, $rows[0]['week_strength_sessions']);
        self::assertSame(0, $rows[0]['week_entries']);
    }

    public function test_weekly_leaderboard_happy_path_two_users_ranked_by_met_minutes(): void
    {
        $this->joinHousehold($this->uid, $this->hid);
        $u2 = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('u2@x', 'x', 'User Two') RETURNING id",
        );
        $this->joinHousehold($u2, $this->hid, 'member');

        // u1: 300 MET-min duration + 1 strength session
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 300.0, kcalSnapshot: 257, loggedOn: '2026-07-14');
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: null, loggedOn: '2026-07-15');
        // u2: 150 MET-min duration only
        $this->log->create($this->hid, $u2, $this->durationExerciseId, 'duration', 'Running',
            minutes: 15.0, sets: null, reps: null, loadKg: null,
            metMinutes: 150.0, kcalSnapshot: 128, loggedOn: '2026-07-14');

        $rows = $this->log->weeklyLeaderboardForHousehold($this->hid, '2026-07-13', '2026-07-20');
        self::assertCount(2, $rows);
        // Ranked: u1 (300) > u2 (150).
        self::assertSame($this->uid, $rows[0]['user_id']);
        self::assertEqualsWithDelta(300.0, (float) $rows[0]['week_met_minutes'], 0.01);
        self::assertSame(1, $rows[0]['week_strength_sessions']);
        self::assertSame(2, $rows[0]['week_entries']);
        self::assertSame($u2, $rows[1]['user_id']);
        self::assertEqualsWithDelta(150.0, (float) $rows[1]['week_met_minutes'], 0.01);
    }

    public function test_weekly_leaderboard_strength_only_user_appears_with_zero_met_minutes(): void
    {
        $this->joinHousehold($this->uid, $this->hid);
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: null, loggedOn: '2026-07-14');
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: null, loggedOn: '2026-07-15');

        $rows = $this->log->weeklyLeaderboardForHousehold($this->hid, '2026-07-13', '2026-07-20');
        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(0.0, (float) $rows[0]['week_met_minutes'], 0.01);
        self::assertSame(2, $rows[0]['week_strength_sessions']);
        self::assertSame(2, $rows[0]['week_entries']);
    }

    public function test_weekly_leaderboard_excludes_cross_household_entries(): void
    {
        $this->joinHousehold($this->uid, $this->hid);
        $otherHid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('Other', 'BBBBBB', 'Pacific/Auckland') RETURNING id",
        );
        // uid entry in the OTHER household — must not appear in $this->hid's leaderboard.
        $this->log->create($otherHid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 60.0, sets: null, reps: null, loadKg: null,
            metMinutes: 588.0, kcalSnapshot: 500, loggedOn: '2026-07-14');

        $rows = $this->log->weeklyLeaderboardForHousehold($this->hid, '2026-07-13', '2026-07-20');
        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(0.0, (float) $rows[0]['week_met_minutes'], 0.01);
    }

    public function test_weekly_leaderboard_boundary_entry_on_week_end_is_excluded(): void
    {
        // Half-open [ws, we) — entries on `we` MUST NOT count.
        $this->joinHousehold($this->uid, $this->hid);
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 300.0, kcalSnapshot: 257, loggedOn: '2026-07-20');  // == weekEnd

        $rows = $this->log->weeklyLeaderboardForHousehold($this->hid, '2026-07-13', '2026-07-20');
        self::assertEqualsWithDelta(0.0, (float) $rows[0]['week_met_minutes'], 0.01);
    }

    public function test_weekly_leaderboard_tiebreak_earlier_joined_wins(): void
    {
        // Both users log same MET-min in same week. Earlier joined_at wins the tie.
        $u2 = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('u2@x', 'x', 'User Two') RETURNING id",
        );
        $this->joinHousehold($this->uid, $this->hid);
        // Sleep to guarantee joined_at ordering across drivers (SQLite/PG both).
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role, joined_at) VALUES (:hid, :uid, 'member', :ts)",
            ['hid' => $this->hid, 'uid' => $u2, 'ts' => '2999-01-01 00:00:00'],
        );
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 100.0, kcalSnapshot: 100, loggedOn: '2026-07-14');
        $this->log->create($this->hid, $u2, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 100.0, kcalSnapshot: 100, loggedOn: '2026-07-15');

        $rows = $this->log->weeklyLeaderboardForHousehold($this->hid, '2026-07-13', '2026-07-20');
        self::assertSame($this->uid, $rows[0]['user_id'], 'earlier joined_at should win the tie');
    }

    public function test_cumulative_stats_for_user_counts_and_sums(): void
    {
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 300.0, kcalSnapshot: 257, loggedOn: '2026-07-14');
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 15.0, sets: null, reps: null, loadKg: null,
            metMinutes: 150.0, kcalSnapshot: 128, loggedOn: '2026-07-15');
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: null, loggedOn: '2026-07-16');

        $s = $this->log->cumulativeStatsForUser($this->uid, $this->hid);
        self::assertSame(3, $s['count']);
        self::assertSame(450, $s['total_met_minutes']);
    }

    public function test_cumulative_stats_for_user_empty_returns_zeros(): void
    {
        $s = $this->log->cumulativeStatsForUser($this->uid, $this->hid);
        self::assertSame(0, $s['count']);
        self::assertSame(0, $s['total_met_minutes']);
    }

    public function test_recent_logged_ons_for_household_batches_by_user(): void
    {
        $this->joinHousehold($this->uid, $this->hid);
        $u2 = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('u2@x', 'x', 'User Two') RETURNING id",
        );
        $this->joinHousehold($u2, $this->hid, 'member');

        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 300.0, kcalSnapshot: 257, loggedOn: '2026-07-14');
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 15.0, sets: null, reps: null, loadKg: null,
            metMinutes: 150.0, kcalSnapshot: 128, loggedOn: '2026-07-13');
        $this->log->create($this->hid, $u2, $this->durationExerciseId, 'duration', 'Running',
            minutes: 20.0, sets: null, reps: null, loadKg: null,
            metMinutes: 200.0, kcalSnapshot: 200, loggedOn: '2026-07-15');

        $out = $this->log->recentLoggedOnsForHousehold($this->hid, '2026-07-10', '2026-07-20');
        self::assertArrayHasKey($this->uid, $out);
        self::assertArrayHasKey($u2, $out);
        // u1's dates ordered DESC.
        self::assertSame(['2026-07-14', '2026-07-13'], $out[$this->uid]);
        self::assertSame(['2026-07-15'], $out[$u2]);
    }

    public function test_recent_logged_ons_for_user_returns_desc_ordered_dates(): void
    {
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 300.0, kcalSnapshot: 257, loggedOn: '2026-07-12');
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 15.0, sets: null, reps: null, loadKg: null,
            metMinutes: 150.0, kcalSnapshot: 128, loggedOn: '2026-07-14');

        $out = $this->log->recentLoggedOnsForUser($this->uid, $this->hid, '2026-07-10', '2026-07-20');
        self::assertSame(['2026-07-14', '2026-07-12'], $out);
    }

    public function test_daily_met_minutes_for_user_buckets_by_date(): void
    {
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 300.0, kcalSnapshot: 257, loggedOn: '2026-07-14');
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'Running',
            minutes: 15.0, sets: null, reps: null, loadKg: null,
            metMinutes: 150.0, kcalSnapshot: 128, loggedOn: '2026-07-14');
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'Squats',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: null, loggedOn: '2026-07-14');   // NULL doesn't add

        $out = $this->log->dailyMetMinutesForUser($this->uid, $this->hid, '2026-07-10', '2026-07-20');
        self::assertArrayHasKey('2026-07-14', $out);
        self::assertEqualsWithDelta(450.0, $out['2026-07-14'], 0.01);
    }
}
