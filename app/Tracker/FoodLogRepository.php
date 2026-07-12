<?php

declare(strict_types=1);

namespace App\Tracker;

use Karhu\Db\Connection;

/**
 * v0.8.0 — food_log write + read for the Today view.
 *
 * kcal_snapshot is computed by the CALLER (`round(qty * serving.kcal)`)
 * so this repo doesn't need to know about food_servings. Snapshot
 * semantics mirror chore_points_ledger (decision #31): historical rows
 * survive dish edits or deletes. food_id / serving_id use ON DELETE SET
 * NULL, so listForUserDay's LEFT JOIN emits COALESCE(name,'(deleted
 * dish)') for deleted-dish rows.
 *
 * listForUserDay is filtered by BOTH user_id AND household_id per round-2
 * plan-agent finding R2-#5 — a user with multiple household memberships
 * shouldn't see cross-household history when viewing the Today screen in
 * a specific household.
 */
final class FoodLogRepository
{
    /** Meal → sort weight for ORDER BY. Breakfast first, snack last. */
    private const MEAL_ORDER = [
        'breakfast' => 1,
        'lunch' => 2,
        'dinner' => 3,
        'snack' => 4,
    ];

    public function __construct(private readonly Connection $db) {}

    public function create(
        int $householdId,
        int $userId,
        int $foodId,
        int $servingId,
        float $qty,
        string $meal,
        string $loggedOn,
        int $kcalSnapshot,
    ): int {
        if (!isset(self::MEAL_ORDER[$meal])) {
            throw new \InvalidArgumentException("food_log.meal invalid: {$meal}");
        }
        if ($qty <= 0) {
            throw new \InvalidArgumentException('food_log.qty must be positive');
        }
        return (int) $this->db->fetchScalar(
            'INSERT INTO food_log
                (household_id, user_id, food_id, serving_id, qty, logged_on, meal, kcal_snapshot)
             VALUES
                (:hid, :uid, :fid, :sid, :qty, :day, :meal, :kcal)
             RETURNING id',
            [
                'hid' => $householdId,
                'uid' => $userId,
                'fid' => $foodId,
                'sid' => $servingId,
                'qty' => $qty,
                'day' => $loggedOn,
                'meal' => $meal,
                'kcal' => $kcalSnapshot,
            ],
        );
    }

    /**
     * Log entries for one user in one household on one calendar day, meal-ordered.
     * LEFT JOIN so deleted-food rows survive with (deleted dish) labels.
     *
     * @return list<array{
     *     id: int, meal: string, qty: string, kcal_snapshot: int,
     *     food_name: string, serving_label: string,
     *     food_id: ?int, serving_id: ?int, logged_at: string
     * }>
     */
    public function listForUserDay(int $userId, int $householdId, string $loggedOn): array
    {
        // MEAL_ORDER emitted as CASE so ORDER BY is portable across PG + SQLite.
        $rows = $this->db->fetchAll(
            "SELECT fl.id, fl.meal, fl.qty, fl.kcal_snapshot,
                    fl.food_id, fl.serving_id, fl.logged_at,
                    COALESCE(f.name, '(deleted dish)') AS food_name,
                    COALESCE(s.label, '(unknown serving)') AS serving_label,
                    CASE fl.meal
                        WHEN 'breakfast' THEN 1
                        WHEN 'lunch' THEN 2
                        WHEN 'dinner' THEN 3
                        WHEN 'snack' THEN 4
                        ELSE 99
                    END AS meal_order
             FROM food_log fl
             LEFT JOIN foods f ON f.id = fl.food_id
             LEFT JOIN food_servings s ON s.id = fl.serving_id
             WHERE fl.user_id = :uid
               AND fl.household_id = :hid
               AND fl.logged_on = :day
             ORDER BY meal_order ASC, fl.logged_at ASC",
            ['uid' => $userId, 'hid' => $householdId, 'day' => $loggedOn],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'meal' => (string) $r['meal'],
                'qty' => (string) $r['qty'],
                'kcal_snapshot' => (int) $r['kcal_snapshot'],
                'food_name' => (string) $r['food_name'],
                'serving_label' => (string) $r['serving_label'],
                'food_id' => $r['food_id'] !== null ? (int) $r['food_id'] : null,
                'serving_id' => $r['serving_id'] !== null ? (int) $r['serving_id'] : null,
                'logged_at' => (string) $r['logged_at'],
            ];
        }
        return $out;
    }

    /**
     * Delete a log entry, but only if $ownerUserId owns it. Returns affected
     * rowcount — 0 means not-owned (or not-found); repo doesn't distinguish.
     */
    public function deleteOwnedById(int $id, int $ownerUserId): int
    {
        return $this->db->run(
            'DELETE FROM food_log WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $ownerUserId],
        );
    }

    /**
     * Aggregate daily totals per user for the household on one day. Used
     * lightly in v0.8.0 (Today view shows own count only); v0.8.2's
     * energy-balance widget uses this heavily.
     *
     * @return array<int, array{user_id: int, total_kcal: int, entries: int}>
     */
    public function dailyTotalsForHousehold(int $householdId, string $loggedOn): array
    {
        $rows = $this->db->fetchAll(
            'SELECT user_id, SUM(kcal_snapshot) AS total_kcal, COUNT(*) AS entries
             FROM food_log
             WHERE household_id = :hid AND logged_on = :day
             GROUP BY user_id',
            ['hid' => $householdId, 'day' => $loggedOn],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = [
                'user_id' => (int) $r['user_id'],
                'total_kcal' => (int) $r['total_kcal'],
                'entries' => (int) $r['entries'],
            ];
        }
        return $out;
    }
}
