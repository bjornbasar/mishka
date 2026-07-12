<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class WeightControllerTest extends AppTestCase
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
        $r = $this->request('GET', '/health/weight');
        self::assertSame(302, $r->status());
        self::assertStringContainsString('/login', (string) $r->header('location'));
    }

    public function test_get_form_renders_empty_state(): void
    {
        $this->signedIn();
        $r = $this->request('GET', '/health/weight');
        self::assertSame(200, $r->status());
        self::assertStringContainsString('No weight recorded yet', $r->body());
    }

    public function test_post_creates_and_lists(): void
    {
        [$uid, ] = $this->signedIn();
        $this->request('POST', '/health/weight', [
            'weight_kg' => '68.5',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        $latest = $this->weightLogRepo->latestForUser($uid);
        self::assertNotNull($latest);
        self::assertEqualsWithDelta(68.5, (float) $latest['weight_kg'], 0.001);
    }

    public function test_post_rejects_out_of_range(): void
    {
        [$uid, ] = $this->signedIn();
        $this->request('POST', '/health/weight', [
            'weight_kg' => '5',
        ], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertNull($this->weightLogRepo->latestForUser($uid));
    }

    public function test_delete_removes_own_entry(): void
    {
        [$uid, ] = $this->signedIn();
        $id = $this->weightLogRepo->create($uid, 68.5, '2026-07-13');
        $this->request('POST', "/health/weight/{$id}/delete", [], headers: ['content-type' => 'application/x-www-form-urlencoded']);
        self::assertNull($this->weightLogRepo->latestForUser($uid));
    }
}
