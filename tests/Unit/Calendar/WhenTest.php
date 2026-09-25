<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Write\When;
use App\Calendar\Write\WriteRefused;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;

/**
 * How a caller's time is read, and what is refused.
 *
 * The refusals carry as much weight as the acceptances. A write tool that
 * silently reinterpreted a timestamp would store an event at the wrong
 * instant and nothing downstream could tell — the read path would faithfully
 * render whatever was saved. So the cases that *must not* be accepted are
 * tested as carefully as the ones that must.
 *
 * @internal
 *
 * @covers \App\Calendar\Write\When
 */
final class WhenTest extends TestCase
{
    private function newYork(): TimeZoneRule
    {
        return new TimeZoneRule('America/New_York');
    }

    public function test_a_date_and_time_is_read_in_the_deployment_timezone(): void
    {
        $when = When::parse('2026-12-01 09:30', $this->newYork(), 'start');

        self::assertFalse($when->isDate);
        // 09:30 in New York on that date is 14:30 UTC.
        self::assertSame('2026-12-01T14:30:00+00:00', $when->instant?->format(DateTimeInterface::ATOM));
    }

    public function test_a_bare_date_is_an_all_day_event(): void
    {
        $when = When::parse('2026-12-05', $this->newYork(), 'start');

        self::assertTrue($when->isDate);
        self::assertSame('2026-12-05', $when->date);
        self::assertNull($when->instant);
    }

    public function test_a_t_separator_is_accepted_like_a_space(): void
    {
        $spaced = When::parse('2026-12-01 09:30', $this->newYork(), 'start');
        $terse = When::parse('2026-12-01T09:30', $this->newYork(), 'start');

        self::assertTrue($spaced->sameAs($terse));
    }

    /**
     * The one input most likely to arrive by mistake.
     *
     * Every listing returns `2026-12-01T09:30:00-05:00`, so it is the string a
     * caller is most likely to paste back — and accepting it would quietly
     * make offsets part of the input format, which is the requirement the
     * whole timezone rule exists to remove.
     */
    public function test_an_offset_bearing_timestamp_is_refused_by_name(): void
    {
        $this->expectException(WriteRefused::class);
        $this->expectExceptionMessageMatches('/Do not pass back the timestamp format that listings return/');

        When::parse('2026-12-01T09:30:00-05:00', $this->newYork(), 'start');
    }

    public function test_a_utc_timestamp_is_also_refused(): void
    {
        $this->expectException(WriteRefused::class);

        When::parse('2026-12-01T14:30:00Z', $this->newYork(), 'start');
    }

    /**
     * A wall clock skipped by a daylight-saving change.
     *
     * PHP's date library moves 02:30 forward to 03:30 without complaint, so an
     * event asked for at 02:30 would be stored an hour from where the caller
     * put it while the response echoed the time they asked for. Refusing is
     * the only answer that cannot be wrong.
     */
    public function test_a_nonexistent_wall_clock_is_refused_rather_than_moved(): void
    {
        $this->expectException(WriteRefused::class);
        $this->expectExceptionMessageMatches('/does not exist in America\/New_York/');

        // US DST starts on this date: 02:00–03:00 does not exist.
        When::parse('2027-03-14 02:30', $this->newYork(), 'start');
    }

    public function test_the_hour_either_side_of_the_dst_gap_is_accepted(): void
    {
        $before = When::parse('2027-03-14 01:30', $this->newYork(), 'start');
        $after = When::parse('2027-03-14 03:30', $this->newYork(), 'start');

        // The gap is real: 01:30 EST and 03:30 EDT are one hour apart in UTC
        // terms, not two.
        self::assertSame(
            3600,
            $after->instant->getTimestamp() - $before->instant->getTimestamp(),
        );
    }

    public function test_an_ambiguous_wall_clock_is_accepted(): void
    {
        // The repeated hour when DST ends. Unlike the gap, this has a defensible
        // reading (PHP takes the first), and refusing it would make a genuinely
        // valid local time unusable once a year.
        $when = When::parse('2026-11-01 01:30', $this->newYork(), 'start');

        self::assertFalse($when->isDate);
    }

    public function test_impossible_dates_and_times_are_refused(): void
    {
        foreach (['2026-02-30 10:00', '2026-12-01 25:00', '2026-12-01 10:99', 'not a date', '01/12/2026'] as $bad) {
            try {
                When::parse($bad, $this->newYork(), 'start');
                self::fail(\sprintf('"%s" should have been refused', $bad));
            } catch (WriteRefused $e) {
                // Every refusal must name the field, so a caller with several
                // time parameters knows which one to fix.
                self::assertStringContainsString('start', $e->getMessage());
            }
        }
    }

    public function test_a_seconds_component_is_refused(): void
    {
        // Not because seconds are harmful, but because accepting `:00` and
        // rejecting `:30` would be a rule nobody remembers, and accepting both
        // would make the write format wider than the read format needs.
        $this->expectException(WriteRefused::class);

        When::parse('2026-12-01 09:30:00', $this->newYork(), 'start');
    }

    public function test_same_as_compares_instants_not_strings(): void
    {
        $local = When::parse('2026-12-01 09:30', $this->newYork(), 'start');
        $utc = When::fromInstant(new DateTimeImmutable('2026-12-01T14:30:00Z'));

        // The whole basis for "the id you were given names this occurrence":
        // an id's instant and an occurrence's local start must be recognised
        // as the same moment despite being different strings.
        self::assertTrue($local->sameAs($utc));
    }

    public function test_same_as_treats_a_date_and_an_instant_as_different(): void
    {
        $date = When::parse('2026-12-05', $this->newYork(), 'start');
        $instant = When::fromInstant(new DateTimeImmutable('2026-12-05T00:00:00Z'));

        // An all-day event and a midnight timed event are different things,
        // and conflating them is the day-shift bug the read path already
        // guards against.
        self::assertFalse($date->sameAs($instant));
    }

    public function test_the_rendered_form_matches_what_a_listing_returns(): void
    {
        $when = When::parse('2026-12-01 09:30', $this->newYork(), 'start');

        // A caller must be able to compare the response to a later read
        // without converting anything.
        self::assertSame('2026-12-01T09:30:00-05:00', $when->describe($this->newYork()));
    }

    public function test_an_all_day_value_renders_as_a_bare_date(): void
    {
        $when = When::parse('2026-12-05', $this->newYork(), 'start');

        self::assertSame('2026-12-05', $when->describe($this->newYork()));
    }
}
