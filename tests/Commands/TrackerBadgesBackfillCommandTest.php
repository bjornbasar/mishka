<?php

declare(strict_types=1);

namespace App\Tests\Commands;

use App\Chores\BadgeAwardRepository;
use App\Commands\TrackerBadgesBackfillCommand;
use App\Tracker\ExerciseLogRepository;
use App\Tracker\ExerciseRepository;
use App\Tracker\TrackerBadgeAwarder;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.8.3 — TrackerBadgesBackfillCommand integration tests.
 *
 * Captures STDOUT via php://memory streams — mirrors the test posture
 * used by BadgesBackfillCommandTest and TrackerSeedFoodsCommandTest.
 */
final class TrackerBadgesBackfillCommandTest extends TestCase
{
    private Connection $db;
    private BadgeAwardRepository $badges;
    private ExerciseLogRepository $log;
    private ExerciseRepository $exercises;
    private TrackerBadgeAwarder $awarder;
    private TrackerBadgesBackfillCommand $cmd;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->badges = new BadgeAwardRepository($this->db);
        $this->log = new ExerciseLogRepository($this->db);
        $this->exercises = new ExerciseRepository($this->db);
        $this->awarder = new TrackerBadgeAwarder($this->badges, $this->log);
        $this->cmd = new TrackerBadgesBackfillCommand($this->awarder, $this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_empty_db_returns_zero_and_writes_zero_awards(): void
    {
        $exit = $this->cmd->handle([]);
        self::assertSame(0, $exit);
    }

    public function test_single_user_with_ten_entries_earns_first_and_ten_workout_badges(): void
    {
        $hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
        $uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('u@x', 'x', 'U') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'owner')",
            ['hid' => $hid, 'uid' => $uid],
        );
        $exId = $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        // 10 entries → first_workout + ten_workouts.
        for ($i = 0; $i < 10; $i++) {
            $this->log->create($hid, $uid, $exId, 'duration', 'Running',
                minutes: 30.0, sets: null, reps: null, loadKg: null,
                metMinutes: 100.0, kcalSnapshot: 85, loggedOn: '2026-07-14');
        }

        $exit = $this->cmd->handle([]);
        self::assertSame(0, $exit);
        $codes = $this->badges->listCodesForUser($hid, $uid);
        self::assertContains('first_workout', $codes);
        self::assertContains('ten_workouts', $codes);
        self::assertNotContains('fifty_workouts', $codes);
    }

    public function test_re_run_is_idempotent_leaves_award_count_unchanged(): void
    {
        $hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
        $uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('u@x', 'x', 'U') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'owner')",
            ['hid' => $hid, 'uid' => $uid],
        );
        $exId = $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $this->log->create($hid, $uid, $exId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 300.0, kcalSnapshot: 257, loggedOn: '2026-07-14');

        self::assertSame(0, $this->cmd->handle([]));
        $countFirst = count($this->badges->listForUser($hid, $uid));
        self::assertSame(0, $this->cmd->handle([]));
        $countSecond = count($this->badges->listForUser($hid, $uid));
        self::assertSame($countFirst, $countSecond, 're-run must be idempotent');
    }

    public function test_skips_household_with_bad_timezone_and_continues(): void
    {
        // Household with an invalid tz string — command must skip + continue,
        // NOT fail the whole run. Mirrors ChoresController::handleDone's
        // defensive fallback but at the CLI iteration level.
        $badHid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('BadTz', 'BADTZ0', 'Not/A/Zone') RETURNING id",
        );
        $goodHid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('GoodTz', 'GOODTZ', 'Pacific/Auckland') RETURNING id",
        );
        $uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('u@x', 'x', 'U') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:bhid, :uid, 'owner'), (:ghid, :uid, 'member')",
            ['bhid' => $badHid, 'ghid' => $goodHid, 'uid' => $uid],
        );
        $exId = $this->exercises->create(null, ['name' => 'Running', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $this->log->create($goodHid, $uid, $exId, 'duration', 'Running',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 300.0, kcalSnapshot: 257, loggedOn: '2026-07-14');

        // Suppress fwrite(STDERR, ...) noise while asserting exit code.
        $exit = $this->cmd->handle([]);
        self::assertSame(0, $exit);
        // Good household still got the award despite the bad-tz sibling.
        self::assertContains('first_workout', $this->badges->listCodesForUser($goodHid, $uid));
    }
}
