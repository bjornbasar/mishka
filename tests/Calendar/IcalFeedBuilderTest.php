<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\EventExceptionRepository;
use App\Calendar\EventRepository;
use App\Calendar\IcalFeedBuilder;
use App\Household\HouseholdRepository;
use Karhu\Db\Connection;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/**
 * IcalFeedBuilder tests round-trip through sabre/vobject's parser so we
 * verify structural properties (RRULE present, RECURRENCE-ID present,
 * VTIMEZONE included) rather than string-contains the serialised output.
 * iCal whitespace + line folding shifts under any formatting tweak; the
 * parser is the authoritative shape oracle.
 */
final class IcalFeedBuilderTest extends TestCase
{
    private Connection $db;
    private IcalFeedBuilder $builder;
    private EventRepository $events;
    private EventExceptionRepository $exceptions;
    private HouseholdRepository $households;
    private int $uid;
    private int $hid;
    private string $tz = 'Pacific/Auckland';

    protected function setUp(): void
    {
        $this->db = $GLOBALS['test_db'];
        $this->db->pdo()->beginTransaction();

        $this->events = new EventRepository($this->db);
        $this->exceptions = new EventExceptionRepository($this->db);
        $this->households = new HouseholdRepository($this->db);
        $this->builder = new IcalFeedBuilder($this->events, $this->exceptions, $this->households);

        $this->uid = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES (:e, 'x', 'T') RETURNING id",
            ['e' => 'u-' . bin2hex(random_bytes(3)) . '@example.com'],
        );
        $this->hid = $this->households->createForOwner('Test Den', $this->uid, $this->tz);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function test_empty_feed_serialises_as_valid_vcalendar(): void
    {
        $output = $this->builder->renderForUser($this->uid);

        self::assertStringContainsString('BEGIN:VCALENDAR', $output);
        self::assertStringContainsString('END:VCALENDAR', $output);
        self::assertStringContainsString('PRODID:', $output);

        // Parses cleanly
        $parsed = Reader::read($output);
        self::assertNotNull($parsed->VCALENDAR ?? $parsed);
    }

