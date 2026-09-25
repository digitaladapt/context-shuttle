<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Write\EventBuilder;
use App\Calendar\Write\EventDraft;
use App\Calendar\Write\When;
use App\Calendar\Write\WriteRefused;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * The payloads a write sends.
 *
 * Two things are load-bearing here and neither is cosmetic:
 *
 * - **Escaping**, because `summary` and friends are attacker-influenced text
 *   that ends up in a wire format where a newline starts a new property. The
 *   builder goes through sabre's data model for this reason, and the first
 *   test is the one that would fail if someone ever "simplified" it into
 *   string concatenation.
 * - **Preservation**, because an event carries properties this application
 *   does not model — attendees, alarms, custom `X-` fields — and an edit that
 *   silently dropped them would delete other people's reminders.
 *
 * @internal
 *
 * @covers \App\Calendar\Write\EventBuilder
 */
final class EventBuilderTest extends TestCase
{
    private function utc(): TimeZoneRule
    {
        return new TimeZoneRule('UTC');
    }

    private function builder(?TimeZoneRule $zone = null): EventBuilder
    {
        return new EventBuilder($zone ?? $this->utc());
    }

    /**
     * `Reader::read()` is annotated as `Document`, which is wider than what it
     * actually returns for a VCALENDAR payload, so the narrowing happens once
     * here rather than being asserted away at every use.
     */
    /**
     * A property value, read the way the production code reads it: `select()`
     * with a narrowing, because sabre's magic getters cannot be checked.
     */
    /**
     * @param VCalendar&iterable<mixed, mixed> $calendar
     */
    private function summaryOf(VCalendar $calendar): string
    {
        $event = $calendar->select('VEVENT')[0] ?? null;
        self::assertInstanceOf(VEvent::class, $event);

        $property = $event->select('SUMMARY')[0] ?? null;
        self::assertInstanceOf(\Sabre\VObject\Property::class, $property);

        return (string) $property->getValue();
    }

    /**
     * @param VCalendar&iterable<mixed, mixed> $calendar
     */
    private function descriptionOf(VCalendar $calendar): string
    {
        $event = $calendar->select('VEVENT')[0] ?? null;
        self::assertInstanceOf(VEvent::class, $event);

        $property = $event->select('DESCRIPTION')[0] ?? null;
        self::assertInstanceOf(\Sabre\VObject\Property::class, $property);

        return (string) $property->getValue();
    }

    /**
     * @return VCalendar&iterable<mixed, mixed>
     */
    private function parse(string $ics): VCalendar
    {
        $component = Reader::read($ics);

        self::assertInstanceOf(VCalendar::class, $component);

        return $component;
    }

    private function timed(string $start = '2026-12-01 14:00'): When
    {
        return When::parse($start, $this->utc(), 'start');
    }

    private function date(string $date = '2026-12-05'): When
    {
        return When::parse($date, $this->utc(), 'start');
    }

    /**
     * A caller's text must never become a second property.
     *
     * Measured against the real serializer: a `summary` containing CRLF and a
     * `BEGIN:VALARM` comes out folded onto one property with the control
     * characters escaped, so no component can be smuggled in.
     */
    public function test_caller_text_cannot_inject_properties_or_components(): void
    {
        $hostile = "Line one\nDESCRIPTION:injected\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nEND:VALARM";

        $calendar = $this->builder()->create('x@test', new EventDraft(summary: $hostile), $this->timed(), null);
        $serialized = $this->builder()->serialize($calendar);

        self::assertStringNotContainsString("\r\nBEGIN:VALARM", $serialized);
        self::assertStringNotContainsString("\r\nDESCRIPTION:injected", $serialized);
        self::assertStringNotContainsString("\r\nACTION:DISPLAY", $serialized);

        // The payload still contains exactly one component and one summary:
        // the hostile text stayed inside a property value instead of starting
        // new lines.
        self::assertSame(1, substr_count($serialized, 'BEGIN:VEVENT'));
        self::assertSame(1, substr_count($serialized, 'SUMMARY:'));
        // Nothing on a line of its own: the text is inside a value, escaped,
        // not a property or a component of its own.
        self::assertSame(0, preg_match_all('/^VALARM/m', $serialized));
        self::assertSame(0, preg_match_all('/^ACTION:/m', $serialized));
        self::assertSame(0, preg_match_all('/^BEGIN:VALARM/m', $serialized));

        // The round trip returns the text with newlines normalised to `\n`
        // (the serializer's line-ending choice, not ours to control), so the
        // assertion is that nothing was lost and nothing became structure —
        // which is the property that matters, not the exact bytes.
        $parsed = $this->parse($serialized);
        self::assertSame(
            str_replace("\r\n", "\n", $hostile),
            (string) $this->summaryOf($parsed),
        );
    }

