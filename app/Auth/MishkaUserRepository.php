<?php

declare(strict_types=1);

namespace App\Auth;

use Karhu\Auth\UserRepositoryInterface;
use Karhu\Db\Connection;

/**
 * Mishka user repository — email is the canonical identifier.
 *
 * Adapts karhu's UserRepositoryInterface (which keys on an opaque
 * "username" string) to mishka's integer-PK + email schema. The returned
 * `username` field is the email; the integer id is available via
 * findIdByEmail() / findById() for FK relationships.
 *
 * Excludes the system sentinel user (id=0, email='__system__') from
 * every lookup so registration validation cannot accidentally see it.
 */
final class MishkaUserRepository implements UserRepositoryInterface
{
    /**
     * SQL function name for JSON array aggregation. PostgreSQL uses
     * json_agg; SQLite 3.38+ uses json_group_array. Detected from the
     * PDO driver at construction so the per-query SQL stays portable.
     */
    private string $jsonAggFn;

    public function __construct(private readonly Connection $db)
    {
        $driver = (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->jsonAggFn = $driver === 'pgsql' ? 'json_agg' : 'json_group_array';
    }

    /**
     * Karhu auth contract — looks up by the opaque "username" string
     * (which mishka treats as the user's email).
     *
     * Adds an extra `id` key to the response (beyond the interface
     * contract) so login can avoid a second round-trip to fetch the PK.
     * Consumers that only care about karhu's typed shape will ignore it.
     *
     * @return array{id: int, username: string, password_hash: string,
     *               roles: list<string>, email_verified_at: ?string}|null
     */
    public function findByUsername(string $username): ?array
    {
        $email = $this->normaliseEmail($username);

        // Single query — JOIN + jsonAgg avoids a second round-trip for roles.
        // FILTER excludes the LEFT JOIN's null rows when a user has no roles.
        // v0.5.0: also SELECT email_verified_at so login can seed the session
        // (NavContext reads Session::get('email_verified_at') to derive the
        // soft verify banner — avoids a per-render SELECT).
        $sql = "SELECT u.id, u.email, u.password_hash, u.email_verified_at,
                       COALESCE({$this->jsonAggFn}(r.role) FILTER (WHERE r.role IS NOT NULL), '[]') AS roles
                FROM users u
                LEFT JOIN system_roles r ON r.user_id = u.id
                WHERE u.email = :email AND u.id > 0
                GROUP BY u.id, u.email, u.password_hash, u.email_verified_at";

        $row = $this->db->fetchOne($sql, ['email' => $email]);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['email'],
            'password_hash' => (string) $row['password_hash'],
            'roles' => $this->decodeRoles($row['roles']),
            'email_verified_at' => $row['email_verified_at'] === null
                ? null
                : (string) $row['email_verified_at'],
        ];
    }

    /**
     * Karhu auth contract — just the roles for an email.
     *
     * @return list<string>
     */
    public function rolesFor(string $username): array
    {
        $email = $this->normaliseEmail($username);
        $rows = $this->db->fetchAll(
            'SELECT r.role FROM system_roles r
             JOIN users u ON u.id = r.user_id
             WHERE u.email = :email AND u.id > 0',
            ['email' => $email],
        );
        return array_map(fn(array $r): string => (string) $r['role'], $rows);
    }

    /**
     * Mishka-internal — lookup by integer PK (preferred for FK joins).
     *
     * @return array{id: int, email: string, display_name: string, password_hash: string, roles: list<string>}|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sql = "SELECT u.id, u.email, u.display_name, u.password_hash,
                       COALESCE({$this->jsonAggFn}(r.role) FILTER (WHERE r.role IS NOT NULL), '[]') AS roles
                FROM users u
                LEFT JOIN system_roles r ON r.user_id = u.id
                WHERE u.id = :id
                GROUP BY u.id, u.email, u.display_name, u.password_hash";

        $row = $this->db->fetchOne($sql, ['id' => $id]);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'password_hash' => (string) $row['password_hash'],
            'roles' => $this->decodeRoles($row['roles']),
        ];
    }

    /** Mishka-internal — fetch the integer PK for an email, or null. */
    public function findIdByEmail(string $email): ?int
    {
        $email = $this->normaliseEmail($email);
        $id = $this->db->fetchScalar(
            'SELECT id FROM users WHERE email = :email AND id > 0',
            ['email' => $email],
        );
        return $id === false || $id === null ? null : (int) $id;
    }

    /** True if the email is already registered (case-insensitive). */
    public function emailExists(string $email): bool
    {
        return $this->findIdByEmail($email) !== null;
    }

    /**
     * Create a user, then atomically claim the admin sentinel.
     *
     * The first registration's UPDATE matches the seeded sentinel row
     * (user_id=0, role='admin') and transfers ownership to the new user
     * — race-free at the SQL level. Subsequent registrations see 0 rows
     * affected and fall through to a plain INSERT of the 'member' role.
     *
     * Guards nested transactions so this works under the test harness
     * (which wraps each test in an outer transaction) and in production
     * (where no outer txn exists).
     */
    public function create(string $email, string $passwordHash, string $displayName): int
    {
        $email = $this->normaliseEmail($email);
        $pdo = $this->db->pdo();

        // Track whether *we* started the transaction, not whether one exists.
        // beginTransaction() throws in ERRMODE_EXCEPTION on failure, so if it
        // throws we never set the flag and the catch won't try to rollBack a
        // transaction we don't own.
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            // INSERT … RETURNING id is portable across PG and SQLite 3.35+.
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO users (email, password_hash, display_name)
                 VALUES (:email, :hash, :name)
                 RETURNING id',
                ['email' => $email, 'hash' => $passwordHash, 'name' => $displayName],
            );

