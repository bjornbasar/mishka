<?php

declare(strict_types=1);

namespace App\Tests\Chores;

use App\Chores\ChoreScheduleRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the recurring-chore template store. Outer transaction wraps
 * each test for isolation (matches the v0.3/v0.4 repo-test pattern).
 */
final class ChoreScheduleRepositoryTest extends TestCase
{
    private Connection $db;
    private ChoreScheduleRepository $repo;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new ChoreScheduleRepository($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_create_persists_with_tz_and_defaults(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        $id = $this->repo->create($this->minimalScheduleData($hid, $uid, [
            'title' => 'Take out bins',
            'points' => 10,
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]));

        $row = $this->repo->findById($id);
        self::assertNotNull($row);
        self::assertSame('Take out bins', $row['title']);
        self::assertSame('Pacific/Auckland', $row['timezone']);
        self::assertSame(10, $row['points']);
        self::assertSame('rotate', $row['assignment_mode']);   // default
        self::assertNull($row['last_assigned_user_id']);
        self::assertNull($row['generated_through']);
    }

    public function test_create_works_under_an_outer_transaction(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        $id = $this->repo->create($this->minimalScheduleData($hid, $uid));

        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertNotNull($this->repo->findById($id));
    }

    public function test_create_rejects_invalid_timezone(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');

        $this->expectException(\InvalidArgumentException::class);
        $this->repo->create($this->minimalScheduleData($hid, $uid, ['timezone' => 'Mars/Phobos']));
    }

    public function test_find_by_id_returns_null_for_missing(): void
    {
        self::assertNull($this->repo->findById(999999));
    }

    public function test_list_for_household(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $other = $this->insertHousehold('Other');
        $this->repo->create($this->minimalScheduleData($hid, $uid, ['title' => 'Mine']));
        $this->repo->create($this->minimalScheduleData($other, $uid, ['title' => 'Theirs']));

        $list = $this->repo->listForHousehold($hid);
        self::assertCount(1, $list);
        self::assertSame('Mine', $list[0]['title']);
    }

    public function test_update_changes_whitelisted_columns_only(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalScheduleData($hid, $uid, ['title' => 'Old']));

        $this->repo->update($id, [
            'title' => 'New',
            'points' => 7,
            'assignment_mode' => 'fixed',
            'fixed_user_id' => $uid,
            'household_id' => 99999,  // ignored
            'created_by' => 99999,    // ignored
        ]);

        $row = $this->repo->findById($id);
        self::assertSame('New', $row['title']);
        self::assertSame(7, $row['points']);
        self::assertSame('fixed', $row['assignment_mode']);
        self::assertSame($uid, $row['fixed_user_id']);
        self::assertSame($hid, $row['household_id']);
        self::assertSame($uid, $row['created_by']);
    }

    public function test_set_rotation_persists_last_assigned(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalScheduleData($hid, $uid));

        $this->repo->setRotation($id, $uid);
        self::assertSame($uid, $this->repo->findById($id)['last_assigned_user_id']);

