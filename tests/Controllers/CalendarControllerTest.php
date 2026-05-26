<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Tests\AppTestCase;

final class CalendarControllerTest extends AppTestCase
{
    public function test_get_calendar_renders_month_grid_for_logged_in_member(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/calendar');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('calendar', strtolower($response->body()));
    }

    public function test_get_calendar_respects_ym_query_param(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/calendar?ym=2026-12');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('December', $response->body());
    }

    public function test_get_calendar_redirects_anon_to_login(): void
    {
        $response = $this->request('GET', '/calendar');
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('location'));
    }

    public function test_get_calendar_redirects_logged_in_no_household_to_setup(): void
    {
        $uid = $this->createUserWithHash('a@example.com', self::testPassword());
        $this->loginAs($uid, 'a@example.com');
        // No active_household_id in session
        $response = $this->request('GET', '/calendar');
        self::assertSame(302, $response->status());
        self::assertSame('/household/setup', $response->header('location'));
    }

    public function test_get_agenda_renders_for_member(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/calendar/agenda?ym=2026-07');
        self::assertSame(200, $response->status());
    }

    public function test_get_new_event_form_renders(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('GET', '/calendar/events/new');
        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="title"', $response->body());
        self::assertStringContainsString('name="starts_at_local"', $response->body());
    }

    public function test_post_event_creates_and_redirects(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();

        $response = $this->request('POST', '/calendar/events', [
            'title' => 'School pickup',
            'description' => 'Afternoon',
            'location' => 'School gate',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T15:30',
        ]);

        self::assertSame(303, $response->status());
        self::assertStringStartsWith('/calendar', $response->header('location'));

        // Verify the row landed in the household
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM events WHERE household_id = :hid AND title = :title',
            ['hid' => $hid, 'title' => 'School pickup'],
        );
        self::assertSame(1, $count);
    }

    public function test_post_event_whitelist_filters_series_event_id_and_timezone(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();

        $this->request('POST', '/calendar/events', [
            'title' => 'Sneaky',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T15:30',
            // These should be silently dropped by the whitelist
            'series_event_id' => 99999,
            'timezone' => 'America/New_York',
            'created_by' => 99999,
        ]);

        $row = $this->db->fetchOne(
            'SELECT timezone, series_event_id, created_by FROM events WHERE household_id = :hid',
            ['hid' => $hid],
        );
        self::assertSame('Pacific/Auckland', $row['timezone']);  // household tz, not the supplied
        self::assertNull($row['series_event_id']);
        self::assertSame($uid, (int) $row['created_by']);  // session user, not the supplied
    }

    public function test_post_event_rejects_invalid_datetime(): void
    {
        $this->signInAsHouseholdOwner();
        $response = $this->request('POST', '/calendar/events', [
            'title' => 'X',
            'starts_at_local' => 'not-a-date',
            'ends_at_local' => 'also-not',
        ]);
        self::assertSame(422, $response->status());
    }

    public function test_update_event_optimistic_concurrency_returns_409_on_stale(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createEvent($hid, $uid, ['title' => 'Original']);

        $response = $this->request('POST', "/calendar/events/{$eid}", [
            'title' => 'Updated',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T15:30',
            '_expected_updated_at' => '1999-01-01 00:00:00',  // stale
        ]);

        self::assertSame(409, $response->status());
        self::assertStringContainsString('refresh', strtolower($response->body()));
    }

    public function test_update_event_happy_path(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createEvent($hid, $uid, ['title' => 'Original']);
        $current = $this->db->fetchOne('SELECT updated_at FROM events WHERE id = :id', ['id' => $eid]);

        $response = $this->request('POST', "/calendar/events/{$eid}", [
            'title' => 'Updated title',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T15:30',
            '_expected_updated_at' => $current['updated_at'],
        ]);

        self::assertSame(303, $response->status());
        $updated = $this->db->fetchOne('SELECT title FROM events WHERE id = :id', ['id' => $eid]);
        self::assertSame('Updated title', $updated['title']);
    }

    public function test_post_event_with_weekly_recurrence_sets_rrule(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();

        $response = $this->request('POST', '/calendar/events', [
            'title' => 'Soccer',
            'starts_at_local' => '2026-07-07T18:00',  // a Tuesday
            'ends_at_local' => '2026-07-07T19:00',
            'recurrence_preset' => 'weekly',
            'byday' => ['TU'],
        ]);

        self::assertSame(303, $response->status());

        $rrule = $this->db->fetchScalar(
            'SELECT rrule FROM events WHERE household_id = :hid AND title = :title',
            ['hid' => $hid, 'title' => 'Soccer'],
        );
        self::assertSame('FREQ=WEEKLY;BYDAY=TU', $rrule);
    }

    public function test_post_event_with_no_recurrence_leaves_rrule_null(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();

        $this->request('POST', '/calendar/events', [
            'title' => 'One-off',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T16:00',
            'recurrence_preset' => 'none',
        ]);

        $rrule = $this->db->fetchScalar(
            "SELECT rrule FROM events WHERE household_id = :hid AND title = 'One-off'",
            ['hid' => $hid],
        );
        self::assertNull($rrule);
    }

    public function test_month_grid_renders_each_occurrence_of_recurring_event(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        // Start on the first Tuesday so all four Tuesdays of July 2026 are expanded.
        $eid = $this->createEvent($hid, $uid, [
            'title' => 'Weekly meeting',
            'starts_at_local' => '2026-07-07 15:00:00',
            'ends_at_local' => '2026-07-07 16:00:00',
        ]);
        $this->db->run("UPDATE events SET rrule = 'FREQ=WEEKLY;BYDAY=TU' WHERE id = :id", ['id' => $eid]);

        $response = $this->request('GET', '/calendar?ym=2026-07');

        // Tuesdays in July 2026: 7, 14, 21, 28 → "Weekly meeting" should appear 4×
        self::assertSame(4, substr_count($response->body(), 'Weekly meeting'));
    }

    public function test_delete_event(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createEvent($hid, $uid);

        $response = $this->request('POST', "/calendar/events/{$eid}/delete");
        self::assertSame(303, $response->status());

        $count = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM events WHERE id = :id', ['id' => $eid]);
        self::assertSame(0, $count);
    }

    // ---- v0.3.1 single-occurrence routes ----

    public function test_get_occurrence_edit_form_renders_series_defaults_for_clean_occurrence(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createRecurringEvent($hid, $uid);

        // Tue 14 Jul 2026 — second occurrence of the series. No exception yet.
        $response = $this->request('GET', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00/edit");

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Soccer', $response->body());
        self::assertStringContainsString('18:00', $response->body());
    }

    public function test_get_occurrence_edit_with_malformed_slug_returns_404(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createRecurringEvent($hid, $uid);

        $response = $this->request('GET', "/calendar/events/{$eid}/occurrences/not-a-date/edit");
        self::assertSame(404, $response->status());
    }

    public function test_get_occurrence_edit_with_non_matching_slug_returns_404(): void
    {
        // Slug is well-formed but no occurrence at that time
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createRecurringEvent($hid, $uid);

        $response = $this->request('GET', "/calendar/events/{$eid}/occurrences/2099-01-01T09-00/edit");
        self::assertSame(404, $response->status());
    }

    public function test_post_occurrence_creates_override_for_clean_occurrence(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createRecurringEvent($hid, $uid);

        $response = $this->request('POST', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00", [
            'title' => 'Moved to 7pm',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14T19:00',
            'ends_at_local' => '2026-07-14T20:00',
        ]);

        self::assertSame(303, $response->status());
        // Exactly one override Event row + one exception pointing at it
        $exceptions = $this->db->fetchAll('SELECT * FROM event_exceptions WHERE event_id = :e', ['e' => $eid]);
        self::assertCount(1, $exceptions);
        self::assertNotNull($exceptions[0]['override_event_id']);
        $override = $this->db->fetchOne('SELECT title FROM events WHERE id = :id', ['id' => $exceptions[0]['override_event_id']]);
        self::assertSame('Moved to 7pm', $override['title']);
    }

    public function test_post_occurrence_cancel_inserts_exception_row(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createRecurringEvent($hid, $uid);

        $response = $this->request('POST', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00/cancel");

        self::assertSame(303, $response->status());
        $row = $this->db->fetchOne(
            'SELECT override_event_id FROM event_exceptions WHERE event_id = :e',
            ['e' => $eid],
        );
        self::assertNotNull($row);
        self::assertNull($row['override_event_id']);
    }

    public function test_post_occurrence_cancel_is_idempotent(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createRecurringEvent($hid, $uid);

        $this->request('POST', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00/cancel");
        $this->request('POST', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00/cancel");  // no error

        $count = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM event_exceptions WHERE event_id = :e', ['e' => $eid]);
        self::assertSame(1, $count);
    }

    public function test_post_occurrence_restore_removes_cancellation(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createRecurringEvent($hid, $uid);

        $this->request('POST', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00/cancel");

        $response = $this->request('POST', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00/restore");

        self::assertSame(303, $response->status());
        $count = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM event_exceptions WHERE event_id = :e', ['e' => $eid]);
        self::assertSame(0, $count);
    }

    public function test_post_occurrence_restore_drops_override_event_too(): void
    {
        // Restoring an OVERRIDDEN occurrence must also delete the override
        // Event row (the round-3 two-step DELETE pattern).
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createRecurringEvent($hid, $uid);

        $this->request('POST', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00", [
            'title' => 'Moved',
            'starts_at_local' => '2026-07-14T19:00',
            'ends_at_local' => '2026-07-14T20:00',
        ]);
        $overrideId = (int) $this->db->fetchScalar(
            "SELECT override_event_id FROM event_exceptions WHERE event_id = :e",
            ['e' => $eid],
        );

        $this->request('POST', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00/restore");

        self::assertNull($this->eventRepo->findById($overrideId));
        self::assertSame(
            0,
            (int) $this->db->fetchScalar('SELECT COUNT(*) FROM event_exceptions WHERE event_id = :e', ['e' => $eid]),
        );
    }

    public function test_get_occurrence_edit_loads_existing_override(): void
    {
        [$uid, $hid] = $this->signInAsHouseholdOwner();
        $eid = $this->createRecurringEvent($hid, $uid);

        // Set up an existing override
        $this->request('POST', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00", [
            'title' => 'Already moved',
            'starts_at_local' => '2026-07-14T19:30',
            'ends_at_local' => '2026-07-14T20:30',
        ]);

        $response = $this->request('GET', "/calendar/events/{$eid}/occurrences/2026-07-14T18-00/edit");

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Already moved', $response->body());
        self::assertStringContainsString('19:30', $response->body());
    }

    public function test_non_member_cannot_create_event(): void
    {
        // Owner A creates a household; user B is not a member but somehow has an
        // active_household_id session pointing at that household.
        $ownerId = $this->createUserWithHash('owner@example.com', self::testPassword());
        $hid = $this->householdRepo->createForOwner('Test Den', $ownerId);

        $strangerId = $this->createUserWithHash('stranger@example.com', self::testPassword());
        $this->loginAs($strangerId, 'stranger@example.com');
        $this->activateHouseholdInSession($strangerId, $hid, 'member');  // fake the session

        $response = $this->request('POST', '/calendar/events', [
            'title' => 'sneaky',
            'starts_at_local' => '2026-07-14T15:00',
            'ends_at_local' => '2026-07-14T15:30',
        ]);

        // requireMember throws ForbiddenException; karhu's ExceptionHandler returns 403
        // (with redirectTo='/household/setup' because the stranger's session matches the
        // household they're not in — so it actually 302s for stale-session self-heal).
        self::assertContains($response->status(), [302, 403]);
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
     */
    private function createEvent(int $hid, int $uid, array $overrides = []): int
    {
        return $this->eventRepo->create($overrides + [
            'household_id' => $hid,
            'created_by' => $uid,
            'title' => 'Test event',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 16:00:00',
            'timezone' => 'Pacific/Auckland',
            'all_day' => false,
        ]);
    }

    /**
     * Helper: a weekly Tuesday series anchored 2026-07-07 6pm.
     * Occurrences for the test month: 7, 14, 21, 28.
     */
    private function createRecurringEvent(int $hid, int $uid): int
    {
        $eid = $this->createEvent($hid, $uid, [
            'title' => 'Soccer',
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
        ]);
        $this->db->run("UPDATE events SET rrule = 'FREQ=WEEKLY;BYDAY=TU' WHERE id = :id", ['id' => $eid]);
        return $eid;
    }

    private static function testPassword(): string
    {
        return 'correct horse battery staple';
    }
}
