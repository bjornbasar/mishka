<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class HomeControllerTest extends AppTestCase
{
    public function test_anonymous_visitor_sees_pitch_and_ctas(): void
    {
        $response = $this->request('GET', '/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Mishka Den', $response->body());
        self::assertStringContainsString('/register', $response->body());
        self::assertStringContainsString('/login', $response->body());
    }

    public function test_logged_in_without_active_household_is_redirected_to_setup(): void
    {
        // Pre-v0.2 user shape: logged in but never went through household setup.
        $this->loginAs(1, 'a@example.com');  // synthetic; no DB row needed

        $response = $this->request('GET', '/');

        self::assertSame(302, $response->status());
        self::assertSame('/household/setup', $response->header('location'));
    }

    public function test_logged_in_with_active_household_sees_household_name(): void
    {
        $userId = $this->createUserWithHash('a@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Test Den', $userId);
        $this->loginAs($userId, 'a@example.com');
        $this->activateHouseholdInSession($userId, $hid, 'owner');

        $response = $this->request('GET', '/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Test Den', $response->body());
        self::assertStringContainsString('Manage household', $response->body());
    }

    public function test_home_shows_points_board_and_chore_counts(): void
    {
        $userId = $this->createUserWithHash('a@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Test Den', $userId);
        $this->loginAs($userId, 'a@example.com');
        $this->activateHouseholdInSession($userId, $hid, 'owner');

        // One completed chore worth 10 points + one open chore.
        $done = $this->choreRepo->create([
            'household_id' => $hid, 'created_by' => $userId, 'title' => 'Dishes',
            'description' => '', 'points' => 10, 'due_at_local' => null,
            'assigned_to' => $userId, 'timezone' => 'Pacific/Auckland',
        ]);
        $this->choreRepo->markDone($done, $userId);
        $this->choreRepo->create([
            'household_id' => $hid, 'created_by' => $userId, 'title' => 'Vacuum',
            'description' => '', 'points' => 5, 'due_at_local' => null,
            'assigned_to' => $userId, 'timezone' => 'Pacific/Auckland',
        ]);

        $response = $this->request('GET', '/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Leaderboard', $response->body());
        self::assertStringContainsString('10', $response->body());          // earned points
        self::assertStringContainsString('1 open', $response->body());      // one open chore
        self::assertStringContainsString('/chores', $response->body());
        // v0.4.3: one completion → first_chore badge renders (emoji + title attr).
        self::assertStringContainsString('🌱', $response->body());
        self::assertStringContainsString('First chore', $response->body());
    }

    public function test_logged_in_with_stale_active_household_redirects_to_setup(): void
    {
        // Session says active_household_id=$hid; user was kicked before this request.
        // NavContext's freshness check returns null active_household → HomeController
        // redirects, self-healing the session.
        $userId = $this->createUserWithHash('a@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Test', $userId);

        $this->loginAs($userId, 'a@example.com');
        $this->activateHouseholdInSession($userId, $hid, 'owner');

        // Kick them out from underneath the session.
        $this->db->run('DELETE FROM household_members WHERE user_id = :uid', ['uid' => $userId]);

        $response = $this->request('GET', '/');

        self::assertSame(302, $response->status());
        self::assertSame('/household/setup', $response->header('location'));
    }
}
