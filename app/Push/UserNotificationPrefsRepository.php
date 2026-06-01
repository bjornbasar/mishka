<?php

declare(strict_types=1);

namespace App\Push;

use Karhu\Db\Connection;

/**
 * v0.6.0 — per-user push notification preferences.
 *
 * One row per user. Two scalars: `event_reminder_minutes` (0=disabled, max
 * 1440=24h) and `overdue_chore_digest` (boolean). The digest TIMING is global
 * (07:30–08:30 household-tz, no per-user override in v0.6) — per-user
 * customisation is a v0.7 candidate if anyone complains.
 *
 * Upsert pattern mirrors UserPreferenceRepository — PG + SQLite 3.24+ both
 * accept ON CONFLICT (user_id) DO UPDATE.
 *
 * getFor() returns the v0.6 defaults (15-min reminder + digest enabled) when
 * a user has no row yet, which avoids forcing every user to visit
 * /me/notifications before push:scan starts trying to notify them.
 */
final class UserNotificationPrefsRepository
{
    private const DEFAULT_REMINDER_MINUTES = 15;
    private const DEFAULT_DIGEST = true;

    public function __construct(private readonly Connection $db) {}

    /** @return array{event_reminder_minutes: int, overdue_chore_digest: bool} */
    public function getFor(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT event_reminder_minutes, overdue_chore_digest
             FROM user_notification_prefs WHERE user_id = :uid',
            ['uid' => $userId],
        );
        if ($row === null) {
            return [
                'event_reminder_minutes' => self::DEFAULT_REMINDER_MINUTES,
                'overdue_chore_digest' => self::DEFAULT_DIGEST,
            ];
        }
        return [
            'event_reminder_minutes' => (int) $row['event_reminder_minutes'],
            // SQLite stores booleans as 0/1 strings; cast through int to bool.
            'overdue_chore_digest' => (bool) (int) $row['overdue_chore_digest'],
        ];
    }

    /**
     * Upsert. Caller is responsible for clamping `event_reminder_minutes` to
     * [0, 1440]; the schema's CHECK will throw a PDOException otherwise.
     *
     * @param array{event_reminder_minutes: int, overdue_chore_digest: bool} $prefs
     */
    public function setFor(int $userId, array $prefs): void
    {
        $this->db->run(
            'INSERT INTO user_notification_prefs
                (user_id, event_reminder_minutes, overdue_chore_digest)
             VALUES (:uid, :mins, :digest)
             ON CONFLICT (user_id) DO UPDATE
             SET event_reminder_minutes = EXCLUDED.event_reminder_minutes,
                 overdue_chore_digest   = EXCLUDED.overdue_chore_digest,
                 updated_at             = CURRENT_TIMESTAMP',
            [
                'uid' => $userId,
                'mins' => $prefs['event_reminder_minutes'],
                'digest' => $prefs['overdue_chore_digest'] ? 1 : 0,
            ],
        );
    }
}
