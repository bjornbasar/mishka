<?php

declare(strict_types=1);

namespace App\Commands;

use App\Chores\BadgeAwardRepository;
use Karhu\Attributes\Command;
use Karhu\Db\Connection;

/**
 * v0.6.13 — backfill badge_awards from historical chore_points_ledger.
 *
 * One-time invocation post-deploy. Idempotent — re-runs are no-ops because
 * BadgeAwardRepository::grant() uses ON CONFLICT DO NOTHING / INSERT OR
 * IGNORE.
 *
 * Single PHP-side path (no PG_ONLY branch) so the walker works on both PG
 * and SQLite. Walks ledger rows per (household, user) accumulating count +
 * cumulative-points; on each threshold crossing, writes badge_awards with
 * earned_at = the triggering completed_at (NOT the time of the backfill
 * invocation).
 *
 * Skips `four_week_streak` per decision #14 — eager-evaluation on the next
 * chore completion will fill it. Backfilling streak accurately requires
 * walking week-by-week with DST-safe arithmetic; the marginal value is
 * low (streak badge is recent-state-aware anyway) and the implementation
 * cost is high.
 *
 * Output format (round-2 S5 — exact match for the assertion in
 * BadgesBackfillCommandTest):
 *   badges:backfill: note: four_week_streak skipped — eager evaluation on next chore completion will fill it.
 *   badges:backfill: wrote N awards across M users (skipped K existing).
 */
final class BadgesBackfillCommand
{
    /** @var array<string, int> count thresholds (matches BadgeAwarder) */
    private const COUNT_THRESHOLDS = [
        'first_chore'  => 1,
        'ten_chores'   => 10,
        'fifty_chores' => 50,
    ];

    /** @var array<string, int> cumulative-points thresholds */
    private const POINTS_THRESHOLDS = [
        'centurion'    => 100,
        'five_hundred' => 500,
    ];

    public function __construct(
        private readonly BadgeAwardRepository $awards,
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string, string|true> $args
     */
    #[Command('badges:backfill', 'Backfill badge_awards from historical chore_points_ledger')]
    public function handle(array $args): int
    {
        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        $written = 0;
        $skipped = 0;
        $usersTouched = [];

        try {
            // Fetch all ledger rows ordered for streaming per-user accumulation.
            // Rows where credited_user_id IS NULL (post-account-delete) are
            // skipped — there's no user to attribute the badge to.
            $rows = $this->db->fetchAll(
                'SELECT household_id, credited_user_id, points, completed_at
                 FROM chore_points_ledger
                 WHERE credited_user_id IS NOT NULL
                 ORDER BY household_id ASC, credited_user_id ASC, completed_at ASC, id ASC',
            );

            $currentKey = '';
            $count = 0;
            $cumPoints = 0;

            foreach ($rows as $row) {
                $hid = (int) $row['household_id'];
                $uid = (int) $row['credited_user_id'];
                $key = "{$hid}:{$uid}";

                if ($key !== $currentKey) {
                    $currentKey = $key;
                    $count = 0;
                    $cumPoints = 0;
                }

                $count++;
                $cumPoints += (int) $row['points'];
                $completedAt = (string) $row['completed_at'];

                // Count thresholds: emit on EXACT crossing (count === N).
                foreach (self::COUNT_THRESHOLDS as $code => $threshold) {
                    if ($count === $threshold) {
                        $usersTouched[$key] = true;
                        if ($this->awards->grant($hid, $uid, $code, $completedAt)) {
                            $written++;
                        } else {
                            $skipped++;
                        }
                    }
                }

                // Points thresholds: emit when cumulative FIRST crosses N.
                // (Computed as before-row vs after-row; the first row to push
                // cum >= N is the one whose completed_at is the earned_at.)
                foreach (self::POINTS_THRESHOLDS as $code => $threshold) {
                    if ($cumPoints >= $threshold && ($cumPoints - (int) $row['points']) < $threshold) {
                        $usersTouched[$key] = true;
                        if ($this->awards->grant($hid, $uid, $code, $completedAt)) {
                            $written++;
                        } else {
                            $skipped++;
                        }
                    }
                }
            }

            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            // Defensive — PG may have auto-rolled the txn on the inner error.
            // @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(\STDERR, "badges:backfill failed: {$e->getMessage()}\n");
            return 1;
        }

        fwrite(\STDOUT, "badges:backfill: note: four_week_streak skipped — eager evaluation on next chore completion will fill it.\n");
        fwrite(\STDOUT, sprintf(
            "badges:backfill: wrote %d awards across %d users (skipped %d existing).\n",
            $written, count($usersTouched), $skipped,
        ));
        return 0;
    }
}
