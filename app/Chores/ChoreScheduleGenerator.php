<?php

declare(strict_types=1);

namespace App\Chores;

use App\Household\HouseholdRepository;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;

/**
 * Materialise recurring-chore occurrences as `chores` rows, lazily on view.
 *
 * Three correctness pillars (from the round-2 skeptic review):
 *
 *  B1 — recurr ALWAYS iterates from the schedule's anchor (DTSTART); there is no
 *       "expand from date X". So we expand from the anchor with a virtualLimit
 *       sized to reach the horizon, but CLAMP the materialised window to
 *       (genFrom, genTo] where genFrom = generated_through ?? max(anchor, now-14d)
 *       and genTo = now+14d. A `generated_through` watermark makes re-views cheap
 *       and lets an old anchor catch up over a few views. A hard MAX_GENERATE_PER_RUN
 *       is the circuit-breaker.
 *
 *  B2 — rotation is a PURE FUNCTION of (last_assigned_user_id, current members in
 *       join order), not a fragile integer index. The cursor advances ONLY after a
 *       successful insert, generating occurrences oldest-first, so a 3-member roster
 *       yields A,B,C,A,B and membership churn / re-runs never skip or double-assign.
 *
 *  B3 — each occurrence inserts under ChoreRepository::createGenerated; a
 *       UNIQUE(schedule_id, occurrence_date) violation (a concurrent page load
 *       already generated it) is swallowed and the cursor is NOT advanced.
 *
 * Occurrence wall-clock times are formatted directly (never tz-converted) so a
 * "daily 9am" schedule stays 9am local across DST, matching the calendar model.
 */
final class ChoreScheduleGenerator
{
    private const LOOKAHEAD_DAYS = 14;
    private const BACKFILL_DAYS = 14;
    private const MAX_GENERATE_PER_RUN = 60;
    private const VLIMIT_CEIL = 750;

    public function __construct(
        private readonly ChoreScheduleRepository $schedules,
        private readonly ChoreRepository $chores,
        private readonly HouseholdRepository $households,
    ) {}

    /** Generate due occurrences for every schedule in the household. Returns rows created. */
    public function generateForHousehold(int $householdId, ?\DateTimeImmutable $now = null): int
    {
        // Paused schedules are skipped HERE (never inside generateForSchedule, which
        // unconditionally advances the watermark — skipping there would drift it
        // forward while paused and lose occurrences after resume).
        $paused = array_flip($this->schedules->listPausedIds($householdId));

        $created = 0;
        foreach ($this->schedules->listForHousehold($householdId) as $schedule) {
            if (isset($paused[(int) $schedule['id']])) {
                continue;
            }
            $created += $this->generateForSchedule($schedule, $now ?? new \DateTimeImmutable('now'));
        }
        return $created;
    }