    public function test_semicolons_commas_and_backslashes_are_escaped(): void
    {
        $value = 'Robin; Hood, Esq. C:\\path';

        $calendar = $this->builder()->create('x@test', new EventDraft(summary: 'S', description: $value), $this->timed(), null);

        $parsed = $this->parse($this->builder()->serialize($calendar));

        self::assertSame($value, (string) $this->descriptionOf($parsed));
    }

    public function test_an_update_preserves_properties_it_does_not_model(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VEVENT\r\nUID:u@t\r\n"
            ."DTSTART:20261201T140000Z\r\nDTEND:20261201T150000Z\r\nSUMMARY:Original\r\n"
            ."ATTENDEE;CN=Bob:mailto:bob@example.com\r\nORGANIZER:mailto:boss@example.com\r\n"
            ."X-CUSTOM-FLAG:keep me\r\n"
            ."BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";

        $calendar = $this->builder()->parse($ics);
        $master = $this->builder()->master($calendar, 'u@t');
        self::assertNotNull($master);

        $this->builder()->applyToMaster($master, new EventDraft(summary: 'Renamed'), null, null);
        $serialized = $this->builder()->serialize($calendar);

        self::assertStringContainsString('SUMMARY:Renamed', $serialized);
        self::assertStringContainsString('ATTENDEE', $serialized);
        self::assertStringContainsString('ORGANIZER', $serialized);
        self::assertStringContainsString('X-CUSTOM-FLAG:keep me', $serialized);
        self::assertStringContainsString('BEGIN:VALARM', $serialized);
        self::assertStringContainsString('TRIGGER:-PT15M', $serialized);
    }

    public function test_a_timed_event_is_stored_as_utc(): void
    {
        // A zone that is *not* UTC, so the conversion is observable: 09:30 in
        // New York on 1 December is 14:30 UTC.
        $newYork = new TimeZoneRule('America/New_York');
        $builder = new EventBuilder($newYork);

        $calendar = $builder->create(
            'x@test',
            new EventDraft(summary: 'S'),
            When::parse('2026-12-01 09:30', $newYork, 'start'),
            null,
        );

        // Storage is an instant, so no `TZID` is claimed: where the event
        // "lives" is a claim a write tool has no business making, and UTC is
        // what every server and client agrees on.
        $serialized = $builder->serialize($calendar);
        self::assertStringContainsString('DTSTART:20261201T143000Z', $serialized);
        self::assertStringNotContainsString('TZID', $serialized);
    }

    public function test_an_all_day_event_is_written_as_a_date_value(): void
    {
        $calendar = $this->builder()->create('x@test', new EventDraft(summary: 'S'), $this->date('2026-12-05'), null);
        $serialized = $this->builder()->serialize($calendar);

        self::assertStringContainsString('DTSTART;VALUE=DATE:20261205', $serialized);
        // A one-day all-day event ends the next day, exclusive — omitting the
        // end leaves many clients guessing.
        self::assertStringContainsString('DTEND;VALUE=DATE:20261206', $serialized);
    }

