<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\CalendarObject;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Mapping\EventMapper;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The mapper: expansion, normalization and DTO shaping.
 *
 * Fixtures are raw iCalendar strings, so each finding is a test that reads
 * like the payload that caused it. Payloads mirror the ones used against the
 * live server so the two cannot drift apart.
 *
 * @internal
 *
 * @covers \App\Calendar\Mapping\EventMapper
 */
final class EventMapperTest extends TestCase
{
    private const WINDOW_FROM = '2026-09-01';
    private const WINDOW_TO = '2026-12-01';

    private function calendar(bool $readonly = false): CalendarInfo
    {
        return new CalendarInfo('/cal/', 'cal', $readonly);
    }

    /**
     * @return array{0: list<\App\Calendar\Domain\CalendarEvent>, 1: list<\App\Calendar\Domain\CalendarProblem>}
     */
    private function map(string $ics, string $zone = 'UTC', bool $readonly = false): array
    {
        $mapper = new EventMapper(new TimeZoneRule($zone));
        $utc = new DateTimeZone('UTC');

        return $mapper->map(
            new CalendarObject('/cal/a.ics', $ics, null, $this->calendar($readonly)),
            new DateTimeImmutable(self::WINDOW_FROM, $utc),
            new DateTimeImmutable(self::WINDOW_TO, $utc),
        );
    }