    /**
     * The unit-testable core. `$now` is injected so the horizon math is deterministic.
     *
     * @param array<string, mixed> $schedule
     */
    public function generateForSchedule(array $schedule, \DateTimeImmutable $now): int
    {
        $tz = new \DateTimeZone((string) $schedule['timezone']);
        $now = $now->setTimezone($tz);
        $anchor = new \DateTimeImmutable((string) $schedule['anchor_at_local'], $tz);

        $genFrom = $schedule['generated_through'] !== null
            ? new \DateTimeImmutable((string) $schedule['generated_through'], $tz)
            : $this->maxDate($anchor, $now->modify('-' . self::BACKFILL_DAYS . ' days'));
        $genTo = $now->modify('+' . self::LOOKAHEAD_DAYS . ' days');

        if ($genFrom >= $genTo) {
            return 0;
        }

        $scheduleId = (int) $schedule['id'];
        $hid = (int) $schedule['household_id'];
        $mode = (string) $schedule['assignment_mode'];
        $lastAssigned = $schedule['last_assigned_user_id'] === null ? null : (int) $schedule['last_assigned_user_id'];

        // Compute the rotation candidate list ONCE (avoid an N+1 in the loop).
        // Pool has rows → cycle listMembers ∩ pool in join order; no rows → all
        // members. A pool whose members have all left the household → empty
        // candidate list, which yields NULL (unassigned) rather than silently
        // reassigning to people the user never picked.
        $candidateIds = [];
        $poolRestricted = false;
        if ($mode === 'rotate') {
            $memberIds = array_map(
                static fn(array $m): int => (int) $m['user_id'],
                $this->households->listMembers($hid),
            );
            $pool = $this->schedules->listParticipantIds($scheduleId);
            if ($pool !== []) {
                $poolRestricted = true;
                $candidateIds = array_values(array_filter(
                    $memberIds,
                    static fn(int $id): bool => in_array($id, $pool, true),
                ));
            } else {
                $candidateIds = $memberIds;
            }
        }

        $created = 0;
        foreach ($this->expandDates($schedule, $anchor, $genTo, $tz) as $occ) {
            if ($occ <= $genFrom) {
                continue;
            }
            if ($occ > $genTo) {
                break;
            }
            if ($created >= self::MAX_GENERATE_PER_RUN) {
                break;
            }

            $occStr = $occ->format('Y-m-d H:i:00');

            if ($mode === 'fixed') {
                $fixed = $schedule['fixed_user_id'] === null ? null : (int) $schedule['fixed_user_id'];
                $assignee = ($fixed !== null && $this->households->isMember($fixed, $hid)) ? $fixed : null;
            } elseif ($poolRestricted && $candidateIds === []) {
                $assignee = null;  // chosen pool members have all left the household
            } else {
                $assignee = $this->nextRotation($lastAssigned, $candidateIds);
            }

            try {
                $this->chores->createGenerated([
                    'household_id' => $hid,
                    'created_by' => (int) $schedule['created_by'],
                    'schedule_id' => $scheduleId,
                    'occurrence_date' => $occStr,
                    'due_at_local' => $occStr,
                    'assigned_to' => $assignee,
                    'title' => (string) $schedule['title'],
                    'description' => (string) $schedule['description'],
                    'points' => (int) $schedule['points'],
                    'timezone' => (string) $schedule['timezone'],
                ]);
            } catch (\PDOException $e) {
                if (!$this->isUniqueViolation($e)) {
                    throw $e;
                }
                continue;  // concurrent dup already generated this occurrence; don't advance the cursor
            }

            $created++;
            if ($mode === 'rotate' && $assignee !== null) {
                $this->schedules->setRotation($scheduleId, $assignee);
                $lastAssigned = $assignee;
            }
        }

        $this->schedules->setGeneratedThrough($scheduleId, $genTo->format('Y-m-d H:i:00'));
        return $created;
    }

    /**
     * Next assignee = pure function of (last, candidate ids in join order).
     * If `last` is still a candidate, the one after them; otherwise the head of
     * the list (first-ever generation, or last assignee no longer a candidate).
     *
     * @param list<int> $ids
     */
    private function nextRotation(?int $last, array $ids): ?int
    {
        if ($ids === []) {
            return null;
        }
        if ($last === null) {
            return $ids[0];
        }
        $pos = array_search($last, $ids, true);
        if ($pos === false) {
            return $ids[0];
        }
        return $ids[($pos + 1) % count($ids)];
    }

    /**
     * Expand the rrule from the anchor with recurr (same recipe as
     * RangeExpander::expandSeries), sized to reach the horizon. Wall-clock,
     * tz-aware — DST-safe.
     *
     * @param array<string, mixed> $schedule
     * @return list<\DateTimeImmutable>
     */
    private function expandDates(
        array $schedule,
        \DateTimeImmutable $anchor,
        \DateTimeImmutable $genTo,
        \DateTimeZone $tz,
    ): array {
        $daysToHorizon = (int) $anchor->diff($genTo)->days;
        $vlimit = min($daysToHorizon + 21, self::VLIMIT_CEIL);

        $rule = new Rule((string) $schedule['rrule'], $anchor);
        $config = new ArrayTransformerConfig();
        $config->setVirtualLimit($vlimit);
        $transformer = new ArrayTransformer($config);

        $out = [];
        foreach ($transformer->transform($rule) as $r) {
            $start = $r->getStart();
            $out[] = $start instanceof \DateTimeImmutable
                ? $start->setTimezone($tz)
                : \DateTimeImmutable::createFromMutable($start)->setTimezone($tz);
        }
        return $out;
    }

    private function maxDate(\DateTimeImmutable $a, \DateTimeImmutable $b): \DateTimeImmutable
    {
        return $a >= $b ? $a : $b;
    }

    private function isUniqueViolation(\PDOException $e): bool
    {
        $sqlState = $e->getCode();
        if ($sqlState === '23505') {
            return true;
        }
        return $sqlState === '23000' && str_contains($e->getMessage(), 'UNIQUE');
    }
}
