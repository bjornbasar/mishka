<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\HouseholdAuthorizer;
use App\Chores\Achievements;
use App\Chores\BadgeAwardRepository;
use App\Chores\WeekWindow;
use App\Household\HouseholdRepository;
use App\Tracker\ExerciseLogRepository;
use App\Tracker\TrackerBadgeAwarder;
use App\View\NavContext;
use Karhu\Attributes\Route;
use Karhu\Http\Request;
use Karhu\Http\Response;
use Karhu\Middleware\Session;
use Karhu\View\TwigAdapter;

/**
 * v0.8.3 — /health/leaderboard (Tracker Phase 4).
 *
 * Household-shared effort leaderboard. Ranks by weekly MET-minutes
 * (duration entries); strength surfaces as a session-count sidecar.
 * Streaks + badges (all household badges — chore + tracker merged).
 *
 * Privacy invariant (TRACKER-PLAN.md §5, DOCS #72): intake / weight /
 * expenditure / net NEVER appear on this page. Only EFFORT is shared
 * with the household. Regression-tested by response-body fingerprinting
 * + shape-based negative assertions in
 * TrackerLeaderboardControllerTest::test_leaderboard_does_not_leak_...
 *
 * Auth triad mirrors BadgesController (v0.6.13):
 *   anonymous → 302 /login
 *   no active_household_id → 302 /household/setup
 *   non-member of active household → HouseholdAuthorizer self-heal.
 */
final class TrackerLeaderboardController
{
    public function __construct(
        private readonly ExerciseLogRepository $exerciseLog,
        private readonly BadgeAwardRepository $awards,
        private readonly HouseholdRepository $households,
        private readonly HouseholdAuthorizer $auth,
        private readonly NavContext $nav,
        private readonly TwigAdapter $view,
    ) {}

    #[Route('/health/leaderboard', methods: ['GET'], name: 'tracker.leaderboard')]
    public function show(Request $request): Response
    {
        $uid = Session::get('user_id');
        if (!is_int($uid) || $uid <= 0) {
            return (new Response())->redirect('/login', 302);
        }
        $hid = Session::get('active_household_id');
        if (!is_int($hid) || $hid <= 0) {
            return (new Response())->redirect('/household/setup', 302);
        }
        $this->auth->requireMember($uid, $hid);

        $household = $this->households->findById($hid);
        $tzName = (string) ($household['timezone'] ?? 'Pacific/Auckland');
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('Pacific/Auckland');
        }
        $now = new \DateTimeImmutable('now');

        $weekStartLocal = WeekWindow::weekStartLocal($tz, $now);
        $weekEndLocal = WeekWindow::weekEndLocal($tz, $weekStartLocal);
        // Streak feed spans 52 weeks back (matches TrackerBadgeAwarder's
        // STREAK_LOOKBACK_WEEKS) — plenty for the 30-day daily streak too.
        $sinceLocal = WeekWindow::lookbackStartLocal($tz, 52, $now);
        $tomorrowLocal = self::plusOneDay($weekEndLocal, $tz);   // half-open right edge

        $weekRows = $this->exerciseLog->weeklyLeaderboardForHousehold($hid, $weekStartLocal, $weekEndLocal);

        // Per-user badges + streaks. BadgeAwardRepository already scopes
        // by household — no chore-vs-tracker separation (DOCS #73: merged
        // achievement wall). Roster iteration follows the weekRows list so
        // display order matches ranking.
        $badgesByUser = $this->awards->listByUserForHousehold($hid);
        // Daily-activity streak: household-wide batched fetch, PHP-side
        // dedup + walk via Achievements::computeDailyStreakLocal.
        $recentDaysByUser = $this->exerciseLog->recentLoggedOnsForHousehold(
            $hid,
            $sinceLocal,
            $tomorrowLocal,
        );
        // Household-local today + yesterday DATE anchors — feed streak walker.
        $todayAnchor = \App\Chores\DayWindow::dayStartLocal($tz, $now);
        $yesterdayAnchor = \App\Chores\DayWindow::previousDayStartLocal($tz, $todayAnchor);

        $rosterStreaks = [];
        foreach ($weekRows as $row) {
            $rowUid = $row['user_id'];
            $daysList = $recentDaysByUser[$rowUid] ?? [];
            $rosterStreaks[$rowUid] = [
                'daily' => Achievements::computeDailyStreakLocal($daysList, $tz, $todayAnchor, $yesterdayAnchor),
                // Weekly streak: compute using the awarder's pure function
                // over the user's own daily-MET-minutes bucket. One SQL per
                // user is acceptable at family scale (≤ 8 members typical);
                // batching this SQL would be premature.
                'weekly' => TrackerBadgeAwarder::computeWeeklyMetStreak(
                    $this->exerciseLog->dailyMetMinutesForUser($rowUid, $hid, $sinceLocal, $tomorrowLocal),
                    TrackerBadgeAwarder::isoWeekKey($todayAnchor),
                    150,
                ),
            ];
        }

        return (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->view->render('tracker/leaderboard.twig', [
                'week_rows' => $weekRows,
                'week_start_local' => $weekStartLocal,
                'week_end_local' => $weekEndLocal,
                'roster_badges' => $badgesByUser,
                'roster_streaks' => $rosterStreaks,
                'viewer_user_id' => $uid,
            ] + $this->nav->forCurrentUser()));
    }

    /** Add one day to a `Y-m-d` DATE string, computed in $tz (DST-safe). */
    private static function plusOneDay(string $ymd, \DateTimeZone $tz): string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, $tz);
        if ($dt === false) {
            throw new \InvalidArgumentException("plusOneDay requires Y-m-d, got: {$ymd}");
        }
        return $dt->modify('+1 day')->format('Y-m-d');
    }
}
