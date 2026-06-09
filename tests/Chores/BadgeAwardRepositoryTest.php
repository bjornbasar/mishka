<?php

declare(strict_types=1);

namespace App\Tests\Chores;

use App\Auth\MishkaUserRepository;
use App\Chores\BadgeAwardRepository;
use App\Household\HouseholdRepository;
use Karhu\Auth\PasswordHasher;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.13 — BadgeAwardRepository unit tests.
 *
 * Verifies the persistent badge-award contract: idempotent grant (UNIQUE
 * + ON CONFLICT DO NOTHING / INSERT OR IGNORE), per-user listings, bulk
 * household fetch (with departed-member filtering), per-member counts,
 * and FK ON DELETE SET NULL behaviour on user delete.
 */
final class BadgeAwardRepositoryTest extends TestCase
{
    private Connection $db;

    private BadgeAwardRepository $repo;

    private HouseholdRepository $households;

    private MishkaUserRepository $users;

    private PasswordHasher $hasher;

    private int $uid;

    private int $hid;

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();
        $this->repo = new BadgeAwardRepository($this->db);
        $this->households = new HouseholdRepository($this->db);
        $this->users = new MishkaUserRepository($this->db);
        $this->hasher = new PasswordHasher();

        $this->uid = $this->users->create(
            'u-' . bin2hex(random_bytes(3)) . '@example.com',
            $this->hasher->hash('x'),
            'Tester',
        );
        $this->hid = $this->households->createForOwner('Den', $this->uid);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_grant_writes_row_on_first_earn_returns_true(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $ok = $this->repo->grant($this->hid, $this->uid, 'first_chore', $now);

        self::assertTrue($ok);
        $codes = $this->repo->listCodesForUser($this->hid, $this->uid);
        self::assertSame(['first_chore'], $codes);
    }

    public function test_grant_returns_false_on_duplicate(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->repo->grant($this->hid, $this->uid, 'first_chore', $now);

        // Second attempt with a different earned_at — UNIQUE blocks; earned_at
        // on the existing row is preserved (NOT updated to the newer value).
        $later = gmdate('Y-m-d H:i:s', time() + 3600);
        $ok = $this->repo->grant($this->hid, $this->uid, 'first_chore', $later);

        self::assertFalse($ok);
        $rows = $this->repo->listForUser($this->hid, $this->uid);
        self::assertCount(1, $rows);
        // earned_at preserved from the first call.
        self::assertSame($now, $rows[0]['earned_at']);
    }

    public function test_listForUser_returns_earned_badges_sorted_earned_at_desc(): void
    {
        $earliest = gmdate('Y-m-d H:i:s', time() - 7200);
        $middle = gmdate('Y-m-d H:i:s', time() - 3600);
        $latest = gmdate('Y-m-d H:i:s');
        $this->repo->grant($this->hid, $this->uid, 'first_chore', $earliest);
        $this->repo->grant($this->hid, $this->uid, 'ten_chores', $middle);
        $this->repo->grant($this->hid, $this->uid, 'centurion', $latest);

        $rows = $this->repo->listForUser($this->hid, $this->uid);

        self::assertCount(3, $rows);
        self::assertSame('centurion', $rows[0]['badge_code']);
        self::assertSame('ten_chores', $rows[1]['badge_code']);
        self::assertSame('first_chore', $rows[2]['badge_code']);
    }

    public function test_listByUserForHousehold_returns_map_keyed_by_user_id(): void
    {
        // Two members + a third who LEAVES the household before assertion.
        // The departed member's badge_awards row stays in the table but is
        // hidden by the INNER JOIN to household_members.
        $other = $this->users->create('other@example.com', $this->hasher->hash('x'), 'Other');
        $departed = $this->users->create('gone@example.com', $this->hasher->hash('x'), 'Gone');
        $this->households->addMember($this->hid, $other);
        $this->households->addMember($this->hid, $departed);

        $now = gmdate('Y-m-d H:i:s');
        $this->repo->grant($this->hid, $this->uid, 'first_chore', $now);
        $this->repo->grant($this->hid, $other, 'first_chore', $now);
        $this->repo->grant($this->hid, $departed, 'first_chore', $now);

        // Departed leaves AFTER earning the badge.
        $this->db->run(
            'DELETE FROM household_members WHERE household_id = :h AND user_id = :u',
            ['h' => $this->hid, 'u' => $departed],
        );

        $map = $this->repo->listByUserForHousehold($this->hid);

        self::assertArrayHasKey($this->uid, $map);
        self::assertArrayHasKey($other, $map);
        // Departed member's badge is HIDDEN by the INNER JOIN filter.
        self::assertArrayNotHasKey($departed, $map);
        self::assertSame(['first_chore'], $map[$this->uid]);
        self::assertSame(['first_chore'], $map[$other]);
    }

    public function test_listCodesForUser_filters_by_household_scope(): void
    {
        // User has badges in TWO different households — listCodes for one
        // household must not leak the other's earnings.
        $otherHid = $this->households->createForOwner('Second Den', $this->uid);
        $now = gmdate('Y-m-d H:i:s');
        $this->repo->grant($this->hid, $this->uid, 'first_chore', $now);
        $this->repo->grant($otherHid, $this->uid, 'ten_chores', $now);

        $denCodes = $this->repo->listCodesForUser($this->hid, $this->uid);
        $secondCodes = $this->repo->listCodesForUser($otherHid, $this->uid);

        self::assertSame(['first_chore'], $denCodes);
        self::assertSame(['ten_chores'], $secondCodes);
    }

    public function test_user_delete_sets_user_id_to_null_keeps_row(): void
    {
        // v0.6.12-style FK contract: user delete SET NULLs user_id but the
        // badge_awards row survives so the household keeps the historical
        // earn record (decision #53 analogue).
        $now = gmdate('Y-m-d H:i:s');
        $this->repo->grant($this->hid, $this->uid, 'first_chore', $now);

        // Before delete: row exists with user_id non-null.
        $before = $this->db->fetchOne(
            'SELECT user_id FROM badge_awards WHERE household_id = :h AND badge_code = :c',
            ['h' => $this->hid, 'c' => 'first_chore'],
        );
        self::assertNotNull($before);
        self::assertSame((string) $this->uid, (string) $before['user_id']);

        // Delete the user.
        self::assertTrue($this->users->delete($this->uid));

        // Row survives with user_id = NULL.
        $after = $this->db->fetchOne(
            'SELECT user_id FROM badge_awards WHERE household_id = :h AND badge_code = :c',
            ['h' => $this->hid, 'c' => 'first_chore'],
        );
        self::assertNotNull($after);
        self::assertNull($after['user_id']);
    }
}
