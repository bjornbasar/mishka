<?php

declare(strict_types=1);

namespace App\Tests\Chores;

use App\Chores\ChoreRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests against the in-memory SQLite test DB. Outer transaction wraps each
 * test for isolation (matches the v0.2/v0.3 repo-test pattern).
 *
 * Helpers insertUser/insertHousehold/minimalChoreData keep each test focused on
 * its assertion rather than scaffolding.
 */
final class ChoreRepositoryTest extends TestCase
{
    private Connection $db;
    private ChoreRepository $repo;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new ChoreRepository($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_persists_chore_with_household_timezone(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        $id = $this->repo->create($this->minimalChoreData($hid, $uid, [
            'title' => 'Take out bins',
            'points' => 10,
            'assigned_to' => $uid,
        ]));

        self::assertGreaterThan(0, $id);
        $row = $this->db->fetchOne('SELECT * FROM chores WHERE id = :id', ['id' => $id]);
        self::assertSame('Take out bins', $row['title']);
        self::assertSame('Pacific/Auckland', $row['timezone']);
        self::assertSame(10, (int) $row['points']);
        self::assertSame($uid, (int) $row['assigned_to']);
        self::assertNull($row['completed_at']);
    }

    public function test_create_works_under_an_outer_transaction(): void
    {
        // setUp already opened a transaction; create() must not try to commit it.
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        $id = $this->repo->create($this->minimalChoreData($hid, $uid));

        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertNotNull($this->repo->findById($id));
    }

    public function test_create_rejects_invalid_timezone(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        $this->expectException(\InvalidArgumentException::class);
        $this->repo->create($this->minimalChoreData($hid, $uid, ['timezone' => 'Mars/Phobos']));
    }

    public function test_create_truncates_due_seconds_to_minute(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        $id = $this->repo->create($this->minimalChoreData($hid, $uid, [
            'due_at_local' => '2026-07-14 15:30:45',
        ]));

        $row = $this->db->fetchOne('SELECT due_at_local FROM chores WHERE id = :id', ['id' => $id]);
        self::assertSame('2026-07-14 15:30:00', $row['due_at_local']);
    }

    public function test_create_allows_null_due_date(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        $id = $this->repo->create($this->minimalChoreData($hid, $uid, ['due_at_local' => null]));

        $chore = $this->repo->findById($id);
        self::assertNotNull($chore);
        self::assertNull($chore['due_at_local']);
    }

    public function test_create_allows_null_assignee(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        $id = $this->repo->create($this->minimalChoreData($hid, $uid, ['assigned_to' => null]));

        $chore = $this->repo->findById($id);
        self::assertNotNull($chore);
        self::assertNull($chore['assigned_to']);
    }

    public function test_check_constraint_rejects_negative_points(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        // The DB CHECK (points >= 0) is the integrity backstop behind the
        // controller validator. A direct negative insert must raise a catchable
        // PDO error rather than silently storing a negative value.
        $this->expectException(\PDOException::class);
        $this->db->run(
            "INSERT INTO chores (household_id, created_by, title, points, timezone)
             VALUES (:hid, :uid, 'Bad', -5, 'Pacific/Auckland')",
            ['hid' => $hid, 'uid' => $uid],
        );
    }

    public function test_find_by_id_returns_null_for_missing(): void
    {
        self::assertNull($this->repo->findById(999999));
    }

    public function test_list_for_household_orders_open_first_then_due(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        $noDue = $this->repo->create($this->minimalChoreData($hid, $uid, ['title' => 'No due', 'due_at_local' => null]));
        $late = $this->repo->create($this->minimalChoreData($hid, $uid, ['title' => 'Late', 'due_at_local' => '2026-07-20 09:00:00']));
        $early = $this->repo->create($this->minimalChoreData($hid, $uid, ['title' => 'Early', 'due_at_local' => '2026-07-10 09:00:00']));
        $done = $this->repo->create($this->minimalChoreData($hid, $uid, ['title' => 'Done', 'due_at_local' => '2026-07-05 09:00:00']));
        $this->repo->markDone($done, $uid);

        $list = $this->repo->listForHousehold($hid);
        $titles = array_map(fn(array $c): string => $c['title'], $list);

        // Open chores first (early due, then late due, then null-due last), done chore last.
        self::assertSame(['Early', 'Late', 'No due', 'Done'], $titles);
    }

    public function test_update_changes_whitelisted_columns_only(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $uid, ['title' => 'Old', 'points' => 1]));

