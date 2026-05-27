<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class ChoresControllerTest extends AppTestCase
{
    public function test_get_chores_renders_for_member(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/chores');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('chore', strtolower($response->body()));
    }

    public function test_get_chores_redirects_anon_to_login(): void
    {
        $response = $this->request('GET', '/chores');
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('location'));
    }

    public function test_get_chores_redirects_logged_in_no_household_to_setup(): void
    {
        $uid = $this->createUserWithHash('a@example.com', self::testPassword());
        $this->loginAs($uid, 'a@example.com');
        $response = $this->request('GET', '/chores');
        self::assertSame(302, $response->status());
        self::assertSame('/household/setup', $response->header('location'));
    }

    public function test_get_new_chore_form_has_member_dropdown(): void
    {
        [$uid] = $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/chores/new');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="assigned_to"', $response->body());
        // The owner is a member, so an option with their id should appear.
        self::assertStringContainsString('value="' . $uid . '"', $response->body());
    }

    public function test_post_chore_creates_and_redirects(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/chores', [
            'title' => 'Take out bins',
            'points' => '10',
            'due_at_local' => '2026-07-14T09:00',
            'assigned_to' => (string) $uid,
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/chores', $response->header('location'));
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chores WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'Take out bins'],
        );
        self::assertSame(1, $count);
    }

    public function test_post_chore_whitelist_forces_household_tz_and_drops_system_columns(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $this->request('POST', '/chores', [
            'title' => 'Sneaky',
            'points' => '5',
            'timezone' => 'America/New_York',   // must be ignored
            'created_by' => 99999,              // must be ignored
            'household_id' => 99999,            // must be ignored
            'schedule_id' => 99999,             // must be ignored
        ]);

        $row = $this->db->fetchOne(
            'SELECT * FROM chores WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'Sneaky'],
        );
        self::assertNotNull($row);
        self::assertSame('Pacific/Auckland', $row['timezone']);
        self::assertSame($uid, (int) $row['created_by']);
        self::assertNull($row['schedule_id']);
    }

    public function test_post_chore_assign_to_non_member_coerced_to_null(): void
    {
        [, $hid] = $this->signInAsHouseholdOwner();
        $stranger = $this->createUserWithHash('stranger@example.com', self::testPassword());

        $this->request('POST', '/chores', [
            'title' => 'Assign stranger',
            'points' => '0',
            'assigned_to' => (string) $stranger,
        ]);

        $row = $this->db->fetchOne(
            'SELECT assigned_to FROM chores WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'Assign stranger'],
        );
        self::assertNull($row['assigned_to']);
    }

    public function test_post_chore_non_numeric_assignee_coerced_to_null(): void
    {
        [, $hid] = $this->signInAsHouseholdOwner();
        $this->request('POST', '/chores', [
            'title' => 'Bad assignee',
            'points' => '0',
            'assigned_to' => 'abc',
        ]);

        $row = $this->db->fetchOne(
            'SELECT assigned_to FROM chores WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'Bad assignee'],
        );
        self::assertNotNull($row);
        self::assertNull($row['assigned_to']);
    }

    public function test_post_chore_rejects_blank_title(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/chores', ['title' => '', 'points' => '5']);
        self::assertSame(422, $response->status());
    }

    public function test_post_chore_rejects_negative_points_with_422_not_500(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/chores', ['title' => 'Neg', 'points' => '-5']);
        self::assertSame(422, $response->status());
    }

    public function test_post_chore_rejects_over_max_points(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/chores', ['title' => 'Huge', 'points' => '99999']);
        self::assertSame(422, $response->status());
    }

    public function test_post_chore_rejects_non_numeric_points(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/chores', ['title' => 'Words', 'points' => 'ten']);
        self::assertSame(422, $response->status());
    }

    public function test_post_chore_blank_points_defaults_to_zero(): void
    {
        [, $hid] = $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/chores', ['title' => 'No points', 'points' => '']);

        self::assertSame(303, $response->status());
        $row = $this->db->fetchOne(
            'SELECT points FROM chores WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'No points'],
        );
        self::assertSame(0, (int) $row['points']);
    }

    public function test_post_chore_allows_blank_due_date(): void
    {
        [, $hid] = $this->signInAsHouseholdOwner();
        // JSON null due (AppTestCase sends JSON by default) → stored NULL.
        $response = $this->request('POST', '/chores', [
            'title' => 'Someday',
            'points' => '0',
            'due_at_local' => null,
        ]);

        self::assertSame(303, $response->status());
        $row = $this->db->fetchOne(
            'SELECT due_at_local FROM chores WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'Someday'],
        );
        self::assertNull($row['due_at_local']);
    }

    public function test_update_chore_changes_title(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $id = $this->choreRepo->create($this->choreData($hid, $uid, ['title' => 'Old']));

        $response = $this->request('POST', "/chores/{$id}", ['title' => 'New', 'points' => '3']);

        self::assertSame(303, $response->status());
        self::assertSame('New', $this->choreRepo->findById($id)['title']);
    }

    public function test_mark_done_credits_assignee_on_board(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $id = $this->choreRepo->create($this->choreData($hid, $uid, [
            'title' => 'Dishes', 'points' => 10, 'assigned_to' => $uid,
        ]));

        $done = $this->request('POST', "/chores/{$id}/done");
        self::assertSame(303, $done->status());

        $response = $this->request('GET', '/chores');
        // The doer's 10 points should appear somewhere on the board.
        self::assertStringContainsString('10', $response->body());
        self::assertTrue($this->choreRepo->findById($id)['is_done']);
    }

    public function test_reopen_moves_chore_back_to_open(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $id = $this->choreRepo->create($this->choreData($hid, $uid, ['assigned_to' => $uid, 'points' => 10]));
        $this->choreRepo->markDone($id, $uid);

        $response = $this->request('POST', "/chores/{$id}/reopen");
        self::assertSame(303, $response->status());
        self::assertFalse($this->choreRepo->findById($id)['is_done']);
    }

    public function test_overdue_badge_in_household_timezone(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $now = new \DateTimeImmutable('now', $tz);
        // ±2 days from "now" in the household tz keeps clear of the midnight boundary.
        $past = $now->modify('-2 days')->format('Y-m-d H:i:00');
        $future = $now->modify('+2 days')->format('Y-m-d H:i:00');

        // Titles deliberately avoid the word "Overdue" so the badge count is unambiguous.
        $this->choreRepo->create($this->choreData($hid, $uid, ['title' => 'PastDueTask', 'due_at_local' => $past]));
        $this->choreRepo->create($this->choreData($hid, $uid, ['title' => 'FutureTask', 'due_at_local' => $future]));
        $donePast = $this->choreRepo->create($this->choreData($hid, $uid, ['title' => 'DonePastTask', 'due_at_local' => $past]));
        $this->choreRepo->markDone($donePast, $uid);

        $body = $this->request('GET', '/chores')->body();

        self::assertStringContainsString('PastDueTask', $body);
        // Exactly one "Overdue" badge: the single open, past-due chore. The future
        // chore and the done past-due chore are not flagged.
        self::assertSame(1, substr_count($body, 'Overdue'));
    }

    public function test_done_chore_appears_in_done_section_not_open_list(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $id = $this->choreRepo->create($this->choreData($hid, $uid, ['title' => 'FinishedTask']));
        $this->choreRepo->markDone($id, $uid);

        $body = $this->request('GET', '/chores')->body();
        // The Done section exists and the completed chore + a Reopen control are present.
        self::assertStringContainsString('FinishedTask', $body);
        self::assertStringContainsString('reopen', strtolower($body));
    }

    public function test_delete_chore(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $id = $this->choreRepo->create($this->choreData($hid, $uid));

        $response = $this->request('POST', "/chores/{$id}/delete");
        self::assertSame(303, $response->status());
        self::assertNull($this->choreRepo->findById($id));
    }

    public function test_chore_in_other_household_returns_404(): void
    {
        $this->signInAsHouseholdOwner();
        // A chore that belongs to a different household.
        $otherOwner = $this->createUserWithHash('other@example.com', self::testPassword());
        $otherHid = $this->householdRepo->createForOwner('Other Den', $otherOwner);
        $id = $this->choreRepo->create($this->choreData($otherHid, $otherOwner));

        $response = $this->request('GET', "/chores/{$id}");
        self::assertSame(404, $response->status());
    }

    public function test_non_member_cannot_create_chore(): void
    {
        // Stranger has a session pointing at a household they're not a member of.
        $strangerId = $this->createUserWithHash('stranger@example.com', self::testPassword());
        $ownerId = $this->createUserWithHash('owner@example.com', self::testPassword());
        $hid = $this->householdRepo->createForOwner('Owner Den', $ownerId);
        $this->loginAs($strangerId, 'stranger@example.com');
        $this->activateHouseholdInSession($strangerId, $hid, 'member');

        $response = $this->request('POST', '/chores', ['title' => 'Intrude', 'points' => '0']);
        self::assertContains($response->status(), [302, 403]);
    }

    public function test_get_chores_materialises_active_schedule_occurrences(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        // A daily schedule anchored today; visiting /chores should generate occurrences.
        $tz = new \DateTimeZone('Pacific/Auckland');
        $anchor = (new \DateTimeImmutable('now', $tz))->format('Y-m-d') . ' 09:00:00';
        $this->scheduleRepo->create([
            'household_id' => $hid, 'created_by' => $uid, 'title' => 'Daily standup',
            'description' => '', 'points' => 1, 'rrule' => 'FREQ=DAILY',
            'anchor_at_local' => $anchor, 'timezone' => 'Pacific/Auckland',
            'assignment_mode' => 'rotate', 'fixed_user_id' => null,
        ]);

        $before = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM chores WHERE household_id = :h', ['h' => $hid]);
        $this->request('GET', '/chores');
        $after = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM chores WHERE household_id = :h', ['h' => $hid]);

        self::assertGreaterThan($before, $after, 'visiting /chores materialises occurrences');
    }

    public function test_get_chores_generation_is_idempotent_across_views(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $anchor = (new \DateTimeImmutable('now', $tz))->format('Y-m-d') . ' 09:00:00';
        $this->scheduleRepo->create([
            'household_id' => $hid, 'created_by' => $uid, 'title' => 'Daily standup',
            'description' => '', 'points' => 1, 'rrule' => 'FREQ=DAILY',
            'anchor_at_local' => $anchor, 'timezone' => 'Pacific/Auckland',
            'assignment_mode' => 'rotate', 'fixed_user_id' => null,
        ]);

        $this->request('GET', '/chores');
        $afterFirst = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM chores WHERE household_id = :h', ['h' => $hid]);
        $this->request('GET', '/chores');
        $afterSecond = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM chores WHERE household_id = :h', ['h' => $hid]);

        self::assertSame($afterFirst, $afterSecond, 'a second visit creates no duplicate occurrences');
    }

    public function test_deleting_a_generated_occurrence_does_not_regenerate_it(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $tz = new \DateTimeZone('Pacific/Auckland');
        $anchor = (new \DateTimeImmutable('now', $tz))->format('Y-m-d') . ' 09:00:00';
        $this->scheduleRepo->create([
            'household_id' => $hid, 'created_by' => $uid, 'title' => 'Daily standup',
            'description' => '', 'points' => 1, 'rrule' => 'FREQ=DAILY',
            'anchor_at_local' => $anchor, 'timezone' => 'Pacific/Auckland',
            'assignment_mode' => 'rotate', 'fixed_user_id' => null,
        ]);

        $this->request('GET', '/chores');
        $generated = (int) $this->db->fetchScalar('SELECT id FROM chores WHERE household_id = :h ORDER BY id ASC LIMIT 1', ['h' => $hid]);
        $this->request('POST', "/chores/{$generated}/delete");

        // Re-visiting must NOT recreate the deleted occurrence (watermark holds).
        $this->request('GET', '/chores');
        self::assertNull($this->choreRepo->findById($generated));
        $stillGone = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM chores WHERE id = :id',
            ['id' => $generated],
        );
        self::assertSame(0, $stillGone);
    }

    /** @return array{0: int, 1: int} */
    private function signInAsHouseholdOwner(): array
    {
        $uid = $this->createUserWithHash('a@example.com', self::testPassword());
        $hid = $this->householdRepo->createForOwner('Test Den', $uid);
        $this->loginAs($uid, 'a@example.com');
        $this->activateHouseholdInSession($uid, $hid, 'owner');
        return [$uid, $hid];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function choreData(int $hid, int $uid, array $overrides = []): array
    {
        return $overrides + [
            'household_id' => $hid,
            'created_by' => $uid,
            'title' => 'Test chore',
            'description' => '',
            'points' => 0,
            'due_at_local' => '2026-07-14 09:00:00',
            'assigned_to' => null,
            'timezone' => 'Pacific/Auckland',
        ];
    }

    private static function testPassword(): string
    {
        return 'correct horse battery staple';
    }
}