        $this->repo->setRotation($id, null);
        self::assertNull($this->repo->findById($id)['last_assigned_user_id']);
    }

    public function test_set_generated_through(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalScheduleData($hid, $uid));

        $this->repo->setGeneratedThrough($id, '2026-06-30 09:00:00');
        self::assertSame('2026-06-30 09:00:00', $this->repo->findById($id)['generated_through']);
    }

    public function test_delete_removes_schedule(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalScheduleData($hid, $uid));

        $this->repo->delete($id);
        self::assertNull($this->repo->findById($id));
    }

    public function test_household_delete_cascades_to_schedules(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalScheduleData($hid, $uid));

        $this->db->run('DELETE FROM households WHERE id = :id', ['id' => $hid]);
        self::assertNull($this->repo->findById($id));
    }

    public function test_fixed_user_account_delete_sets_fixed_user_null(): void
    {
        $owner = $this->insertUser('owner@example.com');
        $pinned = $this->insertUser('pinned@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalScheduleData($hid, $owner, [
            'assignment_mode' => 'fixed',
            'fixed_user_id' => $pinned,
            'last_assigned_user_id' => $pinned,
        ]));

        $this->db->run('DELETE FROM users WHERE id = :u', ['u' => $pinned]);

        $row = $this->repo->findById($id);
        self::assertNull($row['fixed_user_id']);
        self::assertNull($row['last_assigned_user_id']);
    }

    // --- v0.4.2 pause/resume + participant pools ---

    public function test_pause_resume_round_trip_and_idempotent(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalScheduleData($hid, $uid));

        self::assertFalse($this->repo->isPaused($id));
        $this->repo->pause($id);
        self::assertTrue($this->repo->isPaused($id));
        $this->repo->pause($id);  // idempotent
        self::assertTrue($this->repo->isPaused($id));
        $this->repo->resume($id);
        self::assertFalse($this->repo->isPaused($id));
    }

    public function test_list_paused_ids_scoped_to_household(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $other = $this->insertHousehold('Other');
        $mine = $this->repo->create($this->minimalScheduleData($hid, $uid));
        $theirs = $this->repo->create($this->minimalScheduleData($other, $uid));
        $this->repo->pause($mine);
        $this->repo->pause($theirs);

        self::assertSame([$mine], $this->repo->listPausedIds($hid));
    }

    public function test_pause_cascades_on_schedule_delete(): void
    {
        $uid = $this->insertUser('a@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalScheduleData($hid, $uid));
        $this->repo->pause($id);

        $this->repo->delete($id);

        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chore_schedule_pauses WHERE schedule_id = :id',
            ['id' => $id],
        );
        self::assertSame(0, $count);
    }

    public function test_set_participants_replace_semantics_and_clear(): void
    {
        $uid = $this->insertUser('a@example.com');
        $b = $this->insertUser('b@example.com');
        $c = $this->insertUser('c@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalScheduleData($hid, $uid));

        $this->repo->setParticipants($id, [$uid, $b]);
        self::assertEqualsCanonicalizing([$uid, $b], $this->repo->listParticipantIds($id));

        $this->repo->setParticipants($id, [$c]);  // replace, not append
        self::assertSame([$c], $this->repo->listParticipantIds($id));

        $this->repo->setParticipants($id, []);  // clear
        self::assertSame([], $this->repo->listParticipantIds($id));
    }

    public function test_participants_cascade_on_user_delete(): void
    {
        $owner = $this->insertUser('owner@example.com');
        $helper = $this->insertUser('helper@example.com');
        $hid = $this->insertHousehold('Den');
        $id = $this->repo->create($this->minimalScheduleData($hid, $owner));
        $this->repo->setParticipants($id, [$owner, $helper]);

        $this->db->run('DELETE FROM household_members WHERE user_id = :u', ['u' => $helper]);
        $this->db->run('DELETE FROM users WHERE id = :u', ['u' => $helper]);

        self::assertSame([$owner], $this->repo->listParticipantIds($id));
    }

    // --- helpers ---

    private function insertUser(string $email): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO users (email, password_hash, display_name) VALUES (:e, :h, :n) RETURNING id',
            ['e' => $email, 'h' => 'unused', 'n' => 'Test'],
        );
    }

    private function insertHousehold(string $name): int
    {
        return (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES (:n, :c, 'Pacific/Auckland') RETURNING id",
            ['n' => $name, 'c' => substr(bin2hex(random_bytes(4)), 0, 8)],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function minimalScheduleData(int $hid, int $uid, array $overrides = []): array
    {
        return $overrides + [
            'household_id' => $hid,
            'created_by' => $uid,
            'title' => 'Test schedule',
            'description' => '',
            'points' => 0,
            'rrule' => 'FREQ=DAILY',
            'anchor_at_local' => '2026-06-01 09:00:00',
            'timezone' => 'Pacific/Auckland',
            'assignment_mode' => 'rotate',
            'fixed_user_id' => null,
            'last_assigned_user_id' => null,
        ];
    }
}
