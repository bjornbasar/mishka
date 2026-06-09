<?php

declare(strict_types=1);

namespace App\Tests\Chores;

use App\Auth\MishkaUserRepository;
use App\Chores\BadgeAwardRepository;
use App\Chores\BadgeAwarder;
use App\Chores\ChoreRepository;
use App\Household\HouseholdRepository;
use Karhu\Auth\PasswordHasher;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.13 — BadgeAwarder unit tests. Seeds chore_points_ledger directly to
 * simulate post-completion state, then asserts the 6 thresholds fire (or
 * don't) and that the grants are idempotent.
 */
final class BadgeAwarderTest extends TestCase
{
    private Connection $db;

    private BadgeAwarder $awarder;

    private BadgeAwardRepository $awards;

    private ChoreRepository $chores;

    private \DateTimeZone $tz;

    private int $uid;

    private int $hid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->awards = new BadgeAwardRepository($this->db);
        $this->chores = new ChoreRepository($this->db);
        $this->awarder = new BadgeAwarder($this->awards, $this->chores);

        $users = new MishkaUserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $hasher = new PasswordHasher();
        $this->uid = $users->create('a@example.com', $hasher->hash('x'), 'A');
        $this->hid = $households->createForOwner('Den', $this->uid);
        $this->tz = new \DateTimeZone('Pacific/Auckland');
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /**
     * Insert N ledger rows for the test user. Spreads completed_at into the
     * past to keep the running-points-aware tests deterministic (each row's
     * completed_at = now - (N - i) seconds, so row 1 is oldest, row N newest).
     */
    private function seedLedger(int $rows, int $pointsEach): string
    {
        $latest = gmdate('Y-m-d H:i:s');
        for ($i = 0; $i < $rows; $i++) {
            $ts = gmdate('Y-m-d H:i:s', time() - ($rows - $i - 1));
            $this->db->run(
                "INSERT INTO chore_points_ledger
                    (household_id, chore_id, credited_user_id, points, completed_at)
                 VALUES (:h, NULL, :u, :p, :ts)",
                ['h' => $this->hid, 'u' => $this->uid, 'p' => $pointsEach, 'ts' => $ts],
            );
        }
        return $latest;
    }

    public function test_evaluateAndGrant_writes_first_chore_on_first_completion(): void
    {
        $completedAt = $this->seedLedger(rows: 1, pointsEach: 5);

        $this->awarder->evaluateAndGrant(
            $this->hid, $this->uid, $completedAt, $this->tz, new \DateTimeImmutable('now'),
        );

        self::assertContains('first_chore', $this->awards->listCodesForUser($this->hid, $this->uid));
    }

    public function test_evaluateAndGrant_writes_ten_chores_on_tenth(): void
    {
        $completedAt = $this->seedLedger(rows: 10, pointsEach: 1);

        $this->awarder->evaluateAndGrant(
            $this->hid, $this->uid, $completedAt, $this->tz, new \DateTimeImmutable('now'),
        );

        $codes = $this->awards->listCodesForUser($this->hid, $this->uid);
        self::assertContains('first_chore', $codes);
        self::assertContains('ten_chores', $codes);
        self::assertNotContains('fifty_chores', $codes);
    }

    public function test_evaluateAndGrant_writes_fifty_chores_on_fiftieth(): void
    {
        $completedAt = $this->seedLedger(rows: 50, pointsEach: 1);

        $this->awarder->evaluateAndGrant(
            $this->hid, $this->uid, $completedAt, $this->tz, new \DateTimeImmutable('now'),
        );

        $codes = $this->awards->listCodesForUser($this->hid, $this->uid);
        self::assertContains('fifty_chores', $codes);
    }

    public function test_evaluateAndGrant_writes_centurion_when_cumulative_crosses_100(): void
    {
        // 5 completions × 25 points = 125 total points; >= 100 fires centurion.
        $completedAt = $this->seedLedger(rows: 5, pointsEach: 25);

        $this->awarder->evaluateAndGrant(
            $this->hid, $this->uid, $completedAt, $this->tz, new \DateTimeImmutable('now'),
        );

        $codes = $this->awards->listCodesForUser($this->hid, $this->uid);
        self::assertContains('centurion', $codes);
        self::assertNotContains('five_hundred', $codes);
    }

    public function test_evaluateAndGrant_writes_five_hundred_when_cumulative_crosses_500(): void
    {
        // 10 × 50 = 500 — exactly at threshold (>= 500 fires).
        $completedAt = $this->seedLedger(rows: 10, pointsEach: 50);

        $this->awarder->evaluateAndGrant(
            $this->hid, $this->uid, $completedAt, $this->tz, new \DateTimeImmutable('now'),
        );

        $codes = $this->awards->listCodesForUser($this->hid, $this->uid);
        self::assertContains('five_hundred', $codes);
        self::assertContains('centurion', $codes);
    }

    public function test_evaluateAndGrant_writes_four_week_streak_when_streak_reaches_4(): void
    {
        // Seed 4 ledger rows in 4 distinct household-tz weeks, walking back
        // from now. Each row's completed_at is a Monday-midnight + 1 hour
        // (offset so it's clearly inside that week regardless of DST).
        $now = new \DateTimeImmutable('2026-06-08 10:00:00', new \DateTimeZone('UTC'));
        for ($weekOffset = 0; $weekOffset < 4; $weekOffset++) {
            $weekBack = $now->setTimezone($this->tz)
                ->modify('monday this week')
                ->modify('-' . $weekOffset . ' weeks')
                ->setTime(9, 0, 0);
            $ts = $weekBack->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->db->run(
                "INSERT INTO chore_points_ledger (household_id, chore_id, credited_user_id, points, completed_at)
                 VALUES (:h, NULL, :u, 1, :ts)",
                ['h' => $this->hid, 'u' => $this->uid, 'ts' => $ts],
            );
        }

        $this->awarder->evaluateAndGrant(
            $this->hid, $this->uid, gmdate('Y-m-d H:i:s'), $this->tz, $now,
        );

        self::assertContains('four_week_streak', $this->awards->listCodesForUser($this->hid, $this->uid));
    }

    public function test_evaluateAndGrant_is_idempotent_on_repeated_call(): void
    {
        $completedAt = $this->seedLedger(rows: 1, pointsEach: 5);
        $earliest = gmdate('Y-m-d H:i:s', time() - 3600);

        // First call writes first_chore with the seeded completed_at.
        $this->awarder->evaluateAndGrant(
            $this->hid, $this->uid, $earliest, $this->tz, new \DateTimeImmutable('now'),
        );
        // Second call with a NEWER timestamp must NOT update the row.
        $this->awarder->evaluateAndGrant(
            $this->hid, $this->uid, $completedAt, $this->tz, new \DateTimeImmutable('now'),
        );

        $rows = $this->awards->listForUser($this->hid, $this->uid);
        // Filter for first_chore — there could also be no other badges yet.
        $firstChore = array_values(array_filter($rows, static fn(array $r) => $r['badge_code'] === 'first_chore'));
        self::assertCount(1, $firstChore);
        // earned_at preserved from the first call (NOT overwritten).
        self::assertSame($earliest, $firstChore[0]['earned_at']);
    }

    public function test_evaluateAndGrant_no_threshold_crossed_writes_nothing(): void
    {
        // User isn't even in the leaderboard (no ledger rows). evaluateAndGrant
        // finds no row in the board → returns early without writing.
        $this->awarder->evaluateAndGrant(
            $this->hid, $this->uid, gmdate('Y-m-d H:i:s'), $this->tz, new \DateTimeImmutable('now'),
        );

        self::assertSame([], $this->awards->listCodesForUser($this->hid, $this->uid));
    }
}