    public function test_a_mismatched_end_is_not_written_into_a_timed_event(): void
    {
        // A date-valued DTEND beside a timed DTSTART is malformed, so the
        // mismatched end is dropped rather than written.
        $calendar = $this->builder()->create('x@test', new EventDraft(summary: 'S'), $this->timed(), $this->date());
        $serialized = $this->builder()->serialize($calendar);

        self::assertStringNotContainsString('DTEND;VALUE=DATE', $serialized);
        self::assertStringNotContainsString('DTEND', $serialized);
    }

    public function test_editing_an_occurrence_replaces_rather_than_stacks_overrides(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VEVENT\r\nUID:s@t\r\n"
            ."DTSTART:20261207T150000Z\r\nDTEND:20261207T160000Z\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\nSUMMARY:Weekly\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";

        $slot = When::parse('2026-12-14 15:00', $this->utc(), 'start');

        $calendar = $this->builder()->parse($ics);
        $this->builder()->withOccurrenceOverride($calendar, 's@t', $slot, new EventDraft(summary: 'First'), $this->timed('2026-12-14 15:00'), null);
        $calendar = $this->builder()->withOccurrenceOverride($calendar, 's@t', $slot, new EventDraft(summary: 'Second'), $this->timed('2026-12-14 16:00'), null);

        $serialized = $this->builder()->serialize($calendar);

        // Editing the same occurrence twice must not accumulate components,
        // and must not lose the series.
        self::assertSame(2, substr_count($serialized, 'BEGIN:VEVENT'));
        self::assertStringContainsString('SUMMARY:Second', $serialized);
        self::assertStringNotContainsString('SUMMARY:First', $serialized);
        self::assertStringContainsString('RRULE:FREQ=WEEKLY;COUNT=4', $serialized);
    }

    public function test_an_override_keeps_the_fields_it_does_not_change(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VEVENT\r\nUID:s@t\r\n"
            ."DTSTART:20261207T150000Z\r\nDTEND:20261207T160000Z\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\n"
            ."SUMMARY:Weekly\r\nLOCATION:Room 1\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";

        $calendar = $this->builder()->parse($ics);
        $this->builder()->withOccurrenceOverride(
            $calendar,
            's@t',
            When::parse('2026-12-14 15:00', $this->utc(), 'start'),
            new EventDraft(summary: 'Moved'),
            $this->timed('2026-12-14 17:00'),
            null,
        );

        $parsed = $this->parse($this->builder()->serialize($calendar));
        $override = null;
        foreach ($parsed->select('VEVENT') as $event) {
            if ($event instanceof VEvent && null !== ($event->select('RECURRENCE-ID')[0] ?? null)) {
                $override = $event;
            }
        }

        self::assertInstanceOf(VEvent::class, $override);

        $summary = $override->select('SUMMARY')[0] ?? null;
        $location = $override->select('LOCATION')[0] ?? null;

        self::assertInstanceOf(\Sabre\VObject\Property::class, $summary);
        self::assertInstanceOf(\Sabre\VObject\Property::class, $location);
        self::assertSame('Moved', (string) $summary->getValue());
        // "Move this occurrence" must keep the location, not blank it.
        self::assertSame('Room 1', (string) $location->getValue());
        // And must not carry the series' recurrence rules onto the occurrence.
        self::assertSame([], $override->select('RRULE'));
    }