    public function test_one_off_event_emits_vevent_with_summary_and_tzid(): void
    {
        $this->createEvent(['title' => 'School pickup',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 15:30:00']);

        $output = $this->builder->renderForUser($this->uid);
        $parsed = Reader::read($output);

        self::assertCount(1, $parsed->VEVENT);
        $vevent = $parsed->VEVENT[0];
        self::assertSame('School pickup', (string) $vevent->SUMMARY);
        self::assertSame($this->tz, (string) $vevent->DTSTART['TZID']);
    }

    public function test_recurring_event_emits_rrule(): void
    {
        $eid = $this->createEvent([
            'title' => 'Soccer',
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]);

        $output = $this->builder->renderForUser($this->uid);
        $parsed = Reader::read($output);

        $vevent = $parsed->VEVENT[0];
        self::assertNotNull($vevent->RRULE);
        self::assertStringContainsString('FREQ=WEEKLY', (string) $vevent->RRULE);
        self::assertStringContainsString('BYDAY=TU', (string) $vevent->RRULE);
    }

    public function test_cancellation_emits_exdate_on_series_vevent(): void
    {
        $eid = $this->createEvent([
            'title' => 'Soccer',
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]);
        $this->exceptions->cancel($eid, new \DateTimeImmutable('2026-07-14 18:00:00', new \DateTimeZone($this->tz)));

        $output = $this->builder->renderForUser($this->uid);
        $parsed = Reader::read($output);

        $vevent = $parsed->VEVENT[0];
        self::assertNotNull($vevent->EXDATE);
        // The EXDATE references the cancelled occurrence
        $exdates = is_array($vevent->EXDATE) ? $vevent->EXDATE : [$vevent->EXDATE];
        $found = false;
        foreach ($exdates as $exdate) {
            if (str_contains((string) $exdate, '20260714')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'EXDATE should reference 2026-07-14 cancellation');
    }

    public function test_override_emits_separate_vevent_with_recurrence_id(): void
    {
        $eid = $this->createEvent([
            'title' => 'Soccer',
            'starts_at_local' => '2026-07-07 18:00:00',
            'ends_at_local' => '2026-07-07 19:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]);
        $this->exceptions->addOverride(
            $eid,
            new \DateTimeImmutable('2026-07-14 18:00:00', new \DateTimeZone($this->tz)),
            ['title' => 'Soccer (moved)',
             'description' => '',
             'location' => '',
             'starts_at_local' => '2026-07-14 19:00:00',
             'ends_at_local' => '2026-07-14 20:00:00',
             'timezone' => $this->tz,
             'all_day' => false],
        );

        $output = $this->builder->renderForUser($this->uid);
        $parsed = Reader::read($output);

        // Two VEVENTs: the series + the override
        $vevents = $parsed->VEVENT;
        self::assertCount(2, $vevents);

        // Find the one with RECURRENCE-ID; it must share UID with the series
        $seriesUid = null;
        $overrideVevent = null;
        foreach ($vevents as $v) {
            if (isset($v->{'RECURRENCE-ID'})) {
                $overrideVevent = $v;
            } else {
                $seriesUid = (string) $v->UID;
            }
        }
        self::assertNotNull($overrideVevent);
        self::assertSame($seriesUid, (string) $overrideVevent->UID);
        self::assertSame('Soccer (moved)', (string) $overrideVevent->SUMMARY);
        self::assertStringContainsString('20260714', (string) $overrideVevent->{'RECURRENCE-ID'});
    }

    public function test_vtimezone_block_included_for_event_timezone(): void
    {
        $this->createEvent(['title' => 'X',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 16:00:00']);

        $output = $this->builder->renderForUser($this->uid);

        self::assertStringContainsString('BEGIN:VTIMEZONE', $output);
        self::assertStringContainsString('TZID:Pacific/Auckland', $output);
        self::assertStringContainsString('END:VTIMEZONE', $output);
    }

    public function test_range_cap_excludes_events_far_in_the_past(): void
    {
        $this->createEvent(['title' => 'Ancient',
            'starts_at_local' => '2020-01-01 10:00:00',
            'ends_at_local' => '2020-01-01 11:00:00']);

        $output = $this->builder->renderForUser($this->uid);
        $parsed = Reader::read($output);

        // No VEVENT for the 2020 event
        self::assertEmpty($parsed->VEVENT ?? []);
    }

    public function test_open_ended_rrule_included_regardless_of_start_date(): void
    {
        // Old RRULE start, but the rule has no UNTIL → must be included
        $this->createEvent([
            'title' => 'Forever-weekly',
            'starts_at_local' => '2020-01-07 09:00:00',
            'ends_at_local' => '2020-01-07 10:00:00',
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
        ]);

        $output = $this->builder->renderForUser($this->uid);
        $parsed = Reader::read($output);

        self::assertCount(1, $parsed->VEVENT);
        self::assertSame('Forever-weekly', (string) $parsed->VEVENT[0]->SUMMARY);
    }

    public function test_household_membership_required_to_appear_in_feed(): void
    {
        // A second household the user isn't a member of
        $otherOwnerId = (int) $this->db->fetchScalar(
            "INSERT INTO users (email, password_hash, display_name) VALUES ('other-' || :s || '@example.com', 'x', 'O') RETURNING id",
            ['s' => bin2hex(random_bytes(3))],
        );
        $otherHid = $this->households->createForOwner('Other', $otherOwnerId, $this->tz);
        $this->events->create([
            'household_id' => $otherHid,
            'created_by' => $otherOwnerId,
            'title' => 'Not mine',
            'description' => '', 'location' => '',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 16:00:00',
            'timezone' => $this->tz,
            'all_day' => false,
        ]);

        $output = $this->builder->renderForUser($this->uid);

        self::assertStringNotContainsString('Not mine', $output);
    }

    public function test_unique_id_distinct_per_event(): void
    {
        $this->createEvent(['title' => 'A',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 16:00:00']);
        $this->createEvent(['title' => 'B',
            'starts_at_local' => '2026-07-15 15:00:00',
            'ends_at_local' => '2026-07-15 16:00:00']);

        $output = $this->builder->renderForUser($this->uid);
        $parsed = Reader::read($output);

        $uids = [];
        foreach ($parsed->VEVENT as $v) {
            $uids[] = (string) $v->UID;
        }
        self::assertCount(2, $uids);
        self::assertSame($uids, array_unique($uids));
    }

    /** @param array<string, mixed> $overrides */
    private function createEvent(array $overrides): int
    {
        $rrule = $overrides['rrule'] ?? null;
        unset($overrides['rrule']);

        $id = $this->events->create($overrides + [
            'household_id' => $this->hid,
            'created_by' => $this->uid,
            'title' => 'Event',
            'description' => '',
            'location' => '',
            'starts_at_local' => '2026-07-14 15:00:00',
            'ends_at_local' => '2026-07-14 16:00:00',
            'timezone' => $this->tz,
            'all_day' => false,
        ]);
        if ($rrule !== null) {
            $this->db->run('UPDATE events SET rrule = :r WHERE id = :id', ['r' => $rrule, 'id' => $id]);
        }
        return $id;
    }
}
