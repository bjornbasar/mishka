<?php

declare(strict_types=1);

namespace App\Auth;

use Karhu\Db\Connection;

/**
 * v0.6.19 — system-level role lookups for `system_roles` table.
 *
 * Karhu's `Karhu\Auth\Rbac` is `final` and explicitly SQL-free, so this
 * mishka-internal class owns all system-role DB access. Used by:
 *   - AccountController::handleDelete (only-admin self-delete pre-check)
 *   - AccountController::handlePromoteAdmin (admin-gate + candidate list)
 *
 * Driver-aware idempotent grant() mirrors BadgeAwardRepository::grant
 * (decision #54): PG uses ON CONFLICT (user_id, role) DO NOTHING; SQLite
 * uses INSERT OR IGNORE. The `system_roles` PK is composite
 * (user_id, role) so re-granting the same role is silently a no-op.
 *
 * No nested-txn guard on any method — each method is a single statement,
 * caller owns the transaction.
 */
final class SystemRoleRepository
{
    private const ROLE_ADMIN = 'admin';

    /** SQL conflict-suppression suffix; driver-detected at ctor time. */
    private readonly string $onConflict;

    public function __construct(private readonly Connection $db)
    {
        $driver = (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->onConflict = $driver === 'pgsql'
            ? 'ON CONFLICT (user_id, role) DO NOTHING'
            : '';   // SQLite: rewrite INSERT verb to INSERT OR IGNORE instead.
    }

    public function isSystemAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $hit = $this->db->fetchScalar(
            'SELECT 1 FROM system_roles WHERE user_id = :uid AND role = :role',
            ['uid' => $userId, 'role' => self::ROLE_ADMIN],
        );
        return $hit !== null && $hit !== false;
    }

    public function countSystemAdmins(): int
    {
        $count = $this->db->fetchScalar(
            'SELECT COUNT(DISTINCT user_id) FROM system_roles WHERE role = :role',
            ['role' => self::ROLE_ADMIN],
        );
        return (int) $count;
    }

    /**
     * Idempotent grant of the 'admin' role. Returns true iff a new row was
     * written (first grant). Returns false on re-grant (PK conflict, silently
     * skipped via driver-appropriate idempotent INSERT).
     */
    public function grantSystemAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $verb = $this->onConflict === '' ? 'INSERT OR IGNORE INTO' : 'INSERT INTO';
        $tail = $this->onConflict === '' ? '' : ' ' . $this->onConflict;
        $sql = "{$verb} system_roles (user_id, role) VALUES (:uid, :role){$tail}";
        $rows = $this->db->run($sql, [
            'uid' => $userId,
            'role' => self::ROLE_ADMIN,
        ]);
        return $rows === 1;
    }

    /**
     * Candidates for admin promotion — every user EXCEPT the caller.
     * Ordered by display_name for a stable dropdown UX.
     *
     * @return list<array{id: int, email: string, display_name: string}>
     */
    public function listPromotionCandidates(int $excludeUid): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, email, display_name FROM users
             WHERE id != :exclude AND id > 0
             ORDER BY display_name ASC, email ASC',
            ['exclude' => $excludeUid],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'email' => (string) $r['email'],
                'display_name' => (string) $r['display_name'],
            ];
        }
        return $out;
    }
}
