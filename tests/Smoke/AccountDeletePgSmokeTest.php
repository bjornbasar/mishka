<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Auth\MishkaUserRepository;
use App\Household\HouseholdRepository;
use Karhu\Auth\PasswordHasher;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.12 — PG-only smoke for the account-delete flow.
 *
 * Verifies behaviour the SQLite pass can't catch with the same precision:
 *   - SET NULL migration landed for events.created_by (pg_constraint inspect)
 *   - SET NULL migration landed for chores.created_by
 *   - SET NULL migration landed for chore_schedules.created_by
 *   - Full cascade chain on user delete fires cleanly inside one txn
 *   - UNIQUE constraint on users.email survives a delete-then-re-register
 *     cycle (no orphaned UNIQUE rows)
 *
 * SKIPS unless DB_DSN=pgsql:. Uses explicit beginTransaction + rollBack so
 * the smoke-test rows never leak into shareddb.
 */
final class AccountDeletePgSmokeTest extends TestCase
{
    private Connection $db;

    private MishkaUserRepository $users;

    private HouseholdRepository $households;

    private PasswordHasher $hasher;

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
        $this->users = new MishkaUserRepository($this->db);
        $this->households = new HouseholdRepository($this->db);
        $this->hasher = new PasswordHasher();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_set_null_migration_landed_for_events_created_by(): void
    {
        // pg_constraint inspection: the constraint must reference users(id)
        // with confdeltype = 'n' (SET NULL) — was 'r' (RESTRICT) pre-v0.6.12.
        $row = $this->db->fetchOne(
            "SELECT confdeltype FROM pg_constraint
             WHERE conname = 'events_created_by_fkey'",
        );
        self::assertNotNull($row);
        self::assertSame('n', $row['confdeltype'], 'events.created_by FK must be ON DELETE SET NULL');
    }

    public function test_set_null_migration_landed_for_chores_created_by(): void
    {
        $row = $this->db->fetchOne(
            "SELECT confdeltype FROM pg_constraint
             WHERE conname = 'chores_created_by_fkey'",
        );
        self::assertNotNull($row);
        self::assertSame('n', $row['confdeltype'], 'chores.created_by FK must be ON DELETE SET NULL');
    }

    public function test_set_null_migration_landed_for_chore_schedules_created_by(): void
    {
        $row = $this->db->fetchOne(
            "SELECT confdeltype FROM pg_constraint
             WHERE conname = 'chore_schedules_created_by_fkey'",
        );
        self::assertNotNull($row);
        self::assertSame('n', $row['confdeltype'], 'chore_schedules.created_by FK must be ON DELETE SET NULL');
    }

    public function test_full_cascade_chain_on_user_delete_runs_in_one_txn(): void
    {
        // Insert a user with rows in several CASCADE tables + one row in each
        // SET NULL target. DELETE the user; verify all cascades fired and all
        // SET NULL columns were nulled.
        $uid = $this->insertUser('cascade@example.com');
        $hid = $this->households->createForOwner('Cascade Den', $uid);

        $eventId = (int) $this->db->fetchScalar(
            "INSERT INTO events (household_id, created_by, title, starts_at_local, ends_at_local, timezone)
             VALUES (:h, :a, 'E', '2026-01-01 10:00:00', '2026-01-01 11:00:00', 'UTC')
             RETURNING id",
            ['h' => $hid, 'a' => $uid],
        );
        $choreId = (int) $this->db->fetchScalar(
            "INSERT INTO chores (household_id, created_by, title, timezone)
             VALUES (:h, :a, 'C', 'UTC') RETURNING id",
            ['h' => $hid, 'a' => $uid],
        );

        // Sanity: pre-delete state.
        self::assertNotNull($this->users->findById($uid));
        self::assertSame(1, (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM household_members WHERE user_id = :u',
            ['u' => $uid],
        ));

        // The destructive DELETE.
        self::assertTrue($this->users->delete($uid));

        // Post-delete: user gone; household_members cascaded; events.created_by
        // is NULL but the event ROW survives (SET NULL, not CASCADE).
        self::assertNull($this->users->findById($uid));
        self::assertSame(0, (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM household_members WHERE user_id = :u',
            ['u' => $uid],
        ));
        self::assertNull($this->db->fetchScalar(
            'SELECT created_by FROM events WHERE id = :id',
            ['id' => $eventId],
        ));
        self::assertNull($this->db->fetchScalar(
            'SELECT created_by FROM chores WHERE id = :id',
            ['id' => $choreId],
        ));
    }

    public function test_unique_email_still_enforced_after_delete_re_register_cycle(): void
    {
        // Delete a user; immediately re-create another user with the SAME
        // email. Should succeed (UNIQUE doesn't carry over from a deleted row),
        // AND a second insert with the same email should fail (UNIQUE still
        // enforced for the new row).
        $email = 'recycle-' . bin2hex(random_bytes(3)) . '@example.com';
        $uid1 = $this->users->create($email, $this->hasher->hash('x'), 'A');
        self::assertTrue($this->users->delete($uid1));

        // Re-register with the same email — UNIQUE allows it (no orphan).
        $uid2 = $this->users->create($email, $this->hasher->hash('y'), 'A2');
        self::assertNotSame($uid1, $uid2);

        // Inserting a SECOND row with the same email must still raise UNIQUE.
        try {
            $this->users->create($email, $this->hasher->hash('z'), 'A3');
            self::fail('expected UNIQUE violation on duplicate email');
        } catch (\PDOException $e) {
            self::assertSame('23505', $e->getCode());
        }
    }

    private function insertUser(string $email): int
    {
        $suffix = bin2hex(random_bytes(4));
        return $this->users->create($suffix . '-' . $email, $this->hasher->hash('x'), 'Smoke');
    }
}