    private function ics(string $body): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n".$body."END:VCALENDAR\r\n";
    }

    // ── the timezone rule ─────────────────────────────────────────────────

    public function test_three_spellings_of_one_instant_normalize_identically(): void
    {
        $utcSpelling = $this->ics("BEGIN:VEVENT\r\nUID:u\r\nDTSTART:20261015T130000Z\r\nDTEND:20261015T140000Z\r\nSUMMARY:UTC\r\nEND:VEVENT\r\n");
        $namedSpelling = $this->ics("BEGIN:VEVENT\r\nUID:u\r\nDTSTART;TZID=America/New_York:20261015T090000\r\nDTEND;TZID=America/New_York:20261015T100000\r\nSUMMARY:Named\r\nEND:VEVENT\r\n");
        $fixedSpelling = $this->ics("BEGIN:VEVENT\r\nUID:u\r\nDTSTART;TZID=UTC-04:00:20261015T090000\r\nDTEND;TZID=UTC-04:00:20261015T100000\r\nSUMMARY:Fixed\r\nEND:VEVENT\r\n");

        $rendered = [];

        foreach ([$utcSpelling, $namedSpelling, $fixedSpelling] as $spelling) {
            [$events, $problems] = $this->map($spelling, 'Europe/London');
            self::assertSame([], $problems);
            self::assertCount(1, $events);
            $rendered[] = $events[0]->start;
        }

        // 13:00Z, 09:00 EDT and 09:00 at -04:00 are the same moment, so one
        // rendering covers all three.
        self::assertSame(['2026-10-15T14:00:00+01:00'], array_values(array_unique($rendered)));
    }

    public function test_a_dst_crossing_series_renders_the_documented_per_occurrence_times(): void
    {
        // 09:00 Europe/London weekly, six occurrences, crossing the end of
        // British Summer Time.
        $ics = $this->ics(
            "BEGIN:VTIMEZONE\r\nTZID:Europe/London\r\nBEGIN:STANDARD\r\nDTSTART:20001029T020000\r\nRRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0000\r\nEND:STANDARD\r\nBEGIN:DAYLIGHT\r\nDTSTART:20000326T010000\r\nRRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3\r\nTZOFFSETFROM:+0000\r\nTZOFFSETTO:+0100\r\nEND:DAYLIGHT\r\nEND:VTIMEZONE\r\n".
            "BEGIN:VEVENT\r\nUID:london-weekly\r\nDTSTART;TZID=Europe/London:20261001T090000\r\nDTEND;TZID=Europe/London:20261001T100000\r\nRRULE:FREQ=WEEKLY;COUNT=6\r\nSUMMARY:London weekly\r\nEND:VEVENT\r\n",
        );

        [$events, $problems] = $this->map($ics, 'America/New_York');

        self::assertSame([], $problems);
        self::assertCount(6, $events);

        $local = array_map(
            static fn ($event): string => substr($event->start, 11, 5),
            $events,
        );

        // London and New York change DST on different dates, so a viewer
        // genuinely sees the meeting move for a week and move back. Both this
        // and a flat series would be "wrong" to correct.
        self::assertSame(['04:00', '04:00', '04:00', '04:00', '05:00', '04:00'], $local);
    }

    public function test_an_occurrence_id_is_identical_under_two_timezones_while_start_differs(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:evt\r\nDTSTART:20261015T130000Z\r\nDTEND:20261015T140000Z\r\nSUMMARY:Standup\r\nEND:VEVENT\r\n");

        [$utcEvents] = $this->map($ics, 'UTC');
        [$nyEvents] = $this->map($ics, 'America/New_York');

        self::assertCount(1, $utcEvents);
        self::assertCount(1, $nyEvents);

        // The id names a point in time, so it must not move when the
        // deployment changes TZ.
        self::assertSame($utcEvents[0]->id, $nyEvents[0]->id);

        // `start` is the same instant rendered in TZ, so it does move — and
        // the two are deliberately different strings for one occurrence.
        self::assertSame('2026-10-15T13:00:00+00:00', $utcEvents[0]->start);
        self::assertSame('2026-10-15T09:00:00-04:00', $nyEvents[0]->start);
    }

    public function test_a_floating_time_is_read_in_the_deployment_timezone(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:f\r\nDTSTART:20261015T090000\r\nDTEND:20261015T100000\r\nSUMMARY:Floating\r\nEND:VEVENT\r\n");

        [$utcEvents] = $this->map($ics, 'UTC');
        [$nyEvents] = $this->map($ics, 'America/New_York');

        // No zone in the data means the only zone the system has a
        // vocabulary for.
        self::assertSame('2026-10-15T09:00:00+00:00', $utcEvents[0]->start);
        self::assertSame('2026-10-15T09:00:00-04:00', $nyEvents[0]->start);
    }

    // ── the two hard normalization cases ──────────────────────────────────

    public function test_an_unresolvable_tzid_is_reported_rather_than_silently_read_as_utc(): void
    {
        // sabre yields UTC for this with no error (finding 6), and `expand()`
        // then drops the TZID parameter entirely — so the event must be
        // rejected before that happens, or a fabricated instant is presented
        // as fact.
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:evt-badtz\r\nDTSTART;TZID=Custom/Zone:20261015T090000\r\nDTEND;TZID=Custom/Zone:20261015T100000\r\nSUMMARY:Custom zone\r\nEND:VEVENT\r\n");

        [$events, $problems] = $this->map($ics);

        self::assertSame([], $events, 'an event built from a dubious zone must not be returned');
        self::assertCount(1, $problems);
        self::assertSame('evt-badtz', $problems[0]->uid);
        self::assertStringContainsString('Custom/Zone', $problems[0]->reason);
    }

    public function test_a_fixed_offset_tzid_is_recovered_not_rejected(): void
    {
        // sabre throws on TZID=UTC-04:00 at every Reader option level
        // (finding 5), and passing a zone to getDateTimes() does not help
        // because TimeZoneUtil returns UTC for it (finding 7). The offset is
        // recovered from the raw payload instead.
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:evt-fx\r\nDTSTART;TZID=UTC-04:00:20261015T090000\r\nDTEND;TZID=UTC-04:00:20261015T100000\r\nSUMMARY:Fixed offset\r\nEND:VEVENT\r\n");

        [$events, $problems] = $this->map($ics, 'America/New_York');

        self::assertSame([], $problems);
        self::assertCount(1, $events);

        // 09:00 at -04:00 is 13:00Z, which renders back as 09:00 in New York.
        self::assertSame('evt-fx::2026-10-15T13:00:00Z', $events[0]->id);
        self::assertSame('2026-10-15T09:00:00-04:00', $events[0]->start);
    }

    public function test_a_half_hour_fixed_offset_is_exact(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:evt-half\r\nDTSTART;TZID=GMT+05:30:20261015T090000\r\nDTEND;TZID=GMT+05:30:20261015T100000\r\nSUMMARY:Half-hour offset\r\nEND:VEVENT\r\n");

        [$events, $problems] = $this->map($ics);

        self::assertSame([], $problems);
        self::assertSame('2026-10-15T03:30:00+00:00', $events[0]->start);
    }

    public function test_a_fixed_offset_series_keeps_a_constant_shift_across_occurrences(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:evt-fxr\r\nDTSTART;TZID=UTC-04:00:20261015T090000\r\nDTEND;TZID=UTC-04:00:20261015T100000\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Fixed recurring\r\nEND:VEVENT\r\n");

        [$events, $problems] = $this->map($ics);

        self::assertSame([], $problems);
        self::assertCount(3, $events);
        // A fixed offset has no DST, so every occurrence shifts equally.
        self::assertSame(
            ['2026-10-15T13:00:00+00:00', '2026-10-22T13:00:00+00:00', '2026-10-29T13:00:00+00:00'],
            array_map(static fn ($e): string => $e->start, $events),
        );
    }

    // ── all-day events ────────────────────────────────────────────────────

    public function test_an_all_day_event_is_a_date_and_does_not_shift_under_a_non_utc_zone(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:evt-allday\r\nDTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261102\r\nSUMMARY:Holiday\r\nEND:VEVENT\r\n");

        [$events, $problems] = $this->map($ics, 'America/New_York');

        self::assertSame([], $problems);
        self::assertCount(1, $events);

        $event = $events[0];

        self::assertTrue($event->allDay);
        // The regression: converting a bare DATE through a zone renders
        // 2026-11-01 as 2026-10-31 (finding 14).
        self::assertSame('2026-11-01', $event->start);
        self::assertSame('2026-11-02', $event->end);
        self::assertTrue($event->endExclusive, 'DTEND on an all-day event is exclusive');
        self::assertSame('evt-allday::2026-11-01', $event->id);
    }

    public function test_an_all_day_occurrence_id_parses_back_as_a_date(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:holiday\r\nDTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261102\r\nSUMMARY:Holiday\r\nEND:VEVENT\r\n");

        [$events] = $this->map($ics, 'America/New_York');

        $parsed = \App\Calendar\Domain\CompositeId::parse($events[0]->id);

        self::assertSame('2026-11-01', $parsed->date);
        self::assertNull($parsed->instant, 'an all-day occurrence must never be read as an instant');
    }

    // ── recurrence and overrides ──────────────────────────────────────────

    public function test_every_occurrence_shares_a_uid_but_has_its_own_id(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:evt-recurring\r\nDTSTART:20261006T100000Z\r\nDTEND:20261006T103000Z\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\nSUMMARY:Weekly sync\r\nEND:VEVENT\r\n");

        [$events, $problems] = $this->map($ics);

        self::assertSame([], $problems);
        self::assertCount(4, $events);

        $uids = array_unique(array_map(static fn ($e): string => $e->uid, $events));
        $ids = array_unique(array_map(static fn ($e): string => $e->id, $events));

        self::assertCount(1, $uids, 'a series carries one uid across its occurrences');
        self::assertCount(4, $ids, 'each occurrence needs its own address');
    }

    public function test_a_moved_override_reports_its_actual_start_but_keeps_the_original_slot(): void
    {
        $ics = $this->ics(
            "BEGIN:VEVENT\r\nUID:evt-override\r\nDTSTART:20261007T080000Z\r\nDTEND:20261007T081500Z\r\nRRULE:FREQ=DAILY;COUNT=5\r\nSUMMARY:Standup (series)\r\nEND:VEVENT\r\n".
            "BEGIN:VEVENT\r\nUID:evt-override\r\nRECURRENCE-ID:20261009T080000Z\r\nDTSTART:20261009T150000Z\r\nDTEND:20261009T151500Z\r\nSUMMARY:Standup (moved)\r\nEND:VEVENT\r\n",
        );

        [$events, $problems] = $this->map($ics);

        self::assertSame([], $problems);
        self::assertCount(5, $events);

        $moved = null;

        foreach ($events as $event) {
            if ('Standup (moved)' === $event->summary) {
                $moved = $event;
            }
        }

        self::assertNotNull($moved);

        // The id and start name where it actually is, so "moved to 15:00" is
        // distinguishable from "always 15:00".
        self::assertSame('evt-override::2026-10-09T15:00:00Z', $moved->id);
        self::assertSame('2026-10-09T15:00:00+00:00', $moved->start);

        // ...while recurrence_id keeps the slot it was moved out of.
        self::assertSame('2026-10-09T08:00:00Z', $moved->recurrenceId);
    }

    // ── robustness and shaping ────────────────────────────────────────────

    public function test_a_payload_that_will_not_parse_is_a_problem_not_an_exception(): void
    {
        [$events, $problems] = $this->map("BEGIN:VCALENDAR\r\nthis is not a calendar at all\r\n");

        self::assertCount(1, $problems);
        self::assertSame('/cal/', $problems[0]->calendarHref);
        self::assertNotEmpty($problems[0]->reason);
        // Whatever it returned, it must not have thrown.
        self::assertLessThanOrEqual(1, \count($events));
    }

    public function test_an_event_outside_the_window_produces_no_rows_and_no_problems(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:old\r\nDTSTART:20260101T100000Z\r\nDTEND:20260101T110000Z\r\nSUMMARY:Old\r\nEND:VEVENT\r\n");

        [$events, $problems] = $this->map($ics);

        self::assertSame([], $events);
        self::assertSame([], $problems, 'being outside the window is not an error');
    }

    public function test_readonly_is_carried_on_every_occurrence(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:evt\r\nDTSTART:20261006T100000Z\r\nDTEND:20261006T103000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Series\r\nEND:VEVENT\r\n");

        [$events] = $this->map($ics, 'UTC', readonly: true);

        self::assertCount(3, $events);

        foreach ($events as $event) {
            self::assertTrue($event->readonly, 'every expanded occurrence must be self-describing');
        }
    }

    public function test_the_output_shape_is_a_flat_self_describing_row(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:evt\r\nDTSTART:20261015T130000Z\r\nDTEND:20261015T140000Z\r\nSUMMARY:Standup\r\nDESCRIPTION:Daily\\, brief\r\nLOCATION:Office\r\nCATEGORIES:work,meeting\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\n");

        [$events] = $this->map($ics);

        $row = $events[0]->toArray();

        self::assertSame(
            ['id', 'uid', 'recurrence_id', 'summary', 'description', 'start', 'end', 'all_day', 'end_exclusive', 'location', 'categories', 'status', 'readonly', 'calendar'],
            array_keys($row),
        );
        self::assertSame(['work', 'meeting'], $row['categories']);
        self::assertSame('Office', $row['location']);
        self::assertSame('CONFIRMED', $row['status']);
        // readonly is flat on the row, and the calendar object holds only
        // identity.
        self::assertFalse($row['readonly']);
        self::assertSame(['name', 'href'], array_keys($row['calendar']));
    }

    public function test_a_categories_property_is_split_into_a_list(): void
    {
        $ics = $this->ics("BEGIN:VEVENT\r\nUID:evt\r\nDTSTART:20261015T130000Z\r\nDTEND:20261015T140000Z\r\nSUMMARY:Standup\r\nCATEGORIES:work,meeting\r\nEND:VEVENT\r\n");

        [$events] = $this->map($ics);

        self::assertSame(['work', 'meeting'], $events[0]->categories);
    }
}
