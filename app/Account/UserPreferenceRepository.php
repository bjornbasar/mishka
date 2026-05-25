<?php

declare(strict_types=1);

namespace App\Account;

use Karhu\Db\Connection;

/**
 * Per-user preferences — currently just `last_household_id` (for restoring
 * the user's active household across sessions). Future per-user prefs land
 * here without touching `users` (so schema.sql stays ALTER-free).
 *
 * Decoupled from `users` because SQLite (the test harness) does not support
 * `ALTER TABLE ADD COLUMN IF NOT EXISTS` — keeping schema.sql pure CREATE
 * TABLE statements lets it run idempotently on PG (prod) and SQLite (tests).
 */
final class UserPreferenceRepository
{
    public function __construct(private readonly Connection $db) {}

    /** Returns the user's last-selected household id, or null if none. */
    public function getLastHouseholdId(int $userId): ?int
    {
        $value = $this->db->fetchScalar(
            'SELECT last_household_id FROM user_preferences WHERE user_id = :uid',
            ['uid' => $userId],
        );

        return $value === null || $value === false ? null : (int) $value;
    }

    /**
     * Upsert the user's last-selected household.
     * Uses PostgreSQL / SQLite 3.24+ `INSERT ... ON CONFLICT DO UPDATE`.
     */
    public function setLastHouseholdId(int $userId, int $householdId): void
    {
        $this->db->run(
            'INSERT INTO user_preferences (user_id, last_household_id)
             VALUES (:uid, :hid)
             ON CONFLICT (user_id) DO UPDATE
             SET last_household_id = EXCLUDED.last_household_id,
                 updated_at = CURRENT_TIMESTAMP',
            ['uid' => $userId, 'hid' => $householdId],
        );
    }
}
