<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\Domain\CompositeId;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Composite-id parsing and formatting.
 *
 * Every row of the design's parse table is here, because the traps are all
 * in the classification step: `DateTimeImmutable` reads `2026-11-01` as a
 * valid instant, so a "does the tail parse?" rule would silently read every
 * all-day occurrence as midnight UTC and then shift its day under a non-UTC
 * timezone (finding 14).
 *
 * @internal
 *
 * @covers \App\Calendar\Domain\CompositeId
 */
final class CompositeIdTest extends TestCase
{
    public function test_parses_a_timed_occurrence(): void
    {
        $id = CompositeId::parse('evt-standup@test::2026-10-09T15:00:00Z');

        self::assertTrue($id->hasOccurrence);
        self::assertSame('evt-standup@test', $id->uid);
        self::assertNull($id->date);
        self::assertSame('2026-10-09T15:00:00+00:00', $id->instant?->format('c'));
    }

    public function test_parses_an_all_day_occurrence_as_a_date_never_an_instant(): void
    {
        $id = CompositeId::parse('holiday@test::2026-11-01');

        self::assertTrue($id->hasOccurrence);
        self::assertSame('holiday@test', $id->uid);
        self::assertSame('2026-11-01', $id->date);
        // The trap: a constructor-based check would have produced a null date
        // and a midnight instant instead.
        self::assertNull($id->instant);
    }

    public function test_a_plain_uid_is_not_an_occurrence(): void
    {
        $id = CompositeId::parse('evt-standup@test');

        self::assertFalse($id->hasOccurrence);
        self::assertSame('evt-standup@test', $id->uid);
        self::assertNull($id->instant);
        self::assertNull($id->date);
    }

    public function test_splits_on_the_last_separator_so_a_uid_may_contain_one(): void
    {
        $id = CompositeId::parse('weird::uid::2026-10-09T15:00:00Z');

        self::assertTrue($id->hasOccurrence);
        self::assertSame('weird::uid', $id->uid);
        self::assertSame('2026-10-09T15:00:00+00:00', $id->instant?->format('c'));
    }

    public function test_a_uid_ending_in_separator_text_stays_a_uid(): void
    {
        $id = CompositeId::parse('weird::uid@test');

        self::assertFalse($id->hasOccurrence);
        self::assertSame('weird::uid@test', $id->uid);
    }

    public function test_a_non_date_tail_stays_a_uid(): void
    {
        $id = CompositeId::parse('a@b::not-a-date');

        self::assertFalse($id->hasOccurrence);
        self::assertSame('a@b::not-a-date', $id->uid);
    }

    public function test_a_floating_local_time_is_not_an_instant(): void
    {
        // No trailing Z or offset, so it must not be read as a point in time.
        $id = CompositeId::parse('a@b::2026-11-01T00:00:00');

        self::assertFalse($id->hasOccurrence);
        self::assertSame('a@b::2026-11-01T00:00:00', $id->uid);
    }

    public function test_formats_an_all_day_occurrence_as_a_date(): void
    {
        $id = CompositeId::forOccurrence('holiday@test', date: '2026-11-01');

        self::assertSame('holiday@test::2026-11-01', $id->toString());
    }

    public function test_formats_a_timed_occurrence_in_utc_whatever_the_input_zone(): void
    {
        $instant = new DateTimeImmutable('2026-10-09 11:00:00', new DateTimeZone('America/New_York'));

        $id = CompositeId::forOccurrence('evt@test', instant: $instant);

        // 11:00 in New York is 15:00 UTC, and the id names the instant, not
        // the wall clock.
        self::assertSame('evt@test::2026-10-09T15:00:00Z', $id->toString());
    }

    public function test_round_trips_every_parse_case(): void
    {
        $values = [
            'evt-standup@test::2026-10-09T15:00:00Z',
            'holiday@test::2026-11-01',
            'evt-standup@test',
            'weird::uid::2026-10-09T15:00:00Z',
            'weird::uid@test',
        ];

        foreach ($values as $value) {
            self::assertSame($value, CompositeId::parse($value)->toString(), "round trip failed for {$value}");
        }
    }

    public function test_the_instant_is_unchanged_by_a_negative_offset_in_the_id(): void
    {
        $id = CompositeId::parse('evt@test::2026-10-09T15:00:00-04:00');

        self::assertTrue($id->hasOccurrence);
        // The same moment, however the tail spelled it.
        self::assertSame('2026-10-09T19:00:00+00:00', $id->instant?->format('c'));
    }
}
