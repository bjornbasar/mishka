<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class TrackerControllerTest extends AppTestCase
{
    public function test_get_health_redirects_to_login_when_logged_out(): void
    {
        $response = $this->request('GET', '/health');
        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('location'));
    }

    public function test_get_health_renders_today_view_for_authed_user(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwnerLocal();
        $response = $this->request('GET', '/health');
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Health', $response->body());
        // Empty-state copy per Plan-agent finding R2-#9.
        self::assertStringContainsString('Nothing logged yet today', $response->body());
    }

    public function test_health_nav_link_appears_when_household_active(): void
    {
        $this->signInAsHouseholdOwnerLocal();
        $response = $this->request('GET', '/');
        self::assertStringContainsString('href="/health"', $response->body());
    }

    // v0.8.2 — Today energy-balance widget state fork tests.

    public function test_today_widget_renders_needs_profile_state_when_no_profile(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwnerLocal();
        // No profile, no weight — 'needs_profile' state wins per precedence rule.
        $body = $this->request('GET', '/health')->body();
        self::assertStringContainsString('Set up your profile to see your daily balance', $body);
        self::assertStringContainsString('href="/health/profile"', $body);
    }

    public function test_today_widget_renders_needs_weight_state_when_profile_present_but_no_weight(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwnerLocal();
        $this->profileRepo->upsert($uid, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 1.375,
        ]);
        $body = $this->request('GET', '/health')->body();
        self::assertStringContainsString('Record your weight', $body);
        self::assertStringContainsString('href="/health/weight"', $body);
    }

    public function test_today_widget_renders_complete_state_with_breakdown(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwnerLocal();
        $this->profileRepo->upsert($uid, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 1.375,
        ]);
        $this->weightLogRepo->create($uid, 72.5, date('Y-m-d'));
        $body = $this->request('GET', '/health')->body();
        // Complete-state renders the breakdown labels.
        self::assertStringContainsString("Today's balance", $body);
        self::assertStringContainsString('BMR:', $body);
        self::assertStringContainsString('excluding workouts', $body);
        // Base-activity value appears inline.
        self::assertMatchesRegularExpression('/base_activity\s+1\.375/i', $body);
    }

    public function test_today_does_not_leak_other_users_intake_or_weight(): void
    {
        // Privacy regression — Plan-agent finding #2. User A must NOT see any
        // of user B's distinct-value fingerprint strings in the Today body.
        [$uidA, $hid] = $this->signInAsHouseholdOwnerLocal();
        $uidB = $this->createUserWithHash('otheruser@x', 'passw0rd!', 'Wife');
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'member')",
            ['hid' => $hid, 'uid' => $uidB],
        );

        // Both users get profiles + weights so both would hit 'complete' state.
        $this->profileRepo->upsert($uidA, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 1.200,
        ]);
        $this->profileRepo->upsert($uidB, [
            'sex' => 'female', 'birth_year' => 1988, 'height_cm' => 165.0, 'base_activity' => 1.200,
        ]);
        // Household-local today — MUST match TrackerController::today's
        // query axis (LocalDay::today(Auckland)); date('Y-m-d') returns
        // UTC which diverges for ~10 hours each day. Latent v0.8.2 bug
        // caught by v0.8.4's cross-TZ test runs — see DOCS #74.
        $todayLocal = \App\Tracker\LocalDay::today(new \DateTimeZone('Pacific/Auckland'));
        $this->weightLogRepo->create($uidA, 70.0, $todayLocal);
        // Distinct-value fingerprint for user B: 88.8 kg (weight).
        $this->weightLogRepo->create($uidB, 88.8, $todayLocal);

        // Distinct-value fingerprints for food:
        //  - user A eats 1234 kcal
        //  - user B eats 9876 kcal
        $foodId = $this->foodRepo->create(null, ['name' => 'FingerprintFood', 'source' => 'custom'], null);
        $svA = $this->foodServingRepo->create($foodId, [
            'label' => '1 A', 'grams' => 100, 'kcal' => 1234, 'is_default' => true,
        ]);
        $svB = $this->foodServingRepo->create($foodId, [
            'label' => '1 B', 'grams' => 100, 'kcal' => 9876, 'is_default' => false,
        ]);
        $this->foodLogRepo->create($hid, $uidA, $foodId, $svA, 1.0, 'breakfast', $todayLocal, 1234);
        $this->foodLogRepo->create($hid, $uidB, $foodId, $svB, 1.0, 'breakfast', $todayLocal, 9876);

        // Distinct-value fingerprint for exercise: user B logs 5432 kcal.
        $exId = $this->exerciseRepo->create(null, [
            'name' => 'FingerprintExercise',
            'type' => 'duration',
            'met' => 8.0,
        ], null);
        $this->exerciseLogRepo->create(
            $hid, $uidB, $exId, 'duration', 'FingerprintExercise',
            30.0, null, null, null, 240.0, 5432, $todayLocal,
        );

        // User A is signed in from signInAsHouseholdOwnerLocal above.
        $body = $this->request('GET', '/health')->body();

        // A's own intake shows.
        self::assertStringContainsString('1234', $body);
        // B's distinct-value fingerprints MUST NOT appear.
        self::assertStringNotContainsString('9876', $body);
        self::assertStringNotContainsString('88.8', $body);
        self::assertStringNotContainsString('5432', $body);
    }

    /** @return array{0: int, 1: int} */
    private function signInAsHouseholdOwnerLocal(): array
    {
        $uid = $this->createUserWithHash('bjorn@x', 'passw0rd!', 'Bjorn');
        $hid = (int) $this->db->fetchScalar(
            "INSERT INTO households (name, join_code, timezone) VALUES ('HH', 'AAAAAA', 'Pacific/Auckland') RETURNING id",
        );
        $this->db->run(
            "INSERT INTO household_members (household_id, user_id, role) VALUES (:hid, :uid, 'owner')",
            ['hid' => $hid, 'uid' => $uid],
        );
        $this->loginAs($uid, 'bjorn@x');
        $this->activateHouseholdInSession($uid, $hid, 'owner');
        return [$uid, $hid];
    }
}