        $this->repo->update($id, [
            'title' => 'New',
            'points' => 25,
            'household_id' => 99999,   // must be ignored
            'created_by' => 99999,     // must be ignored
        ]);

        $chore = $this->repo->findById($id);
        self::assertSame('New', $chore['title']);
        self::assertSame(25, $chore['points']);
        self::assertSame($hid, $chore['household_id']);
        self::assertSame($uid, $chore['created_by']);
    }

    public function test_mark_done_sets_completed_at_and_by(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $uid));

        $this->repo->markDone($id, $uid);

        $chore = $this->repo->findById($id);
        self::assertTrue($chore['is_done']);
        self::assertNotNull($chore['completed_at']);
        self::assertSame($uid, $chore['completed_by']);
    }

    public function test_mark_done_is_idempotent(): void
    {
        $first = $this->insertUser('a@example.com');
        $second = $this->insertUser('b@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $first));

        $this->repo->markDone($id, $first);
        $after = $this->repo->findById($id);
        $completedAt = $after['completed_at'];

        // A second markDone by a different user must NOT overwrite completer/time.
        $this->repo->markDone($id, $second);
        $again = $this->repo->findById($id);

        self::assertSame($first, $again['completed_by']);
        self::assertSame($completedAt, $again['completed_at']);
    }

    public function test_reopen_clears_completed_fields(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $uid));
        $this->repo->markDone($id, $uid);

        $this->repo->reopen($id);

        $chore = $this->repo->findById($id);
        self::assertFalse($chore['is_done']);
        self::assertNull($chore['completed_at']);
        self::assertNull($chore['completed_by']);
    }

    public function test_points_tally_sums_completed_by_credited_member(): void
    {
        $alice = $this->insertUser('alice@example.com', 'Alice');
        $bob = $this->insertUser('bob@example.com', 'Bob');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $alice, 'owner');
        $this->addMember($hid, $bob, 'member');

        $c1 = $this->repo->create($this->minimalChoreData($hid, $alice, ['points' => 10, 'assigned_to' => $alice]));
        $c2 = $this->repo->create($this->minimalChoreData($hid, $alice, ['points' => 5, 'assigned_to' => $alice]));
        $this->repo->markDone($c1, $alice);
        $this->repo->markDone($c2, $alice);

        $tally = $this->indexTally($this->repo->leaderboardForHousehold($hid, '2000-01-01 00:00:00'));

        self::assertSame(15, $tally[$alice]);
        self::assertSame(0, $tally[$bob]);  // member with no completed chores still listed at 0
    }

    public function test_points_tally_uncredits_after_reopen(): void
    {
        $alice = $this->insertUser('alice@example.com', 'Alice');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $alice, 'owner');
        $id = $this->repo->create($this->minimalChoreData($hid, $alice, ['points' => 10, 'assigned_to' => $alice]));
        $this->repo->markDone($id, $alice);

        self::assertSame(10, $this->indexTally($this->repo->leaderboardForHousehold($hid, '2000-01-01 00:00:00'))[$alice]);

        $this->repo->reopen($id);
        self::assertSame(0, $this->indexTally($this->repo->leaderboardForHousehold($hid, '2000-01-01 00:00:00'))[$alice]);
    }

    public function test_points_tally_credits_completer_when_assignee_removed(): void
    {
        // A chore assigned to Carol but completed by Bob. Carol is then removed
        // from the household. Credit must follow the DOER (Bob, via completed_by),
        // not the now-departed assignee — and Bob's points must show on the board.
        $bob = $this->insertUser('bob@example.com', 'Bob');
        $carol = $this->insertUser('carol@example.com', 'Carol');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $bob, 'owner');
        $this->addMember($hid, $carol, 'member');

        $id = $this->repo->create($this->minimalChoreData($hid, $bob, ['points' => 50, 'assigned_to' => $carol]));
        $this->repo->markDone($id, $bob);

        // Carol leaves the household.
        $this->db->run('DELETE FROM household_members WHERE household_id = :h AND user_id = :u', ['h' => $hid, 'u' => $carol]);

        $tally = $this->indexTally($this->repo->leaderboardForHousehold($hid, '2000-01-01 00:00:00'));
        self::assertSame(50, $tally[$bob]);
        self::assertArrayNotHasKey($carol, $tally);  // Carol no longer on the board
    }

    public function test_completer_account_delete_orphans_the_ledger_points(): void
    {
        // The ledger credits the DOER, frozen at completion. If the doer's ACCOUNT
        // is later deleted, the ledger row's credited_user_id → NULL (SET NULL):
        // the points become unattributed history and drop off everyone's board.
        // (Unlike the old live tally, there is no fall-back to the assignee — the
        // person who actually did it is gone.)
        $alice = $this->insertUser('alice@example.com', 'Alice');
        $temp = $this->insertUser('temp@example.com', 'Temp');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $alice, 'owner');

        $id = $this->repo->create($this->minimalChoreData($hid, $alice, ['points' => 30, 'assigned_to' => $alice]));
        $this->repo->markDone($id, $temp);  // temp is the doer

        $this->db->run('DELETE FROM household_members WHERE user_id = :u', ['u' => $temp]);
        $this->db->run('DELETE FROM users WHERE id = :u', ['u' => $temp]);

        // Alice (the assignee) is NOT credited; the row survives but unattributed.
        $tally = $this->indexTally($this->repo->leaderboardForHousehold($hid, '2000-01-01 00:00:00'));
        self::assertSame(0, $tally[$alice]);
        $orphan = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chore_points_ledger WHERE household_id = :h AND credited_user_id IS NULL AND points = 30',
            ['h' => $hid],
        );
        self::assertSame(1, $orphan);
    }

    public function test_leaderboard_week_points_respects_the_monday_boundary(): void
    {
        $alice = $this->insertUser('alice@example.com', 'Alice');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $alice, 'owner');

        // Two ledger rows: one last week, one this week (UTC instants).
        $weekStart = '2026-06-08 00:00:00';  // a Monday (UTC)
        $this->insertLedgerRow($hid, $alice, 10, '2026-06-05 09:00:00');  // before → all-time only
        $this->insertLedgerRow($hid, $alice, 7, '2026-06-09 09:00:00');   // on/after → this week too

        $row = $this->repo->leaderboardForHousehold($hid, $weekStart)[0];
        self::assertSame(17, $row['total_points']);
        self::assertSame(7, $row['week_points']);
    }

    // --- v0.4.3: leaderboard total_completions + recentCompletionsForHousehold ---

    public function test_leaderboard_returns_total_completions(): void
    {
        $alice = $this->insertUser('alice@example.com', 'Alice');
        $bob = $this->insertUser('bob@example.com', 'Bob');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $alice, 'owner');
        $this->addMember($hid, $bob, 'member');

        $c1 = $this->repo->create($this->minimalChoreData($hid, $alice, ['points' => 10, 'assigned_to' => $alice]));
        $c2 = $this->repo->create($this->minimalChoreData($hid, $alice, ['points' => 5, 'assigned_to' => $alice]));
        $this->repo->markDone($c1, $alice);
        $this->repo->markDone($c2, $alice);

        $board = $this->repo->leaderboardForHousehold($hid, '2000-01-01 00:00:00');
        $byUser = [];
        foreach ($board as $row) {
            $byUser[$row['user_id']] = $row;
        }

        self::assertSame(2, $byUser[$alice]['total_completions']);
        self::assertSame(0, $byUser[$bob]['total_completions']);  // R8: LEFT JOIN regression guard
    }

    public function test_recent_completions_returns_per_user_lists_desc(): void
    {
        $alice = $this->insertUser('alice@example.com', 'Alice');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $alice, 'owner');
        $this->insertLedgerRow($hid, $alice, 5, '2026-06-05 09:00:00');
        $this->insertLedgerRow($hid, $alice, 5, '2026-06-09 09:00:00');
        $this->insertLedgerRow($hid, $alice, 5, '2026-06-07 09:00:00');

        $map = $this->repo->recentCompletionsForHousehold($hid, '2026-06-01 00:00:00');

        self::assertArrayHasKey($alice, $map);
        self::assertSame(
            ['2026-06-09 09:00:00', '2026-06-07 09:00:00', '2026-06-05 09:00:00'],
            $map[$alice],
        );
    }

    public function test_recent_completions_excludes_departed_members(): void
    {
        $owner = $this->insertUser('owner@example.com', 'Owner');
        $carol = $this->insertUser('carol@example.com', 'Carol');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $owner, 'owner');
        $this->addMember($hid, $carol, 'member');
        $this->insertLedgerRow($hid, $owner, 5, '2026-06-09 09:00:00');
        $this->insertLedgerRow($hid, $carol, 5, '2026-06-09 09:00:00');
        // Carol leaves the household (membership only — her ledger row persists).
        $this->db->run('DELETE FROM household_members WHERE household_id = :h AND user_id = :u', ['h' => $hid, 'u' => $carol]);

        $map = $this->repo->recentCompletionsForHousehold($hid, '2026-06-01 00:00:00');

        self::assertArrayHasKey($owner, $map);
        self::assertArrayNotHasKey($carol, $map);
    }

    public function test_household_delete_cascades_to_chores(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $this->repo->create($this->minimalChoreData($hid, $uid));
        $this->repo->create($this->minimalChoreData($hid, $uid));

        $this->db->run('DELETE FROM households WHERE id = :id', ['id' => $hid]);

        $count = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM chores WHERE household_id = :h', ['h' => $hid]);
        self::assertSame(0, $count);
    }

    public function test_assignee_account_delete_sets_assigned_to_null(): void
    {
        $owner = $this->insertUser('owner@example.com');
        $helper = $this->insertUser('helper@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $owner, ['assigned_to' => $helper]));

        // Helper's account is deleted — created_by is owner so SET NULL on the
        // helper's user-id doesn't touch this row's created_by. (Pre-v0.6.12
        // this test relied on the RESTRICT FK protecting owner-as-creator;
        // post-v0.6.12 the FK is SET NULL but the row is created with owner
        // as author, so the helper's delete still doesn't NULL the author.)
        $this->db->run('DELETE FROM household_members WHERE user_id = :u', ['u' => $helper]);
        $this->db->run('DELETE FROM users WHERE id = :u', ['u' => $helper]);

        $chore = $this->repo->findById($id);
        self::assertNull($chore['assigned_to']);
    }

    public function test_delete_removes_chore(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $uid));

        $this->repo->delete($id);

        self::assertNull($this->repo->findById($id));
    }

    // --- v0.5.1 missed-chore counts ---

    public function test_missed_counts_returns_assignee_keyed_map(): void
    {
        $alice = $this->insertUser('a@example.com', 'Alice');
        $bob = $this->insertUser('b@example.com', 'Bob');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $alice, 'owner');
        $this->addMember($hid, $bob, 'member');

        // Alice has 2 chores past due, 1 future, 1 done past due
        $this->repo->create($this->minimalChoreData($hid, $alice, ['assigned_to' => $alice, 'due_at_local' => '2026-05-01 09:00:00']));
        $this->repo->create($this->minimalChoreData($hid, $alice, ['assigned_to' => $alice, 'due_at_local' => '2026-05-15 09:00:00']));
        $this->repo->create($this->minimalChoreData($hid, $alice, ['assigned_to' => $alice, 'due_at_local' => '2027-05-15 09:00:00']));
        $doneChoreId = $this->repo->create($this->minimalChoreData($hid, $alice, ['assigned_to' => $alice, 'due_at_local' => '2026-05-10 09:00:00']));
        $this->repo->markDone($doneChoreId, $alice);

        // Bob has 1 chore past due
        $this->repo->create($this->minimalChoreData($hid, $bob, ['assigned_to' => $bob, 'due_at_local' => '2026-05-15 09:00:00']));

        // Unassigned chore past due — does NOT count toward anyone.
        $this->repo->create($this->minimalChoreData($hid, $alice, ['assigned_to' => null, 'due_at_local' => '2026-05-15 09:00:00']));

        $missed = $this->repo->missedCountsForHousehold($hid, '2026-06-01 00:00:00');

        self::assertSame(2, $missed[$alice]);
        self::assertSame(1, $missed[$bob]);
    }

    public function test_missed_counts_drops_chores_for_kicked_members(): void
    {
        // Self-heal: a chore assigned_to a user who's no longer in the
        // household must not appear in the missed tally. Matches the INNER JOIN
        // in the leaderboard query.
        $alice = $this->insertUser('a@example.com', 'Alice');
        $kicked = $this->insertUser('k@example.com', 'Kicked');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $alice, 'owner');
        $this->addMember($hid, $kicked, 'member');

        $this->repo->create($this->minimalChoreData($hid, $alice, ['assigned_to' => $kicked, 'due_at_local' => '2026-05-15 09:00:00']));
        $this->repo->create($this->minimalChoreData($hid, $alice, ['assigned_to' => $alice, 'due_at_local' => '2026-05-15 09:00:00']));

        // Kicked is removed mid-flight.
        $this->db->run(
            'DELETE FROM household_members WHERE household_id = :h AND user_id = :u',
            ['h' => $hid, 'u' => $kicked],
        );

        $missed = $this->repo->missedCountsForHousehold($hid, '2026-06-01 00:00:00');

        self::assertSame([$alice => 1], $missed);
        self::assertArrayNotHasKey($kicked, $missed);
    }

    public function test_missed_counts_returns_empty_when_no_chores_past_due(): void
    {
        $alice = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $this->addMember($hid, $alice, 'owner');
        // Future-only assignment.
        $this->repo->create($this->minimalChoreData($hid, $alice, ['assigned_to' => $alice, 'due_at_local' => '2099-01-01 09:00:00']));

        self::assertSame([], $this->repo->missedCountsForHousehold($hid, '2026-06-01 00:00:00'));
    }

    // --- v0.4.2 points ledger ---

    public function test_mark_done_writes_one_ledger_row_crediting_the_doer(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $uid, ['points' => 10, 'assigned_to' => $uid]));

        $transitioned = $this->repo->markDone($id, $uid);

        self::assertTrue($transitioned);
        $rows = $this->ledgerRows($id);
        self::assertCount(1, $rows);
        self::assertSame($uid, (int) $rows[0]['credited_user_id']);
        self::assertSame(10, (int) $rows[0]['points']);
        self::assertSame($hid, (int) $rows[0]['household_id']);
    }

    public function test_double_complete_writes_exactly_one_ledger_row(): void
    {
        $first = $this->insertUser('a@example.com');
        $second = $this->insertUser('b@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $first, ['points' => 10]));

        self::assertTrue($this->repo->markDone($id, $first));
        self::assertFalse($this->repo->markDone($id, $second));  // no-op, already done

        $rows = $this->ledgerRows($id);
        self::assertCount(1, $rows);
        self::assertSame($first, (int) $rows[0]['credited_user_id']);  // first doer keeps the credit
    }

    public function test_reopen_removes_the_ledger_row(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $uid, ['points' => 10]));
        $this->repo->markDone($id, $uid);

        $this->repo->reopen($id);

        self::assertCount(0, $this->ledgerRows($id));
    }

    public function test_reopen_then_recomplete_leaves_one_row_at_current_points(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $uid, ['points' => 10]));
        $this->repo->markDone($id, $uid);
        $this->repo->reopen($id);
        // Points edited while open, then re-completed.
        $this->repo->update($id, ['points' => 25]);
        $this->repo->markDone($id, $uid);

        $rows = $this->ledgerRows($id);
        self::assertCount(1, $rows);
        self::assertSame(25, (int) $rows[0]['points']);  // credits the points at re-completion
    }

    public function test_editing_points_on_a_completed_chore_leaves_the_ledger_frozen(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $uid, ['points' => 10]));
        $this->repo->markDone($id, $uid);

        $this->repo->update($id, ['points' => 999]);  // edit AFTER completion

        $rows = $this->ledgerRows($id);
        self::assertCount(1, $rows);
        self::assertSame(10, (int) $rows[0]['points']);  // frozen at completion
    }

    public function test_deleting_a_completed_chore_keeps_the_ledger_row(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalChoreData($hid, $uid, ['points' => 10]));
        $this->repo->markDone($id, $uid);

        $this->repo->delete($id);

        // chore_id SET NULL, but the points-history row survives.
        $surviving = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chore_points_ledger WHERE household_id = :h AND credited_user_id = :u AND points = 10',
            ['h' => $hid, 'u' => $uid],
        );
        self::assertSame(1, $surviving);
    }

    // --- helpers ---

    /** @return list<array<string, mixed>> */
    private function ledgerRows(int $choreId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM chore_points_ledger WHERE chore_id = :id',
            ['id' => $choreId],
        );
    }

    private function insertUser(string $email, string $name = 'Test'): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name) VALUES (:email, :hash, :name) RETURNING id',
            ['email' => $email, 'hash' => 'unused', 'name' => $name],
        );
    }

    private function insertHousehold(string $name): int
    {
        return (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES (:name, :code, 'Pacific/Auckland') RETURNING id",
            ['name' => $name, 'code' => substr(bin2hex(random_bytes(4)), 0, 8)],
        );
    }

    private function addMember(int $hid, int $uid, string $role): void
    {
        $this->db->run(
            'INSERT INTO household_members (household_id, user_id, role) VALUES (:h, :u, :r)',
            ['h' => $hid, 'u' => $uid, 'r' => $role],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function minimalChoreData(int $hid, int $uid, array $overrides = []): array
    {
        return $overrides + [
            'household_id' => $hid,
            'created_by' => $uid,
            'title' => 'Test chore',
            'description' => '',
            'points' => 0,
            'due_at_local' => '2026-07-14 09:00:00',
            'assigned_to' => null,
            'timezone' => 'Pacific/Auckland',
        ];
    }

    /**
     * @param list<array{user_id: int, display_name: string, email: string, total_points: int, week_points?: int}> $tally
     * @return array<int, int>
     */
    private function indexTally(array $tally): array
    {
        $out = [];
        foreach ($tally as $row) {
            $out[$row['user_id']] = $row['total_points'];
        }
        return $out;
    }

    private function insertLedgerRow(int $hid, int $userId, int $points, string $completedAtUtc): void
    {
        $this->db->run(
            'INSERT INTO chore_points_ledger (household_id, chore_id, credited_user_id, points, completed_at)
             VALUES (:h, NULL, :u, :p, :t)',
            ['h' => $hid, 'u' => $userId, 'p' => $points, 't' => $completedAtUtc],
        );
    }
}