            // Atomic admin claim: transfers the sentinel row from user_id=0
            // to the new user iff nobody else has claimed it yet.
            $claimed = $this->db->run(
                "UPDATE system_roles SET user_id = :new_id
                 WHERE role = 'admin' AND user_id = 0",
                ['new_id' => $id],
            );

            if ($claimed === 0) {
                $this->db->insert('system_roles', ['user_id' => $id, 'role' => 'member']);
            }

            if ($transactionStarted) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Stamp the last_login_at column for an authenticated user. */
    public function recordLogin(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $this->db->run(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $userId],
        );
    }

    // ============================================================
    // v0.5.0 extensions — account lifecycle
    // ============================================================

    /**
     * Update the user's display name. No-op for the system sentinel (id=0).
     * Caller is responsible for length + content validation; this method
     * just writes whatever it's given.
     */
    public function updateDisplayName(int $userId, string $displayName): void
    {
        if ($userId <= 0) {
            return;
        }
        $this->db->run(
            'UPDATE users SET display_name = :name, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['name' => $displayName, 'id' => $userId],
        );
    }

    /**
     * Update the password hash AND stamp the credential-change in the same
     * transaction. The pair is atomic — a failure on either write rolls both
     * back, preserving the invariant that SessionRevocationGuard relies on
     * (every password change is reflected in user_password_changes).
     *
     * BL-2: caller MUST pass the pinned `$now` from the handler. This repo
     * never re-derives the timestamp — passing two different gmdate() values
     * for users.updated_at vs user_password_changes.password_changed_at is the
     * exact bug BL-2 prevents.
     *
     * Nested-txn guard pattern (mirrors create()).
     */
    public function updatePassword(int $userId, string $passwordHash, string $now): void
    {
        if ($userId <= 0) {
            return;
        }
        $pdo = $this->db->pdo();
        $transactionStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            $this->db->run(
                'UPDATE users SET password_hash = :h, updated_at = :now WHERE id = :id',
                ['h' => $passwordHash, 'now' => $now, 'id' => $userId],
            );

            // Stamp credential-change with the SAME `$now` the caller pinned.
            // Constructing UserPasswordChangeRepository here keeps the ctor of
            // MishkaUserRepository stable (single Connection arg) while still
            // making the two writes atomic via the shared PDO transaction.
            (new UserPasswordChangeRepository($this->db))->stamp($userId, $now);

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
     * Set `email_verified_at = NOW()` for a user, ONLY if it's currently NULL.
     * The WHERE guard makes this idempotent — re-verifying a token that was
     * already redeemed (race) doesn't overwrite the original verification
     * timestamp.
     */
    public function markEmailVerified(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $this->db->run(
            'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP,
                              updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND email_verified_at IS NULL',
            ['id' => $userId],
        );
    }

    /**
     * v0.6.11 — atomic email-swap, applied inside the caller's transaction.
     *
     * The caller is responsible for opening and committing the surrounding
     * transaction (the `/me/email-change/{token}` controller does this so the
     * token redemption + swap + pending-token invalidations are all atomic).
     * This method is a single UPDATE; no nested-txn guard.
     *
     * Sets `email_verified_at = CURRENT_TIMESTAMP` unconditionally: the user
     * just clicked through to the new address, which IS the verification
     * event (decision #52). Pre-existing verification of the OLD email is
     * irrelevant — it's a different mailbox now.
     *
     * Throws `\PDOException` with SQLSTATE 23505 (PG) or 23000+UNIQUE (SQLite)
     * if `new_email` is already taken by another user (the race-with-another-
     * user case). Caller catches and surfaces as a 422 conflict page.
     *
     * Returns true iff exactly one row was updated (i.e. the user exists).
     */
    public function applyEmailSwap(int $userId, string $newEmail): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $newEmail = $this->normaliseEmail($newEmail);
        $rows = $this->db->run(
            'UPDATE users SET email = :e,
                              email_verified_at = CURRENT_TIMESTAMP,
                              updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            ['e' => $newEmail, 'id' => $userId],
        );
        return $rows === 1;
    }

    /** Returns true iff the user has a non-null email_verified_at stamp. */
    public function isEmailVerified(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $stamp = $this->db->fetchScalar(
            'SELECT email_verified_at FROM users WHERE id = :id',
            ['id' => $userId],
        );
        return $stamp !== null && $stamp !== false;
    }

    /** Normalise email: trim + lowercase. Applied on every read and write. */
    private function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Parse a JSON-array string (from json_agg / json_group_array) into a
     * list of role strings. Returns [] for null, '[]', or malformed input.
     *
     * @return list<string>
     */
    private function decodeRoles(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $roles = array_values(array_filter($decoded, 'is_string'));
        /** @var list<string> $roles */
        return $roles;
    }
}
