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
