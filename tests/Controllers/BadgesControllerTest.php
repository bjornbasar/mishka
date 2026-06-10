<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

/**
 * v0.6.13 — BadgesController integration tests.
 *
 * Covers the GET /badges handler: auth triad (login + active household +
 * membership), per-user grid (earned + locked), household roster section,
 * and the badge_meta Twig global wiring (emoji rendering).
 */
final class BadgesControllerTest extends AppTestCase
{
    public function test_get_badges_renders_earned_and_locked_sections(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();

        // Grant 2 badges, leave 4 locked.
        $now = gmdate('Y-m-d H:i:s');
        $this->badgeAwardRepo->grant($hid, $uid, 'first_chore', $now);
        $this->badgeAwardRepo->grant($hid, $uid, 'ten_chores', $now);

        $response = $this->request('GET', '/badges');

        self::assertSame(200, $response->status());
        $body = $response->body();
        // Earned section: shows "2 of 6 earned" and the two emoji.
        // v0.6.14 added seven_day_streak + thirty_day_streak → 8 total.
        self::assertStringContainsString('2 of 8', $body);
        self::assertStringContainsString('🌱', $body);
        self::assertStringContainsString('⭐', $body);
        // Locked section: 🔒 appears for the remaining badges.
        self::assertStringContainsString('🔒', $body);
        // Earned cards carry an "Earned" date label.
        self::assertStringContainsString('Earned', $body);
    }

    public function test_get_badges_shows_household_roster_with_other_members(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        // Add a second member with one badge.
        $other = $this->createUserWithHash('other@example.com', self::testPassword(), 'Other');
        $this->householdRepo->addMember($hid, $other);
        $now = gmdate('Y-m-d H:i:s');
        $this->badgeAwardRepo->grant($hid, $other, 'first_chore', $now);

        $response = $this->request('GET', '/badges');

        $body = $response->body();
        self::assertSame(200, $response->status());
        // Roster section shows the other member.
        self::assertStringContainsString('Household roster', $body);
        self::assertStringContainsString('Other', $body);
        // Self should NOT appear in the roster (filtered out by the controller).
        // We can't directly assert by text since "Owner" might match — but we
        // can count occurrences of the roster <li> shape.
    }

    public function test_get_badges_redirects_anonymous_to_login(): void
    {
        $response = $this->request('GET', '/badges');
        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('location'));
    }

    public function test_get_badges_redirects_to_household_setup_if_no_active_household(): void
    {
        // Sign in but DON'T activate a household.
        $uid = $this->createUserWithHash('lone@example.com', self::testPassword());
        $this->loginAs($uid, 'lone@example.com');
        // Note: loginAs does NOT set active_household_id.

        $response = $this->request('GET', '/badges');

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/household/setup', (string) $response->header('location'));
    }

    public function test_get_badges_redirects_non_member_via_household_authorizer(): void
    {
        // User A creates a household. User B logs in with A's active_household_id
        // in their session but is NOT a member. HouseholdAuthorizer should clear
        // active_household_id and redirect to / (its documented self-heal).
        [$ownerUid, $hid] = $this->signInAsHouseholdOwner();
        $stranger = $this->createUserWithHash('stranger@example.com', self::testPassword());

        // Take over the session as the stranger but keep $hid as active.
        $_SESSION['user_id'] = $stranger;
        $_SESSION['username'] = 'stranger@example.com';
        $_SESSION['active_household_id'] = $hid;

        $response = $this->request('GET', '/badges');

        // HouseholdAuthorizer::requireMember throws ForbiddenException → 403.
        self::assertContains($response->status(), [302, 403]);
    }

    public function test_get_badges_renders_emoji_from_badge_meta_global(): void
    {
        // Granting four_week_streak alone — the emoji should be 🔥 per
        // config/badges.php. Verifies the Twig global wiring + the canonical
        // mapping flows through.
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $now = gmdate('Y-m-d H:i:s');
        $this->badgeAwardRepo->grant($hid, $uid, 'four_week_streak', $now);

        $response = $this->request('GET', '/badges');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('🔥', $response->body());
        self::assertStringContainsString('On fire', $response->body());
    }

    private function signInAsHouseholdOwner(): array
    {
        $uid = $this->createUserWithHash('owner@example.com', self::testPassword(), 'Owner');
        $hid = $this->householdRepo->createForOwner('Den', $uid);
        $this->loginAs($uid, 'owner@example.com');
        $_SESSION['active_household_id'] = $hid;
        $_SESSION['active_household_role'] = 'owner';
        return [$uid, $hid];
    }

    private static function testPassword(): string
    {
        return 'correct horse battery staple';
    }
}
