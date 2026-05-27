<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class ChoreSchedulesControllerTest extends AppTestCase
{
    public function test_get_new_schedule_form_renders_recurrence_fieldset(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/chores/schedules/new');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="recurrence_preset"', $response->body());
        self::assertStringContainsString('name="assignment_mode"', $response->body());
    }

    public function test_get_new_redirects_anon_to_login(): void
    {
        $response = $this->request('GET', '/chores/schedules/new');
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('location'));
    }

    public function test_post_create_persists_schedule_with_translated_rrule(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/chores/schedules', [
            'title' => 'Take out bins',
            'points' => '10',
            'anchor_date' => '2026-06-02',
            'due_time' => '18:00',
            'recurrence_preset' => 'weekly',
            'recurrence_byday' => ['TU'],
            'assignment_mode' => 'rotate',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/chores', $response->header('location'));
        $row = $this->db->fetchOne(
            'SELECT * FROM chore_schedules WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'Take out bins'],
        );
        self::assertNotNull($row);
        self::assertStringContainsString('FREQ=WEEKLY', $row['rrule']);
        self::assertStringContainsString('BYDAY=TU', $row['rrule']);
        self::assertSame('2026-06-02 18:00:00', $row['anchor_at_local']);
        self::assertSame('Pacific/Auckland', $row['timezone']);
    }

    public function test_post_create_preset_none_is_422(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/chores/schedules', [
            'title' => 'Not recurring',
            'anchor_date' => '2026-06-02',
            'due_time' => '18:00',
            'recurrence_preset' => 'none',
        ]);
        self::assertSame(422, $response->status());
    }

    public function test_post_create_fixed_mode_persists_pinned_member(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $this->request('POST', '/chores/schedules', [
            'title' => 'Pay rent',
            'points' => '0',
            'anchor_date' => '2026-06-01',
            'due_time' => '09:00',
            'recurrence_preset' => 'monthly',
            'recurrence_monthly_day' => '1',
            'assignment_mode' => 'fixed',
            'fixed_user_id' => (string) $uid,
        ]);

        $row = $this->db->fetchOne(
            'SELECT * FROM chore_schedules WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'Pay rent'],
        );
        self::assertSame('fixed', $row['assignment_mode']);
        self::assertSame($uid, (int) $row['fixed_user_id']);
    }

    public function test_post_create_fixed_mode_non_member_is_422(): void
    {
        $this->signInAsHouseholdOwner();
        $stranger = $this->createUserWithHash('stranger@example.com', self::testPassword());
        $response = $this->request('POST', '/chores/schedules', [
            'title' => 'Bad pin',
            'anchor_date' => '2026-06-01',
            'due_time' => '09:00',
            'recurrence_preset' => 'daily',
            'assignment_mode' => 'fixed',
            'fixed_user_id' => (string) $stranger,
        ]);
        self::assertSame(422, $response->status());
    }

    public function test_edit_form_repopulates_preset(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $sid = $this->makeSchedule($hid, $uid, ['rrule' => 'FREQ=WEEKLY;BYDAY=TU']);

        $response = $this->request('GET', "/chores/schedules/{$sid}");
        self::assertSame(200, $response->status());
        // The weekly preset option should be selected.
        self::assertMatchesRegularExpression('/value="weekly"\s+selected/', $response->body());
    }

    public function test_edit_refreshes_upcoming_occurrences(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $sid = $this->makeSchedule($hid, $uid, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-01 09:00:00']);

        // Seed a future-open generated instance + a completed one, both linked to the schedule.
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Auckland')))->modify('+3 days')->format('Y-m-d H:i:00');
        $openId = $this->choreRepo->createGenerated([
            'household_id' => $hid, 'created_by' => $uid, 'schedule_id' => $sid,
            'occurrence_date' => $future, 'due_at_local' => $future, 'assigned_to' => $uid,
            'title' => 'bins', 'points' => 5, 'timezone' => 'Pacific/Auckland',
        ]);
        $doneId = $this->choreRepo->createGenerated([
            'household_id' => $hid, 'created_by' => $uid, 'schedule_id' => $sid,
            'occurrence_date' => '2026-06-01 09:00:00', 'due_at_local' => '2026-06-01 09:00:00',
            'assigned_to' => $uid, 'title' => 'bins', 'points' => 5, 'timezone' => 'Pacific/Auckland',
        ]);
        $this->choreRepo->markDone($doneId, $uid);

        $this->request('POST', "/chores/schedules/{$sid}", [
            'title' => 'bins',
            'points' => '5',
            'anchor_date' => '2026-06-01',
            'due_time' => '09:00',
            'recurrence_preset' => 'daily',
            'assignment_mode' => 'rotate',
        ]);

        // Future-open instance deleted; completed instance kept.
        self::assertNull($this->choreRepo->findById($openId));
        self::assertNotNull($this->choreRepo->findById($doneId));
    }

    public function test_delete_drops_open_keeps_completed_and_removes_schedule(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $sid = $this->makeSchedule($hid, $uid, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-01 09:00:00']);
        $openId = $this->choreRepo->createGenerated([
            'household_id' => $hid, 'created_by' => $uid, 'schedule_id' => $sid,
            'occurrence_date' => '2026-06-05 09:00:00', 'due_at_local' => '2026-06-05 09:00:00',
            'assigned_to' => $uid, 'title' => 'bins', 'points' => 5, 'timezone' => 'Pacific/Auckland',
        ]);
        $doneId = $this->choreRepo->createGenerated([
            'household_id' => $hid, 'created_by' => $uid, 'schedule_id' => $sid,
            'occurrence_date' => '2026-06-01 09:00:00', 'due_at_local' => '2026-06-01 09:00:00',
            'assigned_to' => $uid, 'title' => 'bins', 'points' => 5, 'timezone' => 'Pacific/Auckland',
        ]);
        $this->choreRepo->markDone($doneId, $uid);

        $response = $this->request('POST', "/chores/schedules/{$sid}/delete");

        self::assertSame(303, $response->status());
        self::assertNull($this->scheduleRepo->findById($sid));
        self::assertNull($this->choreRepo->findById($openId));          // open dropped
        $kept = $this->choreRepo->findById($doneId);
        self::assertNotNull($kept);                                      // completed kept
        self::assertNull($kept['schedule_id']);                          // but detached
    }

    public function test_cross_household_schedule_returns_404(): void
    {
        $this->signInAsHouseholdOwner();
        $otherOwner = $this->createUserWithHash('other@example.com', self::testPassword());
        $otherHid = $this->householdRepo->createForOwner('Other Den', $otherOwner);
        $sid = $this->makeSchedule($otherHid, $otherOwner, []);

        $response = $this->request('GET', "/chores/schedules/{$sid}");
        self::assertSame(404, $response->status());
    }

    public function test_non_member_cannot_create_schedule(): void
    {
        $strangerId = $this->createUserWithHash('stranger@example.com', self::testPassword());
        $ownerId = $this->createUserWithHash('owner@example.com', self::testPassword());
        $hid = $this->householdRepo->createForOwner('Owner Den', $ownerId);
        $this->loginAs($strangerId, 'stranger@example.com');
        $this->activateHouseholdInSession($strangerId, $hid, 'member');

        $response = $this->request('POST', '/chores/schedules', [
            'title' => 'Intrude', 'anchor_date' => '2026-06-01', 'due_time' => '09:00',
            'recurrence_preset' => 'daily',
        ]);
        self::assertContains($response->status(), [302, 403]);
    }

    public function test_pause_then_resume_toggles_and_resume_rewinds_watermark(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $sid = $this->makeSchedule($hid, $uid, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-01 09:00:00']);
        $this->scheduleRepo->setGeneratedThrough($sid, '2026-06-01 09:00:00');

        $pause = $this->request('POST', "/chores/schedules/{$sid}/pause");
        self::assertSame(303, $pause->status());
        self::assertTrue($this->scheduleRepo->isPaused($sid));

        $resume = $this->request('POST', "/chores/schedules/{$sid}/resume");
        self::assertSame(303, $resume->status());
        self::assertFalse($this->scheduleRepo->isPaused($sid));
        // Resume rewinds the watermark forward (away from the old anchor) so no backlog.
        self::assertNotSame('2026-06-01 09:00:00', $this->scheduleRepo->findById($sid)['generated_through']);
    }

    public function test_pause_on_cross_household_schedule_404s(): void
    {
        $this->signInAsHouseholdOwner();
        $otherOwner = $this->createUserWithHash('other2@example.com', self::testPassword());
        $otherHid = $this->householdRepo->createForOwner('Other Den', $otherOwner);
        $sid = $this->makeSchedule($otherHid, $otherOwner, []);

        $response = $this->request('POST', "/chores/schedules/{$sid}/pause");
        self::assertSame(404, $response->status());
    }

    public function test_create_rotate_persists_participant_pool(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $bob = $this->createUserWithHash('bob@example.com', self::testPassword());
        $this->householdRepo->addMember($hid, $bob);

        $this->request('POST', '/chores/schedules', [
            'title' => 'Bins',
            'points' => '5',
            'anchor_date' => '2026-06-02',
            'due_time' => '18:00',
            'recurrence_preset' => 'weekly',
            'recurrence_byday' => ['TU'],
            'assignment_mode' => 'rotate',
            'participants' => [(string) $uid, (string) $bob],
        ]);

        $sid = (int) $this->db->fetchScalar(
            'SELECT id FROM chore_schedules WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'Bins'],
        );
        self::assertEqualsCanonicalizing([$uid, $bob], $this->scheduleRepo->listParticipantIds($sid));
    }

    public function test_create_fixed_mode_clears_any_pool(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();

        $this->request('POST', '/chores/schedules', [
            'title' => 'Rent',
            'points' => '0',
            'anchor_date' => '2026-06-01',
            'due_time' => '09:00',
            'recurrence_preset' => 'monthly',
            'recurrence_monthly_day' => '1',
            'assignment_mode' => 'fixed',
            'fixed_user_id' => (string) $uid,
            'participants' => [(string) $uid],  // ignored in fixed mode
        ]);

        $sid = (int) $this->db->fetchScalar(
            'SELECT id FROM chore_schedules WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'Rent'],
        );
        self::assertSame([], $this->scheduleRepo->listParticipantIds($sid));
    }

    public function test_create_drops_non_member_participant(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $stranger = $this->createUserWithHash('stranger3@example.com', self::testPassword());

        $this->request('POST', '/chores/schedules', [
            'title' => 'Bins',
            'points' => '0',
            'anchor_date' => '2026-06-02',
            'due_time' => '18:00',
            'recurrence_preset' => 'daily',
            'assignment_mode' => 'rotate',
            'participants' => [(string) $uid, (string) $stranger],
        ]);

        $sid = (int) $this->db->fetchScalar(
            'SELECT id FROM chore_schedules WHERE household_id = :h AND title = :t',
            ['h' => $hid, 't' => 'Bins'],
        );
        self::assertSame([$uid], $this->scheduleRepo->listParticipantIds($sid));  // stranger dropped
    }

    public function test_edit_form_repopulates_participant_checkboxes(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $bob = $this->createUserWithHash('bob2@example.com', self::testPassword());
        $this->householdRepo->addMember($hid, $bob);
        $sid = $this->makeSchedule($hid, $uid, ['rrule' => 'FREQ=DAILY', 'anchor_at_local' => '2026-06-01 09:00:00']);
        $this->scheduleRepo->setParticipants($sid, [$bob]);

        $body = $this->request('GET', "/chores/schedules/{$sid}")->body();

        // Bob's participant checkbox is checked; the value + checked attr are present.
        self::assertMatchesRegularExpression('/name="participants\[\]" value="' . $bob . '"\s+checked/', $body);
    }

    // --- helpers ---

    /** @return array{0: int, 1: int} */
    private function signInAsHouseholdOwner(): array
    {
        $uid = $this->createUserWithHash('a@example.com', self::testPassword());
        $hid = $this->householdRepo->createForOwner('Test Den', $uid);
        $this->loginAs($uid, 'a@example.com');
        $this->activateHouseholdInSession($uid, $hid, 'owner');
        return [$uid, $hid];
    }

    /** @param array<string, mixed> $overrides */
    private function makeSchedule(int $hid, int $uid, array $overrides): int
    {
        return $this->scheduleRepo->create($overrides + [
            'household_id' => $hid,
            'created_by' => $uid,
            'title' => 'Take out bins',
            'description' => '',
            'points' => 5,
            'rrule' => 'FREQ=DAILY',
            'anchor_at_local' => '2026-06-01 09:00:00',
            'timezone' => 'Pacific/Auckland',
            'assignment_mode' => 'rotate',
            'fixed_user_id' => null,
        ]);
    }

    private static function testPassword(): string
    {
        return 'correct horse battery staple';
    }
}
