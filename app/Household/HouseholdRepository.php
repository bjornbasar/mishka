<?php

declare(strict_types=1);

namespace App\Household;

use Karhu\Db\Connection;

/**
 * Households + N:M membership.
 *
 * Excludes the sentinel user (id=0) from every household query via
 * `AND user_id > 0` — mirrors the MishkaUserRepository v0.1 pattern so
 * the sentinel never accidentally surfaces in member rosters.
 *
 * All write methods (create, add/remove member, rename) work under either
 * a caller-owned transaction (test harness wraps each test) or no
 * transaction (production). See createForOwner for the guard pattern.
 */
final class HouseholdRepository
{
    /**
     * Restricted alphabet for join codes — excludes lookalikes (I/O/L/0/1)
     * so a code dictated over the phone can't be mistyped.
     */
    private const JOIN_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const JOIN_CODE_LENGTH = 8;
    private const JOIN_CODE_MAX_ATTEMPTS = 5;

    /**
     * Engine-specific row-lock suffix for SELECT statements that must hold
     * locks until the surrounding transaction commits.
     *
     * PG: " FOR UPDATE" — locks the matched rows against concurrent UPDATE/
     * DELETE under the default READ COMMITTED isolation level (round-4 BL-3
     * needs this for the owner-transfer race).
     *
     * SQLite: "" — single-writer engine; once a transaction begins WRITING
     * (the subsequent UPDATE in transferOwnership), no other writer can run
     * until commit, so the lock is implicit. SQLite also REJECTS the
     * `FOR UPDATE` keyword as a syntax error, so it must be omitted.
     */
    private string $forUpdateSuffix;

