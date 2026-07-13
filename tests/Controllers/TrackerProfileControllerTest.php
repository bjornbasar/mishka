<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.8.2 — TrackerProfileController integration tests.
 *
 * Covers CRUD (GET form + POST upsert), bounds validation, and auth
 * gates. Upsert is exercised twice (create + update path) in the same
 * assertion block so the DO UPDATE branch is proven end-to-end.
 */
final class TrackerProfileControllerTest extends AppTestCase
{
    /** @return array{0: int, 1: int} */
    private function signedIn(): array
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

    public function test_unauth_redirects_to_login(): void
    {
        $r = $this->request('GET', '/health/profile');
        self::assertSame(302, $r->status());
        self::assertStringContainsString('/login', (string) $r->header('location'));
    }

    public function test_no_active_household_redirects_to_setup(): void
    {
        $uid = $this->createUserWithHash('bjorn@x', 'passw0rd!', 'Bjorn');
        $this->loginAs($uid, 'bjorn@x');
        // no activateHouseholdInSession call — active_household_id missing
        $r = $this->request('GET', '/health/profile');
        self::assertSame(302, $r->status());
        self::assertStringContainsString('/household/setup', (string) $r->header('location'));
    }

    public function test_get_form_renders_empty_state(): void
    {
        $this->signedIn();
        $r = $this->request('GET', '/health/profile');
        self::assertSame(200, $r->status());
        // Double-count-trap wording MUST be present — see DOCS #72.
        self::assertStringContainsString('EXCLUDING WORKOUTS', $r->body());
        self::assertStringContainsString('name="base_activity"', $r->body());
    }

    public function test_get_form_renders_populated_state(): void
    {
        [$uid, ] = $this->signedIn();
        $this->profileRepo->upsert($uid, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 1.375,
        ]);
        $this->weightLogRepo->create($uid, 72.5, '2026-07-12');
        $r = $this->request('GET', '/health/profile');
        self::assertSame(200, $r->status());
        // BMR preview panel shows a number when profile + weight both present.
        self::assertStringContainsString('BMR preview', $r->body());
        self::assertStringContainsString('72.5 kg', $r->body());
    }

    public function test_post_creates_profile(): void
    {
        [$uid, ] = $this->signedIn();
        $r = $this->request('POST', '/health/profile', [
            'sex' => 'male',
            'birth_year' => '1985',
            'height_cm' => '175.0',
            'base_activity' => '1.375',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(303, $r->status());
        self::assertSame('/health', $r->header('location'));
        $row = $this->profileRepo->findByUserId($uid);
        self::assertNotNull($row);
        self::assertSame('male', $row['sex']);
        self::assertSame(1985, (int) $row['birth_year']);
        self::assertEqualsWithDelta(1.375, (float) $row['base_activity'], 0.0005);
    }

    public function test_post_updates_existing_profile(): void
    {
        [$uid, ] = $this->signedIn();
        $this->profileRepo->upsert($uid, [
            'sex' => 'male', 'birth_year' => 1985, 'height_cm' => 175.0, 'base_activity' => 1.200,
        ]);
        $r = $this->request('POST', '/health/profile', [
            'sex' => 'male',
            'birth_year' => '1985',
            'height_cm' => '175.0',
            'base_activity' => '1.550',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(303, $r->status());
        $row = $this->profileRepo->findByUserId($uid);
        self::assertNotNull($row);
        self::assertEqualsWithDelta(1.550, (float) $row['base_activity'], 0.0005);
    }

    public function test_post_rejects_out_of_range_height(): void
    {
        [$uid, ] = $this->signedIn();
        $r = $this->request('POST', '/health/profile', [
            'sex' => 'male',
            'birth_year' => '1985',
            'height_cm' => '10',       // absurdly low → repo bound
            'base_activity' => '1.375',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(422, $r->status());
        self::assertNull($this->profileRepo->findByUserId($uid));
    }

    public function test_post_rejects_current_year_birth_year(): void
    {
        // Plan-agent finding #5 — birth_year = currentYear must reject (would give age=0).
        [$uid, ] = $this->signedIn();
        $r = $this->request('POST', '/health/profile', [
            'sex' => 'male',
            'birth_year' => (string) ((int) date('Y')),
            'height_cm' => '175.0',
            'base_activity' => '1.375',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertSame(422, $r->status());
        self::assertNull($this->profileRepo->findByUserId($uid));
    }
}
