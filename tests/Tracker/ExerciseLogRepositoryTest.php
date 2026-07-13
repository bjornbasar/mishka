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
}