    public function __construct(private readonly Connection $db)
    {
        $driver = (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->forUpdateSuffix = $driver === 'pgsql' ? ' FOR UPDATE' : '';
    }

    /**
     * Create a household and add the creator as 'owner' atomically.
     *
     * Race-free because both INSERTs happen in a single transaction with no
     * external read between them — there's no TOCTOU window for a second
     * registrant to claim ownership.
     *
     * Nested-transaction guard: PDO doesn't support nested BEGIN; the test
     * harness wraps each test in an outer transaction, so this method must
     * only begin/commit/rollback its own transaction when no outer one is
     * already active. Mirrors MishkaUserRepository::create().
     */
    public function createForOwner(string $name, int $ownerUserId, string $timezone = 'Pacific/Auckland'): int
    {
        $pdo = $this->db->pdo();
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            $joinCode = $this->generateUniqueJoinCode();

            $hid = (int) $this->db->fetchScalar(
                'INSERT INTO households (name, join_code, timezone)
                 VALUES (:name, :code, :tz) RETURNING id',
                ['name' => $name, 'code' => $joinCode, 'tz' => $timezone],
            );

            $this->db->run(
                'INSERT INTO household_members (household_id, user_id, role)
                 VALUES (:hid, :uid, :role)',
                ['hid' => $hid, 'uid' => $ownerUserId, 'role' => 'owner'],
            );

            if ($transactionStarted) {
                $pdo->commit();
            }
            return $hid;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Add a user to an existing household. Always 'member' role (never 'owner'). */
    public function addMember(int $householdId, int $userId): void
    {
        $this->db->run(
            'INSERT INTO household_members (household_id, user_id, role)
             VALUES (:hid, :uid, :role)',
            ['hid' => $householdId, 'uid' => $userId, 'role' => 'member'],
        );
    }

    /**
     * Remove a user from a household.
     *
     * Rejects removing an 'owner' — v0.2 has exactly one owner per household
     * (set at create-time, never transferred). Removing them would orphan
     * the household; transfer/delete semantics come in v0.3.
     */
    public function removeMember(int $householdId, int $userId): void
    {
        $row = $this->db->fetchOne(
            'SELECT role FROM household_members WHERE household_id = :hid AND user_id = :uid',
            ['hid' => $householdId, 'uid' => $userId],
        );
        if ($row === null) {
            throw new \RuntimeException('User is not a member of this household.');
        }
        if ($row['role'] === 'owner') {
            throw new \RuntimeException('Cannot remove the owner of a household.');
        }

        $this->db->run(
            'DELETE FROM household_members WHERE household_id = :hid AND user_id = :uid',
            ['hid' => $householdId, 'uid' => $userId],
        );
    }

    /** @return array{id: int, name: string, join_code: string, timezone: string, created_at: string}|null */
    public function findById(int $householdId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, name, join_code, timezone, created_at
             FROM households WHERE id = :id',
            ['id' => $householdId],
        );
        return $row === null ? null : [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'join_code' => (string) $row['join_code'],
            'timezone' => (string) $row['timezone'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * Look up by join code. Case-insensitive, trimmed.
     * @return array{id: int, name: string, join_code: string, timezone: string, created_at: string}|null
     */
    public function findByJoinCode(string $code): ?array
    {
        $normalised = strtoupper(trim($code));
        $row = $this->db->fetchOne(
            'SELECT id, name, join_code, timezone, created_at
             FROM households WHERE join_code = :code',
            ['code' => $normalised],
        );
        return $row === null ? null : [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'join_code' => (string) $row['join_code'],
            'timezone' => (string) $row['timezone'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function rename(int $householdId, string $name): void
    {
        $this->db->run(
            'UPDATE households SET name = :name WHERE id = :id',
            ['id' => $householdId, 'name' => $name],
        );
    }

    /**
     * All households this user belongs to, with their per-household role.
     * Excludes the sentinel user.
     *
     * @return list<array{id: int, name: string, role: string, joined_at: string}>
     */
    public function listForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT h.id, h.name, m.role, m.joined_at
             FROM household_members m
             JOIN households h ON h.id = m.household_id
             WHERE m.user_id = :uid
             ORDER BY m.joined_at ASC',
            ['uid' => $userId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'role' => (string) $row['role'],
                'joined_at' => (string) $row['joined_at'],
            ];
        }
        return $out;
    }

    /**
     * Members of a household with their email + display name. Excludes the sentinel.
     *
     * @return list<array{user_id: int, email: string, display_name: string, role: string, joined_at: string}>
     */
    public function listMembers(int $householdId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT u.id AS user_id, u.email, u.display_name, m.role, m.joined_at
             FROM household_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.household_id = :hid AND u.id > 0
             ORDER BY m.joined_at ASC',
            ['hid' => $householdId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'user_id' => (int) $row['user_id'],
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
                'role' => (string) $row['role'],
                'joined_at' => (string) $row['joined_at'],
            ];
        }
        return $out;
    }

    public function isMember(int $userId, int $householdId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $hit = $this->db->fetchScalar(
            'SELECT 1 FROM household_members
             WHERE user_id = :uid AND household_id = :hid',
            ['uid' => $userId, 'hid' => $householdId],
        );
        return $hit !== false && $hit !== null;
    }

    public function isOwner(int $userId, int $householdId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $hit = $this->db->fetchScalar(
            "SELECT 1 FROM household_members
             WHERE user_id = :uid AND household_id = :hid AND role = 'owner'",
            ['uid' => $userId, 'hid' => $householdId],
        );
        return $hit !== false && $hit !== null;
    }

    // ============================================================
    // v0.5.0 extensions — household lifecycle
    // ============================================================

    /**
     * Rotate the household's join code. The old code stops working immediately
     * (lookups go by `WHERE join_code = X`, which the UPDATE invalidates).
     *
     * Reuses the same generateUniqueJoinCode() retry loop as createForOwner.
     * Returns the new code so the caller can echo it back to the owner.
     */
    public function regenerateJoinCode(int $householdId): string
    {
        $newCode = $this->generateUniqueJoinCode();
        $this->db->run(
            'UPDATE households SET join_code = :code WHERE id = :id',
            ['code' => $newCode, 'id' => $householdId],
        );
        return $newCode;
    }

    /**
     * Atomic owner-transfer. Promotes $newOwner to 'owner' and demotes $oldOwner
     * to 'member' inside one transaction.
     *
     * B5: both UPDATEs are guarded (`WHERE role='member'` / `WHERE role='owner'`)
     * and the rowCount must be 1 each — if either matches 0 rows the txn rolls
     * back. Two concurrent transfers can't both succeed (the second one's
     * guarded UPDATE matches 0 rows once the first commits).
     *
     * BL-3 (round 4): under PG READ COMMITTED, the two UPDATEs alone are not
     * enough — the validate-target-is-member check is unlocked, so two
     * concurrent transfers can both pass validation before either commits.
     * Fix: `SELECT … FOR UPDATE` locks the two relevant rows BEFORE the
     * UPDATEs, serialising the operation. SQLite is single-writer so the
     * suffix is empty (set at ctor time); the locking is implicit there.
     */
    public function transferOwnership(int $householdId, int $oldOwnerUserId, int $newOwnerUserId): void
    {
        if ($oldOwnerUserId === $newOwnerUserId) {
            throw new \RuntimeException('Cannot transfer ownership to the current owner.');
        }

        $pdo = $this->db->pdo();
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            // Lock the two member rows up-front (PG). Re-verify both roles
            // INSIDE the lock so a concurrent rename/transfer can't slip in.
            $locked = $this->db->fetchAll(
                "SELECT user_id, role FROM household_members
                 WHERE household_id = :hid AND user_id IN (:old, :new){$this->forUpdateSuffix}",
                ['hid' => $householdId, 'old' => $oldOwnerUserId, 'new' => $newOwnerUserId],
            );
            $rolesByUser = [];
            foreach ($locked as $row) {
                $rolesByUser[(int) $row['user_id']] = (string) $row['role'];
            }
            if (($rolesByUser[$oldOwnerUserId] ?? null) !== 'owner') {
                throw new \RuntimeException('Caller is not the current owner of this household.');
            }
            if (($rolesByUser[$newOwnerUserId] ?? null) !== 'member') {
                throw new \RuntimeException('Target user is not a non-owner member of this household.');
            }

            // Promote first, demote second. The two-owner intermediate state is
            // degenerate-OK (transient inside the txn); zero-owner would be
            // degenerate-bad (no owner = no one can transfer back).
            $promoted = $this->db->run(
                "UPDATE household_members SET role = 'owner'
                 WHERE household_id = :hid AND user_id = :uid AND role = 'member'",
                ['hid' => $householdId, 'uid' => $newOwnerUserId],
            );
            if ($promoted !== 1) {
                throw new \RuntimeException('Failed to promote new owner (target no longer a member).');
            }

            $demoted = $this->db->run(
                "UPDATE household_members SET role = 'member'
                 WHERE household_id = :hid AND user_id = :uid AND role = 'owner'",
                ['hid' => $householdId, 'uid' => $oldOwnerUserId],
            );
            if ($demoted !== 1) {
                throw new \RuntimeException('Failed to demote old owner (caller no longer the owner).');
            }

            if ($transactionStarted) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete a household. FK ON DELETE CASCADE on every child table
     * (household_members, events, event_exceptions, chores, chore_schedules,
     * chore_points_ledger, etc.) wipes their rows automatically.
     *
     * Idempotent — DELETE on a missing id is a no-op (rowCount=0). The
     * caller is responsible for any session-state cleanup (clearing
     * active_household_id) for users who were members.
     */
    public function delete(int $householdId): void
    {
        $this->db->run(
            'DELETE FROM households WHERE id = :id',
            ['id' => $householdId],
        );
    }

    /**
     * Generate an 8-char join code from the restricted alphabet, with
     * collision retry. ~5 attempts ceiling — in practice will never iterate
     * (32^8 = 1.1 trillion codes).
     */
    private function generateUniqueJoinCode(): string
    {
        for ($attempt = 0; $attempt < self::JOIN_CODE_MAX_ATTEMPTS; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::JOIN_CODE_LENGTH; $i++) {
                $code .= self::JOIN_CODE_ALPHABET[random_int(0, strlen(self::JOIN_CODE_ALPHABET) - 1)];
            }
            $exists = $this->db->fetchScalar(
                'SELECT 1 FROM households WHERE join_code = :code',
                ['code' => $code],
            );
            if ($exists === false || $exists === null) {
                return $code;
            }
        }
        throw new \RuntimeException('Failed to generate a unique join code after ' . self::JOIN_CODE_MAX_ATTEMPTS . ' attempts.');
    }
}
