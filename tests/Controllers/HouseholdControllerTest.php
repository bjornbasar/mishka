<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class HouseholdControllerTest extends AppTestCase
{
    public function test_get_setup_renders_form_when_logged_in_with_no_household(): void
    {
        $userId = $this->createUserWithHash('a@example.com', 'pw-correct-horse-staple');
        $this->loginAs($userId, 'a@example.com');

        $response = $this->request('GET', '/household/setup');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Set up your household', $response->body());
        self::assertStringContainsString('name="action"', $response->body());
    }

    public function test_get_setup_redirects_to_household_when_already_active(): void
    {
        $userId = $this->createUserWithHash('a@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Existing', $userId);
        $this->loginAs($userId, 'a@example.com');
        $this->activateHouseholdInSession($userId, $hid, 'owner');

        $response = $this->request('GET', '/household/setup');

        self::assertSame(302, $response->status());
        self::assertSame('/household', $response->header('location'));
    }

    public function test_post_setup_create_sets_session_and_writes_preference(): void
    {
        $userId = $this->createUserWithHash('a@example.com', 'pw-correct-horse-staple');
        $this->loginAs($userId, 'a@example.com');

        $response = $this->request('POST', '/household/setup', [
            'action' => 'create',
            'name' => 'My Den',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/', $response->header('location'));
        self::assertGreaterThan(0, $_SESSION['active_household_id'] ?? 0);
        self::assertSame('owner', $_SESSION['active_household_role']);
        self::assertSame(
            $_SESSION['active_household_id'],
            $this->prefsRepo->getLastHouseholdId($userId),
        );
    }

    public function test_post_setup_create_requires_name(): void
    {
        $userId = $this->createUserWithHash('a@example.com', 'pw-correct-horse-staple');
        $this->loginAs($userId, 'a@example.com');

        $response = $this->request('POST', '/household/setup', [
            'action' => 'create',
            'name' => '',
        ]);

        self::assertSame(422, $response->status());
        self::assertArrayNotHasKey('active_household_id', $_SESSION);
    }

    public function test_post_setup_join_adds_member_to_existing_household(): void
    {
        // Owner sets up the household
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('The Cabin', $ownerId);
        $code = $this->householdRepo->findById($hid)['join_code'];

        // Second user joins
        $joinerId = $this->createUserWithHash('joiner@example.com', 'pw-correct-horse-staple');
        $this->loginAs($joinerId, 'joiner@example.com');

        $response = $this->request('POST', '/household/setup', [
            'action' => 'join',
            'join_code' => $code,
        ]);

        self::assertSame(303, $response->status());
        self::assertSame($hid, $_SESSION['active_household_id']);
        self::assertSame('member', $_SESSION['active_household_role']);
        self::assertTrue($this->householdRepo->isMember($joinerId, $hid));
    }

    public function test_post_setup_join_with_bad_code_returns_422(): void
    {
        $userId = $this->createUserWithHash('a@example.com', 'pw-correct-horse-staple');
        $this->loginAs($userId, 'a@example.com');

        $response = $this->request('POST', '/household/setup', [
            'action' => 'join',
            'join_code' => 'NOSUCHCODE',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('No household found', $response->body());
    }

    public function test_get_household_shows_invite_code_to_owner(): void
    {
        $userId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('The Den', $userId);
        $this->loginAs($userId, 'owner@example.com');
        $this->activateHouseholdInSession($userId, $hid, 'owner');

        $code = $this->householdRepo->findById($hid)['join_code'];

        $response = $this->request('GET', '/household');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('The Den', $response->body());
        self::assertStringContainsString($code, $response->body());
        self::assertStringContainsString('Rename household', $response->body());
    }

    public function test_get_household_hides_invite_code_from_non_owner(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('The Den', $ownerId);

        $memberId = $this->createUserWithHash('member@example.com', 'pw-correct-horse-staple');
        $this->householdRepo->addMember($hid, $memberId);
        $this->loginAs($memberId, 'member@example.com');
        $this->activateHouseholdInSession($memberId, $hid, 'member');

        $code = $this->householdRepo->findById($hid)['join_code'];

        $response = $this->request('GET', '/household');

        self::assertSame(200, $response->status());
        self::assertStringNotContainsString($code, $response->body());
        self::assertStringNotContainsString('Rename household', $response->body());
    }

    public function test_post_rename_works_for_owner(): void
    {
        $userId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Old Name', $userId);
        $this->loginAs($userId, 'owner@example.com');
        $this->activateHouseholdInSession($userId, $hid, 'owner');

        $response = $this->request('POST', '/household/rename', ['name' => 'New Name']);

        self::assertSame(303, $response->status());
        self::assertSame('New Name', $this->householdRepo->findById($hid)['name']);
    }

    public function test_post_rename_rejects_non_owner_with_403(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Old Name', $ownerId);

        $memberId = $this->createUserWithHash('member@example.com', 'pw-correct-horse-staple');
        $this->householdRepo->addMember($hid, $memberId);
        $this->loginAs($memberId, 'member@example.com');
        $this->activateHouseholdInSession($memberId, $hid, 'member');

        $response = $this->request('POST', '/household/rename', ['name' => 'Sneaky']);

        self::assertSame(403, $response->status());
        self::assertSame('Old Name', $this->householdRepo->findById($hid)['name']);
    }

    public function test_post_remove_member_works_for_owner(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Den', $ownerId);

        $memberId = $this->createUserWithHash('member@example.com', 'pw-correct-horse-staple');
        $this->householdRepo->addMember($hid, $memberId);

        $this->loginAs($ownerId, 'owner@example.com');
        $this->activateHouseholdInSession($ownerId, $hid, 'owner');

        $response = $this->request('POST', "/household/members/{$memberId}/remove");

        self::assertSame(303, $response->status());
        self::assertFalse($this->householdRepo->isMember($memberId, $hid));
    }

    public function test_post_remove_member_rejects_non_owner(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Den', $ownerId);
        $memberId = $this->createUserWithHash('member@example.com', 'pw-correct-horse-staple');
        $this->householdRepo->addMember($hid, $memberId);

        $this->loginAs($memberId, 'member@example.com');
        $this->activateHouseholdInSession($memberId, $hid, 'member');

        $response = $this->request('POST', "/household/members/{$ownerId}/remove");

        self::assertSame(403, $response->status());
        self::assertTrue($this->householdRepo->isOwner($ownerId, $hid));
    }

    public function test_owner_cannot_remove_self(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Den', $ownerId);
        $this->loginAs($ownerId, 'owner@example.com');
        $this->activateHouseholdInSession($ownerId, $hid, 'owner');

        $response = $this->request('POST', "/household/members/{$ownerId}/remove");

        self::assertSame(422, $response->status());
        self::assertTrue($this->householdRepo->isOwner($ownerId, $hid));
    }

    public function test_post_switch_changes_active_household_and_writes_preference(): void
    {
        $userId = $this->createUserWithHash('multi@example.com', 'pw-correct-horse-staple');
        $hid1 = $this->householdRepo->createForOwner('First', $userId);
        $hid2 = $this->householdRepo->createForOwner('Second', $userId);

        $this->loginAs($userId, 'multi@example.com');
        $this->activateHouseholdInSession($userId, $hid1, 'owner');

        $response = $this->request('POST', '/household/switch', ['household_id' => $hid2]);

        self::assertSame(303, $response->status());
        self::assertSame($hid2, $_SESSION['active_household_id']);
        self::assertSame($hid2, $this->prefsRepo->getLastHouseholdId($userId));
    }

    public function test_post_switch_to_non_member_household_is_forbidden(): void
    {
        $otherOwnerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $otherHid = $this->householdRepo->createForOwner('Strangers', $otherOwnerId);

        $userId = $this->createUserWithHash('me@example.com', 'pw-correct-horse-staple');
        $myHid = $this->householdRepo->createForOwner('Mine', $userId);

        $this->loginAs($userId, 'me@example.com');
        $this->activateHouseholdInSession($userId, $myHid, 'owner');

        $response = $this->request('POST', '/household/switch', ['household_id' => $otherHid]);

        self::assertSame(403, $response->status());
        self::assertSame($myHid, $_SESSION['active_household_id']);  // unchanged
    }

    // ============================================================
    // v0.5.0 — household lifecycle (regenerate / leave / transfer / delete)
    // ============================================================

    public function test_post_regenerate_code_as_owner_rotates_join_code(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Den', $ownerId);
        $before = (string) $this->householdRepo->findById($hid)['join_code'];
        $this->loginAs($ownerId, 'owner@example.com');
        $this->activateHouseholdInSession($ownerId, $hid, 'owner');

        $response = $this->request('POST', '/household/regenerate-code');

        self::assertSame(303, $response->status());
        self::assertSame('/household', $response->header('location'));
        $after = (string) $this->householdRepo->findById($hid)['join_code'];
        self::assertNotSame($before, $after);
    }

    public function test_post_regenerate_code_as_non_owner_is_forbidden(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $memberId = $this->createUserWithHash('member@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Den', $ownerId);
        $this->householdRepo->addMember($hid, $memberId);
        $this->loginAs($memberId, 'member@example.com');
        $this->activateHouseholdInSession($memberId, $hid, 'member');

        $response = $this->request('POST', '/household/regenerate-code');

        self::assertSame(403, $response->status());
    }

    public function test_post_leave_as_owner_returns_422(): void
    {
        // Owner can't leave — must transfer or delete first. Single-source-of-truth
        // is HouseholdRepository::removeMember (which throws on owner), but the
        // controller catches and returns a UX-friendly 422.
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Den', $ownerId);
        $this->loginAs($ownerId, 'owner@example.com');
        $this->activateHouseholdInSession($ownerId, $hid, 'owner');

        $response = $this->request('POST', '/household/leave');

        self::assertSame(422, $response->status());
        self::assertTrue($this->householdRepo->isMember($ownerId, $hid));  // still a member
    }

    public function test_post_leave_as_member_clears_session_and_redirects_to_setup(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $memberId = $this->createUserWithHash('member@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Den', $ownerId);
        $this->householdRepo->addMember($hid, $memberId);
        $this->loginAs($memberId, 'member@example.com');
        $this->activateHouseholdInSession($memberId, $hid, 'member');

        $response = $this->request('POST', '/household/leave');

        self::assertSame(303, $response->status());
        // No other memberships — redirect to /household/setup.
        self::assertSame('/household/setup', $response->header('location'));
        self::assertFalse($this->householdRepo->isMember($memberId, $hid));
        self::assertArrayNotHasKey('active_household_id', $_SESSION);
        self::assertArrayNotHasKey('active_household_role', $_SESSION);
    }

    public function test_post_transfer_swaps_ownership_atomically(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $targetId = $this->createUserWithHash('target@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Den', $ownerId);
        $this->householdRepo->addMember($hid, $targetId);
        $this->loginAs($ownerId, 'owner@example.com');
        $this->activateHouseholdInSession($ownerId, $hid, 'owner');

        $response = $this->request('POST', '/household/transfer', [
            'new_owner_user_id' => $targetId,
        ]);

        self::assertSame(303, $response->status());
        self::assertTrue($this->householdRepo->isOwner($targetId, $hid));
        self::assertFalse($this->householdRepo->isOwner($ownerId, $hid));
        // The acting user (now a member) must have their session role updated.
        self::assertSame('member', $_SESSION['active_household_role']);
    }

    public function test_post_transfer_to_non_member_returns_422(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $strangerId = $this->createUserWithHash('stranger@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Den', $ownerId);
        $this->loginAs($ownerId, 'owner@example.com');
        $this->activateHouseholdInSession($ownerId, $hid, 'owner');

        $response = $this->request('POST', '/household/transfer', [
            'new_owner_user_id' => $strangerId,
        ]);

        self::assertSame(422, $response->status());
        self::assertTrue($this->householdRepo->isOwner($ownerId, $hid));  // unchanged
    }

    public function test_post_transfer_as_non_owner_is_forbidden(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $memberId = $this->createUserWithHash('member@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('Den', $ownerId);
        $this->householdRepo->addMember($hid, $memberId);
        $this->loginAs($memberId, 'member@example.com');
        $this->activateHouseholdInSession($memberId, $hid, 'member');

        $response = $this->request('POST', '/household/transfer', [
            'new_owner_user_id' => $ownerId,
        ]);

        self::assertSame(403, $response->status());
    }

    public function test_post_delete_with_matching_name_cascades_child_tables(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('My Den', $ownerId);
        // Drop a chore in there so we can verify CASCADE actually wiped it.
        $this->choreRepo->create([
            'household_id' => $hid, 'created_by' => $ownerId, 'title' => 'Dishes',
            'description' => '', 'points' => 5, 'due_at_local' => null,
            'assigned_to' => $ownerId, 'timezone' => 'Pacific/Auckland',
        ]);
        $this->loginAs($ownerId, 'owner@example.com');
        $this->activateHouseholdInSession($ownerId, $hid, 'owner');

        $response = $this->request('POST', '/household/delete', [
            'confirm_name' => 'My Den',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/', $response->header('location'));
        self::assertNull($this->householdRepo->findById($hid));
        // CASCADE wiped chores too.
        $remaining = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chores WHERE household_id = :h',
            ['h' => $hid],
        );
        self::assertSame(0, $remaining);
        self::assertArrayNotHasKey('active_household_id', $_SESSION);
    }

    public function test_post_delete_with_wrong_name_returns_422(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('My Den', $ownerId);
        $this->loginAs($ownerId, 'owner@example.com');
        $this->activateHouseholdInSession($ownerId, $hid, 'owner');

        $response = $this->request('POST', '/household/delete', [
            'confirm_name' => 'Wrong Name',
        ]);

        self::assertSame(422, $response->status());
        self::assertNotNull($this->householdRepo->findById($hid));  // not deleted
    }

    public function test_post_delete_as_non_owner_is_forbidden(): void
    {
        $ownerId = $this->createUserWithHash('owner@example.com', 'pw-correct-horse-staple');
        $memberId = $this->createUserWithHash('member@example.com', 'pw-correct-horse-staple');
        $hid = $this->householdRepo->createForOwner('My Den', $ownerId);
        $this->householdRepo->addMember($hid, $memberId);
        $this->loginAs($memberId, 'member@example.com');
        $this->activateHouseholdInSession($memberId, $hid, 'member');

        $response = $this->request('POST', '/household/delete', [
            'confirm_name' => 'My Den',
        ]);

        self::assertSame(403, $response->status());
        self::assertNotNull($this->householdRepo->findById($hid));
    }
}
