<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Tracker\ExerciseLogRepository;
use App\Tracker\ExerciseRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.8.3 — PG-only smoke for the leaderboard SQL added to
 * ExerciseLogRepository.
 *
 * Guards driver-portability edges the SQLite in-memory suite can't catch:
 *   - LEFT JOIN + `SUM(CASE WHEN e.exercise_type_snapshot = 'strength' ...)` aggregate
 *     on the outer side of the join (PG's null-propagation on missing rows).
 *   - NUMERIC(8,2) SUM roundtrip via PDO (PG returns as string; needs float cast).
 *   - COALESCE(SUM(NULL), 0) semantics for a strength-only user.
 *   - `household_members` INNER JOIN dropping departed users.
 *   - `u.id > 0` sentinel-user guard.
 *
 * SKIPS unless DB_DSN points at pgsql://. Explicit txn + rollBack() so
 * smoke-test rows never leak into shareddb. See DOCS #71 / #72.
 */
final class ExerciseLogRepositoryLeaderboardPgSmokeTest extends TestCase
{
    private Connection $db;
    private ExerciseLogRepository $log;
    private ExerciseRepository $exercises;
    private int $hid;
    private int $uid;
    private int $durationExerciseId;
    private int $strengthExerciseId;

    protected function setUp(): void
    {
        $dsn = getenv('DB_DSN') ?: ($_ENV['DB_DSN'] ?? '');
        if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) {
            self::markTestSkipped('PG smoke tests require DB_DSN=pgsql:...');
        }
        $this->db = new Connection(
            $dsn,
            (string) (getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? '')),
            (string) (getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '')),
        );
        $this->db->pdo()->beginTransaction();
        $this->log = new ExerciseLogRepository($this->db);
        $this->exercises = new ExerciseRepository($this->db);

        $this->hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('SmokeHH', 'SMOK01', 'Pacific/Auckland') RETURNING id",
        );
        $this->uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('smoke1@example.test', 'x', 'Smoke1') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'owner')",
            ['hid' => $this->hid, 'uid' => $this->uid],
        );
        $this->durationExerciseId = $this->exercises->create($this->hid, ['name' => 'SmokeRun', 'type' => 'duration', 'met' => 9.8, 'source' => 'custom'], $this->uid);
        $this->strengthExerciseId = $this->exercises->create($this->hid, ['name' => 'SmokeSquat', 'type' => 'strength', 'met' => 5.0, 'default_rom_m' => 0.5, 'source' => 'custom'], $this->uid);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_weekly_leaderboard_numeric_sum_roundtrips_via_pdo(): void
    {
        // NUMERIC(8,2) SUM returns as string via PG's PDO — test the roundtrip.
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'SmokeRun',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 294.50, kcalSnapshot: 257, loggedOn: '2026-07-14');
        $this->log->create($this->hid, $this->uid, $this->durationExerciseId, 'duration', 'SmokeRun',
            minutes: 15.0, sets: null, reps: null, loadKg: null,
            metMinutes: 147.25, kcalSnapshot: 128, loggedOn: '2026-07-15');

        $rows = $this->log->weeklyLeaderboardForHousehold($this->hid, '2026-07-13', '2026-07-20');
        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(441.75, (float) $rows[0]['week_met_minutes'], 0.005);
    }

    public function test_cumulative_stats_for_strength_only_user_returns_zero_met_minutes(): void
    {
        // Only strength entries → SUM(met_minutes) is NULL → COALESCE → 0.
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'SmokeSquat',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: null, loggedOn: '2026-07-14');
        $this->log->create($this->hid, $this->uid, $this->strengthExerciseId, 'strength', 'SmokeSquat',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: null, loggedOn: '2026-07-15');

        $stats = $this->log->cumulativeStatsForUser($this->uid, $this->hid);
        self::assertSame(2, $stats['count']);
        self::assertSame(0, $stats['total_met_minutes']);
    }

    public function test_weekly_leaderboard_drops_departed_household_member(): void
    {
        // Create a second user, then delete the membership row → they must
        // NOT appear in the leaderboard even though the exercise_log row
        // remains (user_id CASCADE-scoped, membership drop is orthogonal).
        $u2 = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('smoke2@example.test', 'x', 'Smoke2') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'member')",
            ['hid' => $this->hid, 'uid' => $u2],
        );
        // u2 logs a workout, then leaves the household.
        $this->log->create($this->hid, $u2, $this->durationExerciseId, 'duration', 'SmokeRun',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 300.0, kcalSnapshot: 257, loggedOn: '2026-07-14');
        $this->db->run(
            "DELETE FROM household_members WHERE household_id = :hid AND user_id = :uid",
            ['hid' => $this->hid, 'uid' => $u2],
        );

        $rows = $this->log->weeklyLeaderboardForHousehold($this->hid, '2026-07-13', '2026-07-20');
        self::assertCount(1, $rows, 'departed member must not appear in leaderboard');
        self::assertSame($this->uid, $rows[0]['user_id']);
    }

    public function test_weekly_leaderboard_excludes_sentinel_user_id_zero(): void
    {
        // A `users(id=0)` sentinel would be a rare invariant break, but the
        // `u.id > 0` guard mirrors ChoreRepository::leaderboardForHousehold
        // — prove PG honours it even if such a row got inserted.
        try {
            $this->db->run(
                "INSERT INTO users (id, email, password_hash, display_name) VALUES (0, 'sentinel@example.test', 'x', 'Sentinel')",
            );
        } catch (\Throwable $e) {
            // If the driver / sequence rejects id=0, the guard is vacuously
            // safe. Skip rather than fail — the invariant we care about
            // (guard is present in the SQL) is already verified by the
            // in-memory suite.
            self::markTestSkipped('driver rejects users(id=0) insert: ' . $e->getMessage());
        }
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, 0, 'member')",
            ['hid' => $this->hid],
        );
        $rows = $this->log->weeklyLeaderboardForHousehold($this->hid, '2026-07-13', '2026-07-20');
        foreach ($rows as $r) {
            self::assertGreaterThan(0, $r['user_id'], 'sentinel user (id=0) must not appear in leaderboard');
        }
    }
}
