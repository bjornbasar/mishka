<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.8.3 — TrackerLeaderboardController integration tests.
 *
 * Includes the load-bearing privacy regression: intake / weight / net
 * MUST NOT appear on /health/leaderboard. Only effort (MET-minutes,
 * strength session count, streaks, badges) is shared with the household.
 */
final class TrackerLeaderboardControllerTest extends AppTestCase
{
    /** @return array{0: int, 1: int} */
    private function signedIn(string $email = 'bjorn@x'): array
    {
        $uid = $this->createUserWithHash($email, 'passw0rd!', 'Bjorn');
        $hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'owner')",
            ['hid' => $hid, 'uid' => $uid],
        );
        $this->loginAs($uid, $email);
        $this->activateHouseholdInSession($uid, $hid, 'owner');
        return [$uid, $hid];
    }

    public function test_unauth_redirects_to_login(): void
    {
        $r = $this->request('GET', '/health/leaderboard');
        self::assertSame(302, $r->status());
        self::assertStringContainsString('/login', (string) $r->header('location'));
    }

    public function test_no_active_household_redirects_to_setup(): void
    {
        $uid = $this->createUserWithHash('u@x', 'passw0rd!', 'U');
        $this->loginAs($uid, 'u@x');
        // no active_household_id
        $r = $this->request('GET', '/health/leaderboard');
        self::assertSame(302, $r->status());
        self::assertStringContainsString('/household/setup', (string) $r->header('location'));
    }

    public function test_empty_household_renders_zero_effort_muted_row(): void
    {
        [$uid, $hid] = $this->signedIn();
        $body = $this->request('GET', '/health/leaderboard')->body();
        self::assertStringContainsString('Leaderboard', $body);
        // Solo user with no logs — table renders their row muted.
        self::assertStringContainsString('(you)', $body);
        // Zero-effort placeholder message.
        self::assertStringContainsString('log a workout', $body);
    }

    public function test_populated_ranking_two_users_by_met_minutes(): void
    {
        [$uidA, $hid] = $this->signedIn();
        $uidB = $this->createUserWithHash('other@x', 'passw0rd!', 'Wife');
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'member')",
            ['hid' => $hid, 'uid' => $uidB],
        );
        $exId = $this->exerciseRepo->create(null, ['name' => 'Run', 'type' => 'duration', 'met' => 9.8, 'source' => 'compendium'], null);
        $today = \App\Tracker\LocalDay::today(new \DateTimeZone('Pacific/Auckland'));
        // A: 2222 MET-min — 4-digit fingerprint unlikely to collide with layout CSS/text.
        $this->exerciseLogRepo->create($hid, $uidA, $exId, 'duration', 'Run',
            minutes: 226.7, sets: null, reps: null, loadKg: null,
            metMinutes: 2222.0, kcalSnapshot: 1890, loggedOn: $today);
        // B: 7777 MET-min — should rank ABOVE A.
        $this->exerciseLogRepo->create($hid, $uidB, $exId, 'duration', 'Run',
            minutes: 793.6, sets: null, reps: null, loadKg: null,
            metMinutes: 7777.0, kcalSnapshot: 6610, loggedOn: $today);

        $body = $this->request('GET', '/health/leaderboard')->body();
        // Both distinct values appear.
        self::assertStringContainsString('2222', $body);
        self::assertStringContainsString('7777', $body);
        // Order: 7777 comes BEFORE 2222 in the response body.
        self::assertLessThan(
            strpos($body, '2222'),
            strpos($body, '7777'),
            'higher MET-minute row must render first',
        );
    }

    public function test_strength_sidecar_appears_for_strength_only_user(): void
    {
        [$uid, $hid] = $this->signedIn();
        $exId = $this->exerciseRepo->create(null, ['name' => 'Squat', 'type' => 'strength', 'met' => 5.0, 'default_rom_m' => 0.5, 'source' => 'compendium'], null);
        $today = \App\Tracker\LocalDay::today(new \DateTimeZone('Pacific/Auckland'));
        $this->exerciseLogRepo->create($hid, $uid, $exId, 'strength', 'Squat',
            minutes: null, sets: 3, reps: 10, loadKg: 20.0,
            metMinutes: null, kcalSnapshot: 4, loggedOn: $today);

        $body = $this->request('GET', '/health/leaderboard')->body();
        self::assertMatchesRegularExpression('/1 session/i', $body);
    }

    public function test_viewer_row_highlighted_you_marker(): void
    {
        [$uid, $hid] = $this->signedIn();
        $body = $this->request('GET', '/health/leaderboard')->body();
        self::assertStringContainsString('(you)', $body);
    }

    public function test_badges_card_wall_renders_earned_emoji(): void
    {
        [$uid, $hid] = $this->signedIn();
        // Grant a tracker badge directly via the repo — no need to log a
        // workout to test the display path.
        $this->badgeAwardRepo->grant($hid, $uid, 'first_workout', '2026-07-14 00:00:00');
        $body = $this->request('GET', '/health/leaderboard')->body();
        // Emoji from config/badges.php['first_workout']
        self::assertStringContainsString('🏃', $body);
    }

    /**
     * Load-bearing privacy regression — TRACKER-PLAN.md §5 invariant.
     * Intake / weight / expenditure / net MUST NOT leak onto the
     * leaderboard. Uses distinct-value fingerprints on user B; asserts
     * NONE appear in user A's response body. PLUS shape-based negative
     * assertions to defend against a future maintainer copy-pasting the
     * Today balance-widget into leaderboard.twig.
     */
    public function test_leaderboard_does_not_leak_intake_or_weight_or_net(): void
    {
        [$uidA, $hid] = $this->signedIn();
        $uidB = $this->createUserWithHash('other@x', 'passw0rd!', 'Wife');
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'member')",
            ['hid' => $hid, 'uid' => $uidB],
        );

        // B's private fingerprints: 9876 kcal intake / 88.8 kg weight /
        // 5432 kcal exercise. Same shape as v0.8.2's Today regression.
        $this->profileRepo->upsert($uidB, [
            'sex' => 'female', 'birth_year' => 1988, 'height_cm' => 165.0, 'base_activity' => 1.200,
        ]);
        $this->weightLogRepo->create($uidB, 88.8, \App\Tracker\LocalDay::today(new \DateTimeZone('Pacific/Auckland')));
        $foodId = $this->foodRepo->create(null, ['name' => 'FingerprintFood', 'source' => 'custom'], null);
        $svB = $this->foodServingRepo->create($foodId, [
            'label' => '1 B', 'grams' => 100, 'kcal' => 9876, 'is_default' => true,
        ]);
        $this->foodLogRepo->create($hid, $uidB, $foodId, $svB, 1.0, 'breakfast', \App\Tracker\LocalDay::today(new \DateTimeZone('Pacific/Auckland')), 9876);

        $exId = $this->exerciseRepo->create(null, [
            'name' => 'FingerprintExercise', 'type' => 'duration', 'met' => 8.0,
        ], null);
        $this->exerciseLogRepo->create($hid, $uidB, $exId, 'duration', 'FingerprintExercise',
            minutes: 30.0, sets: null, reps: null, loadKg: null,
            metMinutes: 240.0, kcalSnapshot: 5432, loggedOn: \App\Tracker\LocalDay::today(new \DateTimeZone('Pacific/Auckland')));

        // User A logs their own small workout so their row shows something.
        $this->exerciseLogRepo->create($hid, $uidA, $exId, 'duration', 'FingerprintExercise',
            minutes: 15.0, sets: null, reps: null, loadKg: null,
            metMinutes: 120.0, kcalSnapshot: 100, loggedOn: \App\Tracker\LocalDay::today(new \DateTimeZone('Pacific/Auckland')));

        $body = $this->request('GET', '/health/leaderboard')->body();

        // B's distinct private-value fingerprints MUST NOT appear.
        self::assertStringNotContainsString('9876', $body, 'intake kcal must NOT leak');
        self::assertStringNotContainsString('88.8', $body, 'weight kg must NOT leak');
        self::assertStringNotContainsString('5432', $body, 'exercise kcal snapshot must NOT leak');

        // Shape-based negative assertions — defends against value mangling
        // (rounding, kJ conversion) that would evade the fingerprint check.
        // Uniquely-widget-shape markers: "Intake:" / "Expenditure:" / "Net:" / "BMR:"
        // only appear on the Today balance widget. Broader tokens like "kcal"
        // or " kg" leak into layout.twig's live-search IIFE + generic UI copy
        // and don't discriminate widget vs leaderboard, so they're omitted.
        self::assertStringNotContainsString('Intake:', $body);
        self::assertStringNotContainsString('Expenditure:', $body);
        self::assertStringNotContainsString('BMR:', $body);
        self::assertStringNotContainsString('Net:', $body);

        // Shared effort MAY appear — B's MET-min (240) IS an effort value.
        self::assertStringContainsString('240', $body);
    }
}
