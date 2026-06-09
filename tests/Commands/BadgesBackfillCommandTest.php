<?php

declare(strict_types=1);

namespace App\Tests\Commands;

use App\Auth\MishkaUserRepository;
use App\Chores\BadgeAwardRepository;
use App\Commands\BadgesBackfillCommand;
use App\Household\HouseholdRepository;
use Karhu\Auth\PasswordHasher;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.13 — BadgesBackfillCommand unit tests.
 *
 * Seeds chore_points_ledger with various historical patterns + asserts
 * that backfill writes badge_awards with the correct earned_at = the
 * triggering completed_at (NOT the time of the backfill invocation).
 */
final class BadgesBackfillCommandTest extends TestCase
{
    private Connection $db;

    private BadgesBackfillCommand $cmd;

    private BadgeAwardRepository $awards;

    private HouseholdRepository $households;

    private MishkaUserRepository $users;

    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->awards = new BadgeAwardRepository($this->db);
        $this->cmd = new BadgesBackfillCommand($this->awards, $this->db);
        $this->households = new HouseholdRepository($this->db);
        $this->users = new MishkaUserRepository($this->db);
        $this->hasher = new PasswordHasher();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /**
     * Seed N ledger rows with $pointsEach points each, completed_at backdated
     * so row 1 is OLDEST (earned_at on the threshold-crossing test should
     * match the threshold-crossing row's backdated timestamp).
     *
     * @return list<string> the completed_at strings of each inserted row, in
     *                      order of insertion (row 1 oldest → row N newest).
     */
    private function seedLedger(int $hid, int $uid, int $rows, int $pointsEach): array
    {
        $stamps = [];
        for ($i = 0; $i < $rows; $i++) {
            $ts = gmdate('Y-m-d H:i:s', time() - ($rows - $i) * 60);
            $this->db->run(
                "INSERT INTO chore_points_ledger
                    (household_id, chore_id, credited_user_id, points, completed_at)
                 VALUES (:h, NULL, :u, :p, :ts)",
                ['h' => $hid, 'u' => $uid, 'p' => $pointsEach, 'ts' => $ts],
            );
            $stamps[] = $ts;
        }
        return $stamps;
    }

    public function test_backfill_writes_first_chore_with_earned_at_of_first_completion(): void
    {
        $uid = $this->users->create('a@example.com', $this->hasher->hash('x'), 'A');
        $hid = $this->households->createForOwner('Den', $uid);
        $stamps = $this->seedLedger($hid, $uid, rows: 3, pointsEach: 1);

        self::assertSame(0, $this->cmd->handle([]));

        $rows = $this->awards->listForUser($hid, $uid);
        $first = array_values(array_filter($rows, static fn($r) => $r['badge_code'] === 'first_chore'));
        self::assertCount(1, $first);
        // earned_at = the 1st (oldest) completion's completed_at.
        self::assertSame($stamps[0], $first[0]['earned_at']);
    }

    public function test_backfill_writes_ten_chores_with_correct_earned_at(): void
    {
        $uid = $this->users->create('b@example.com', $this->hasher->hash('x'), 'B');
        $hid = $this->households->createForOwner('Den', $uid);
        $stamps = $this->seedLedger($hid, $uid, rows: 12, pointsEach: 1);

        $this->cmd->handle([]);

        $rows = $this->awards->listForUser($hid, $uid);
        $ten = array_values(array_filter($rows, static fn($r) => $r['badge_code'] === 'ten_chores'));
        self::assertCount(1, $ten);
        // earned_at = the 10th completion's completed_at (1-indexed → $stamps[9]).
        self::assertSame($stamps[9], $ten[0]['earned_at']);
    }

    public function test_backfill_writes_fifty_chores(): void
    {
        $uid = $this->users->create('c@example.com', $this->hasher->hash('x'), 'C');
        $hid = $this->households->createForOwner('Den', $uid);
        $this->seedLedger($hid, $uid, rows: 50, pointsEach: 1);

        $this->cmd->handle([]);

        self::assertContains('fifty_chores', $this->awards->listCodesForUser($hid, $uid));
    }

    public function test_backfill_writes_centurion_when_cumulative_crosses_100(): void
    {
        $uid = $this->users->create('d@example.com', $this->hasher->hash('x'), 'D');
        $hid = $this->households->createForOwner('Den', $uid);
        // 4 rows × 30 points = 120 cumulative; cumulative crosses 100 on the
        // 4th row (90 → 120). earned_at should be the 4th row's completed_at.
        $stamps = $this->seedLedger($hid, $uid, rows: 4, pointsEach: 30);

        $this->cmd->handle([]);

        $rows = $this->awards->listForUser($hid, $uid);
        $cent = array_values(array_filter($rows, static fn($r) => $r['badge_code'] === 'centurion'));
        self::assertCount(1, $cent);
        self::assertSame($stamps[3], $cent[0]['earned_at']);   // the 4th row (0-indexed 3)
    }

    public function test_backfill_is_idempotent_second_run_writes_zero(): void
    {
        $uid = $this->users->create('e@example.com', $this->hasher->hash('x'), 'E');
        $hid = $this->households->createForOwner('Den', $uid);
        $this->seedLedger($hid, $uid, rows: 12, pointsEach: 1);

        $this->cmd->handle([]);
        $afterFirst = count($this->awards->listForUser($hid, $uid));

        // Second run: all UNIQUE conflicts; no new rows written.
        $this->cmd->handle([]);
        $afterSecond = count($this->awards->listForUser($hid, $uid));

        self::assertSame($afterFirst, $afterSecond);
        self::assertGreaterThan(0, $afterFirst, 'first run should have written at least one award');
    }
}
