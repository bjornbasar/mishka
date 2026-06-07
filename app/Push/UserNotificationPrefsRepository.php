<?php

declare(strict_types=1);

namespace App\Push;

use Karhu\Db\Connection;

/**
 * v0.6.0 + v0.6.6 — per-user push notification preferences.
 *
 * One row per user. Four scalars:
 *   - `event_reminder_minutes` (0=disabled, max 1440=24h, default 15)
 *   - `overdue_chore_digest` (boolean, default true)
 *   - `new_chore_assigned_enabled` (v0.6.6, boolean, default true)
 *   - `new_event_enabled` (v0.6.6, boolean, default true)
 *
 * The digest TIMING is global (07:30–08:30 household-tz, no per-user override
 * in v0.6) — per-user customisation is a v0.7 candidate if anyone complains.
 *
 * Upsert pattern mirrors UserPreferenceRepository — PG + SQLite 3.24+ both
 * accept ON CONFLICT (user_id) DO UPDATE.
 *
 * getFor() returns the v0.6.6 defaults (15-min reminder + 3 booleans all true)
 * when a user has no row yet, which avoids forcing every user to visit
 * /me/notifications before push:scan starts trying to notify them.
 *
 * setFor() is a PARTIAL UPDATE (v0.6.6): only keys present in the input array
 * are written; absent keys preserve their current value (or fall to the
 * default if no row exists). This makes the v0.6.5→v0.6.6 deploy window safe
 * — a stale browser tab posting only the v0.6.5 keys does NOT silently flip
 * the new v0.6.6 booleans to false. The change is strictly more robust than
 * the v0.6.0 replace-all semantics, and no caller relied on the old behaviour.
 */
final class UserNotificationPrefsRepository
{
    private const DEFAULT_REMINDER_MINUTES = 15;
    private const DEFAULT_DIGEST = true;
    private const DEFAULT_NEW_CHORE_ASSIGNED = true;
    private const DEFAULT_NEW_EVENT = true;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   event_reminder_minutes: int,
     *   overdue_chore_digest: bool,
     *   new_chore_assigned_enabled: bool,
     *   new_event_enabled: bool
     * }
     */
    public function getFor(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT event_reminder_minutes, overdue_chore_digest,
                    new_chore_assigned_enabled, new_event_enabled
             FROM user_notification_prefs WHERE user_id = :uid',
            ['uid' => $userId],
        );
        if ($row === null) {
            return [
                'event_reminder_minutes' => self::DEFAULT_REMINDER_MINUTES,
                'overdue_chore_digest' => self::DEFAULT_DIGEST,
                'new_chore_assigned_enabled' => self::DEFAULT_NEW_CHORE_ASSIGNED,
                'new_event_enabled' => self::DEFAULT_NEW_EVENT,
            ];
        }
        // SQLite stores booleans as 0/1 strings; cast through int to bool.
        return [
            'event_reminder_minutes' => (int) $row['event_reminder_minutes'],
            'overdue_chore_digest' => (bool) (int) $row['overdue_chore_digest'],
            'new_chore_assigned_enabled' => (bool) (int) $row['new_chore_assigned_enabled'],
            'new_event_enabled' => (bool) (int) $row['new_event_enabled'],
        ];
    }

    /**
     * Partial upsert (v0.6.6). Only keys present in `$prefs` are written;
     * absent keys keep their current value or fall to default if no row exists.
     *
     * Caller is responsible for clamping `event_reminder_minutes` to [0, 1440];
     * the schema's CHECK will throw a PDOException otherwise.
     *
     * @param array{
     *   event_reminder_minutes?: int,
     *   overdue_chore_digest?: bool,
     *   new_chore_assigned_enabled?: bool,
     *   new_event_enabled?: bool
     * } $prefs
     */
    public function setFor(int $userId, array $prefs): void
    {
        // Resolve effective values: input-if-present, else current-if-row-exists,
        // else default. Read current first so the insert path has all 4 values.
        $current = $this->getFor($userId);
        $mins    = array_key_exists('event_reminder_minutes', $prefs)
            ? (int) $prefs['event_reminder_minutes']
            : $current['event_reminder_minutes'];
        $digest  = array_key_exists('overdue_chore_digest', $prefs)
            ? (bool) $prefs['overdue_chore_digest']
            : $current['overdue_chore_digest'];
        $nca     = array_key_exists('new_chore_assigned_enabled', $prefs)
            ? (bool) $prefs['new_chore_assigned_enabled']
            : $current['new_chore_assigned_enabled'];
        $newEv   = array_key_exists('new_event_enabled', $prefs)
            ? (bool) $prefs['new_event_enabled']
            : $current['new_event_enabled'];

        $this->db->run(
            'INSERT INTO user_notification_prefs
                (user_id, event_reminder_minutes, overdue_chore_digest,
                 new_chore_assigned_enabled, new_event_enabled)
             VALUES (:uid, :mins, :digest, :nca, :ev)
             ON CONFLICT (user_id) DO UPDATE
             SET event_reminder_minutes     = EXCLUDED.event_reminder_minutes,
                 overdue_chore_digest       = EXCLUDED.overdue_chore_digest,
                 new_chore_assigned_enabled = EXCLUDED.new_chore_assigned_enabled,
                 new_event_enabled          = EXCLUDED.new_event_enabled,
                 updated_at                 = CURRENT_TIMESTAMP',
            [
                'uid' => $userId,
                'mins' => $mins,
                'digest' => $digest ? 1 : 0,
                'nca' => $nca ? 1 : 0,
                'ev' => $newEv ? 1 : 0,
            ],
        );
    }
}
