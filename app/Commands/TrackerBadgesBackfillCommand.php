<?php

declare(strict_types=1);

namespace App\Commands;

use App\Tracker\TrackerBadgeAwarder;
use Karhu\Attributes\Command;
use Karhu\Db\Connection;

/**
 * v0.8.3 — backfill tracker badges from historical exercise_log rows.
 *
 * Mirrors {@see BadgesBackfillCommand} shape but delegates to
 * {@see TrackerBadgeAwarder::evaluateAndGrant} rather than walking
 * ledger rows per-crossing. The awarder's `cumulativeStatsForUser` +
 * `dailyMetMinutesForUser` reads compute the current state directly,
 * which is what we want for backfill: "given this user's history + now,
 * grant every earned badge that isn't yet in badge_awards."
 *
 * Idempotent — every grant path is `ON CONFLICT DO NOTHING` (PG) /
 * `INSERT OR IGNORE` (SQLite). Re-runs are silent no-ops.
 *
 * Fidelity trade-off: `earned_at` on backfilled badges is the backfill
 * invocation timestamp, NOT the exercise_log row that crossed the
 * threshold. Chores' `badges:backfill` walks rows to preserve fidelity,
 * but tracker's cumulative shape (SUM over met_minutes) doesn't lend
 * itself to per-row emission — we'd have to replay 5000 MET-minute
 * accumulations to detect the crossing. Family-scale: the marginal
 * value of "earned_at = actual crossing" over "earned_at = deploy time"
 * is negligible.
 *
 * Streak badges (`four_week_effort_streak`, `seven/thirty_day_activity_
 * streak`) require the user's recent activity to still be current at
 * backfill time — same eager-only semantics as chore streak badges
 * (DOCS #54/#55).
 *
 * Output format (documented for downstream tooling; not asserted by tests):
 *   tracker:badges-backfill: user 12 in household 3: 4 new awards.
 *   tracker:badges-backfill: wrote N awards across M user-household pairs.
 */
final class TrackerBadgesBackfillCommand
{
    public function __construct(
        private readonly TrackerBadgeAwarder $awarder,
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string, string|true> $args
     */
    #[Command('tracker:badges-backfill', 'Backfill tracker badges from historical exercise_log')]
    public function handle(array $args): int
    {
        $now = new \DateTimeImmutable('now');
        $totalWritten = 0;
        $pairs = 0;

        try {
            // Enumerate households — we NEED the timezone for correct
            // household-local WeekWindow / DayWindow math.
            $households = $this->db->fetchAll(
                'SELECT id, timezone FROM households ORDER BY id ASC',
            );
            foreach ($households as $hh) {
                $hid = (int) $hh['id'];
                $tzName = (string) ($hh['timezone'] ?? '');
                if ($tzName === '') {
                    $tzName = 'Pacific/Auckland';   // defensive fallback (matches ChoresController)
                }
                try {
                    $tz = new \DateTimeZone($tzName);
                } catch (\Throwable $e) {
                    fwrite(\STDERR, "tracker:badges-backfill: skipping household {$hid} (bad tz '{$tzName}'): {$e->getMessage()}\n");
                    continue;
                }

                $members = $this->db->fetchAll(
                    'SELECT user_id FROM household_members WHERE household_id = :hid AND user_id > 0',
                    ['hid' => $hid],
                );
                foreach ($members as $m) {
                    $uid = (int) $m['user_id'];
                    $before = (int) $this->db->fetchScalar(
                        'SELECT COUNT(*) FROM badge_awards WHERE household_id = :hid AND user_id = :uid',
                        ['hid' => $hid, 'uid' => $uid],
                    );
                    $this->awarder->evaluateAndGrant($hid, $uid, $tz, $now);
                    $after = (int) $this->db->fetchScalar(
                        'SELECT COUNT(*) FROM badge_awards WHERE household_id = :hid AND user_id = :uid',
                        ['hid' => $hid, 'uid' => $uid],
                    );
                    $delta = $after - $before;
                    $totalWritten += $delta;
                    $pairs++;
                    if ($delta > 0) {
                        fwrite(\STDOUT, sprintf(
                            "tracker:badges-backfill: user %d in household %d: %d new awards.\n",
                            $uid, $hid, $delta,
                        ));
                    }
                }
            }
        } catch (\Throwable $e) {
            fwrite(\STDERR, "tracker:badges-backfill failed: {$e->getMessage()}\n");
            return 1;
        }

        fwrite(\STDOUT, sprintf(
            "tracker:badges-backfill: wrote %d awards across %d user-household pairs.\n",
            $totalWritten, $pairs,
        ));
        return 0;
    }
}