    public function test_deleting_one_occurrence_excludes_it_and_keeps_the_series(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VEVENT\r\nUID:s@t\r\n"
            ."DTSTART:20261207T150000Z\r\nDTEND:20261207T160000Z\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\nSUMMARY:Weekly\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";

        $calendar = $this->builder()->parse($ics);
        $this->builder()->withoutOccurrence($calendar, 's@t', When::parse('2026-12-14 15:00', $this->utc(), 'start'));

        $serialized = $this->builder()->serialize($calendar);

        self::assertStringContainsString('EXDATE:20261214T150000Z', $serialized);
        self::assertStringContainsString('RRULE:FREQ=WEEKLY;COUNT=4', $serialized);

        // The exclusion really removes the occurrence, and only that one.
        $expanded = (clone $this->parse($serialized))->expand(
            new DateTime('2026-12-01'),
            new DateTime('2027-01-15'),
            new DateTimeZone('UTC'),
        );
        self::assertCount(3, $expanded->select('VEVENT'));
    }

    public function test_deleting_a_moved_occurrence_both_drops_the_override_and_excludes_the_slot(): void
    {
        // The subtle case, and one that was got wrong first: an occurrence that
        // had been moved is a component whose slot the master still generates,
        // so removing the override alone puts the original back and excluding
        // the slot alone leaves the moved copy visible.
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VEVENT\r\nUID:s@t\r\n"
            ."DTSTART:20261207T150000Z\r\nDTEND:20261207T160000Z\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\nSUMMARY:Weekly\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";

        $calendar = $this->builder()->parse($ics);
        $slot = When::parse('2026-12-14 15:00', $this->utc(), 'start');
        $this->builder()->withOccurrenceOverride($calendar, 's@t', $slot, new EventDraft(), $this->timed('2026-12-14 20:00'), null);
        $this->builder()->withoutOccurrence($calendar, 's@t', $slot);

        $serialized = $this->builder()->serialize($calendar);

        self::assertSame(1, substr_count($serialized, 'BEGIN:VEVENT'), 'the override must be gone');
        self::assertStringContainsString('EXDATE:20261214T150000Z', $serialized);

        $expanded = (clone $this->parse($serialized))->expand(
            new DateTime('2026-12-01'),
            new DateTime('2027-01-15'),
            new DateTimeZone('UTC'),
        );
        self::assertCount(3, $expanded->select('VEVENT'));
    }

    public function test_deleting_one_occurrence_of_a_non_recurring_event_is_refused(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VEVENT\r\nUID:o@t\r\n"
            ."DTSTART:20261201T150000Z\r\nDTEND:20261201T160000Z\r\nSUMMARY:One-off\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";

        $calendar = $this->builder()->parse($ics);

        // Deleting the whole thing would satisfy the letter of the request and
        // destroy more than was asked for, so it is refused with a sentence
        // pointing at the right call instead.
        $this->expectException(WriteRefused::class);
        $this->expectExceptionMessageMatches('/does not repeat/');

        $this->builder()->withoutOccurrence($calendar, 'o@t', When::parse('2026-12-01 15:00', $this->utc(), 'start'));
    }

    public function test_truncating_a_series_replaces_count_with_until(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VEVENT\r\nUID:s@t\r\n"
            ."DTSTART:20261207T150000Z\r\nDTEND:20261207T160000Z\r\nRRULE:FREQ=WEEKLY;COUNT=10\r\nSUMMARY:Weekly\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";

        $calendar = $this->builder()->parse($ics);
        $this->builder()->truncateFrom($calendar, 's@t', When::parse('2026-12-21 15:00', $this->utc(), 'start'));

        $serialized = $this->builder()->serialize($calendar);

        // Both bounds at once is a payload strict servers reject.
        self::assertStringNotContainsString('COUNT=', $serialized);
        self::assertStringContainsString('UNTIL=20261221T150000Z', $serialized);
    }

    public function test_an_unreadable_payload_is_refused_rather_than_rebuilt(): void
    {
        // Writing back a rebuilt payload would delete whatever we could not
        // understand, so an unparseable object fails loudly instead.
        $this->expectException(WriteRefused::class);
        $this->expectExceptionMessageMatches('/could not be read/');

        $this->builder()->parse("BEGIN:VCALENDAR\r\nnot a calendar at all");
    }
}
