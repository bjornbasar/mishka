<?php

declare(strict_types=1);

namespace App\Chores;

use Karhu\Db\Connection;

/**
 * v0.6.13 — persistent badge-earn records.
 *
 * Reverses decision #35 (stateless badges, v0.4.3). Each row pins the user's
 * earn moment for a single badge code. UNIQUE(household_id, user_id, badge_code)
 * gives "earned once forever" semantics; the eager-award path's grant() uses
 * the driver-appropriate idempotent INSERT (ON CONFLICT DO NOTHING on PG,
 * INSERT OR IGNORE on SQLite).
 *
 * No nested-txn guard on any method — each method is a single statement,
 * caller owns the transaction. Mirrors the IcalFeedTokenRepository pattern.
 */
final class BadgeAwardRepository
{
    /** SQL conflict-suppression suffix; driver-detected at ctor time. */
    private readonly string $insertOrIgnore;

    public function __construct(private readonly Connection $db)
    {
        // Match HouseholdRepository::__construct's ctor-time driver detection.
        // PG ON CONFLICT requires the target columns; SQLite uses INSERT OR IGNORE
        // (which is per-statement and doesn't need the column list).
        $driver = (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->insertOrIgnore = $driver === 'pgsql'
            ? 'ON CONFLICT (household_id, user_id, badge_code) DO NOTHING'
            : '';   // SQLite: rewrite the INSERT verb instead (see grant()).
    }

    /**
     * Idempotent grant. Returns true iff a new row was written (first earn).
     * Returns false on the second+ attempt (UNIQUE conflict, silently skipped).
     */
    public function grant(int $householdId, int $userId, string $badgeCode, string $earnedAt): bool
    {
        if ($householdId <= 0 || $userId <= 0 || $badgeCode === '') {
            return false;
        }
        // SQLite path: `INSERT OR IGNORE INTO ... VALUES ...`.
        // PG path: `INSERT INTO ... VALUES ... ON CONFLICT (...) DO NOTHING`.
        $verb = $this->insertOrIgnore === '' ? 'INSERT OR IGNORE INTO' : 'INSERT INTO';
        $tail = $this->insertOrIgnore === '' ? '' : ' ' . $this->insertOrIgnore;
        $sql = "{$verb} badge_awards (household_id, user_id, badge_code, earned_at)
                VALUES (:hid, :uid, :code, :earned){$tail}";
        $rows = $this->db->run($sql, [
            'hid' => $householdId,
            'uid' => $userId,
            'code' => $badgeCode,
            'earned' => $earnedAt,
        ]);
        return $rows === 1;
    }

    /**
     * Per-user badge list, ordered by earn time DESC (newest first). Feeds
     * the /badges per-user grid + the controller-side "earned_codes" derivation.
     *
     * @return list<array{badge_code: string, earned_at: string}>
     */
    public function listForUser(int $householdId, int $userId): array
    {
        if ($householdId <= 0 || $userId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT badge_code, earned_at FROM badge_awards
             WHERE household_id = :hid AND user_id = :uid
             ORDER BY earned_at DESC',
            ['hid' => $householdId, 'uid' => $userId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'badge_code' => (string) $r['badge_code'],
                'earned_at' => (string) $r['earned_at'],
            ];
        }
        return $out;
    }

    /**
     * Bulk fetch for leaderboard rendering — one query per household covers
     * all members. INNER JOIN household_members so departed members drop out
     * (their badge_awards rows stay in the table but are hidden from the
     * household roster, matching the leaderboardForHousehold posture in #24).
     *
     * Per round-2 S6: order by earned_at ASC so the per-user code lists carry
     * journey-order semantics (oldest badge first).
     *
     * @return array<int, list<string>> user_id → list of badge codes
     */
    public function listByUserForHousehold(int $householdId): array
    {
        if ($householdId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT ba.user_id, ba.badge_code
             FROM badge_awards ba
             INNER JOIN household_members hm
                 ON hm.user_id = ba.user_id AND hm.household_id = ba.household_id
             WHERE ba.household_id = :hid
             ORDER BY ba.user_id, ba.earned_at ASC',
            ['hid' => $householdId],
        );
        $out = [];
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            $out[$uid] ??= [];
            $out[$uid][] = (string) $r['badge_code'];
        }
        return $out;
    }

    /**
     * Per-member badge counts for the /badges roster section. INNER JOIN
     * household_members so departed members don't appear.
     *
     * @return array<int, int> user_id → badge count
     */
    public function countsByUserForHousehold(int $householdId): array
    {
        if ($householdId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT ba.user_id, COUNT(*) AS c
             FROM badge_awards ba
             INNER JOIN household_members hm
                 ON hm.user_id = ba.user_id AND hm.household_id = ba.household_id
             WHERE ba.household_id = :hid
             GROUP BY ba.user_id',
            ['hid' => $householdId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * Flat list of badge codes earned by this user in this household. Used
     * by the eager-award short-circuit (avoid redundant grant() calls).
     *
     * @return list<string>
     */
    public function listCodesForUser(int $householdId, int $userId): array
    {
        if ($householdId <= 0 || $userId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT badge_code FROM badge_awards
             WHERE household_id = :hid AND user_id = :uid',
            ['hid' => $householdId, 'uid' => $userId],
        );
        return array_map(static fn(array $r): string => (string) $r['badge_code'], $rows);
    }
}
