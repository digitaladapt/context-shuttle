<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\Domain\CalendarEvent;
use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Paging\Cursor;
use App\Calendar\Paging\Paginator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The opaque page cursor.
 *
 * The property that matters is that following a cursor yields every row
 * exactly once, and that it stays correct when the underlying data changes
 * between pages — which is why it anchors to a row's position rather than a
 * count.
 *
 * @internal
 *
 * @covers \App\Calendar\Paging\Cursor
 * @covers \App\Calendar\Paging\Paginator
 */
final class PagingTest extends TestCase
{
    private function event(string $id, string $start, string $orderKey, string $summary = 'S'): CalendarEvent
    {
        return new CalendarEvent(
            id: $id,
            uid: 'uid-'.$id,
            recurrenceId: null,
            summary: $summary,
            description: null,
            start: $start,
            end: null,
            allDay: false,
            endExclusive: false,
            location: null,
            categories: [],
            status: null,
            readonly: false,
            calendar: new CalendarInfo('/cal/', 'cal', false),
            orderKey: $orderKey,
        );
    }

    /**
     * @return list<CalendarEvent>
     */
    private function events(int $count): array
    {
        $events = [];

        for ($i = 1; $i <= $count; ++$i) {
            $day = str_pad((string) $i, 2, '0', \STR_PAD_LEFT);
            $events[] = $this->event("e{$i}", "2026-10-{$day}T10:00:00+00:00", "2026-10-{$day}T10:00:00Z");
        }

        return $events;
    }

    // ── cursor encoding ───────────────────────────────────────────────────

    public function test_round_trips(): void
    {
        $cursor = new Cursor('2026-10-09T15:00:00Z', 'evt::2026-10-09T15:00:00Z');

        $decoded = Cursor::decode($cursor->encode());

        self::assertSame('2026-10-09T15:00:00Z', $decoded->sortKey);
        self::assertSame('evt::2026-10-09T15:00:00Z', $decoded->id);
    }

    public function test_encoding_is_url_safe(): void
    {
        $encoded = (new Cursor('2026-11-01', 'holiday::2026-11-01'))->encode();

        self::assertSame($encoded, rawurlencode($encoded), 'a cursor must survive being pasted as a query value');
    }

    public function test_a_malformed_cursor_is_a_named_error_not_a_silent_restart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cursor');

        Cursor::decode('!!!not-base64!!!');
    }

    public function test_a_cursor_with_the_wrong_payload_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cursor::decode(rtrim(strtr(base64_encode('{"nope":true}'), '+/', '-_'), '='));
    }

    public function test_a_valid_base64_string_that_is_not_json_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cursor::decode(rtrim(strtr(base64_encode('not json at all'), '+/', '-_'), '='));
    }

    // ── paging behaviour ──────────────────────────────────────────────────

    public function test_a_listing_shorter_than_the_page_has_no_cursor(): void
    {
        $page = (new Paginator())->page($this->events(3), 10, null);

        self::assertCount(3, $page->events);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
    }

    public function test_walking_to_exhaustion_yields_every_row_exactly_once(): void
    {
        $all = $this->events(11);
        $paginator = new Paginator();

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $paginator->page($all, 4, $cursor);
            ++$pages;

            foreach ($page->events as $event) {
                $seen[] = $event->id;
            }

            $cursor = $page->nextCursor;
        } while (null !== $cursor && $pages < 20);

        self::assertSame(3, $pages);
        self::assertCount(11, $seen);
        self::assertSame($seen, array_values(array_unique($seen)), 'no row may repeat');
        self::assertSame(
            array_map(static fn ($e): string => $e->id, $all),
            $seen,
            'no row may be skipped',
        );
    }

    public function test_a_row_inserted_before_the_cursor_neither_skips_nor_duplicates(): void
    {
        $original = $this->events(8);
        $paginator = new Paginator();

        $first = $paginator->page($original, 3, null);
        self::assertNotNull($first->nextCursor);

        // Something is added earlier in the range between the two pages.
        $inserted = $this->event('inserted', '2026-10-01T05:00:00+00:00', '2026-10-01T05:00:00Z');
        $shifted = array_merge([$inserted], $original);

        $second = $paginator->page($shifted, 3, $first->nextCursor);

        // An offset would have repeated the row that moved down a slot; a
        // position does not.
        $firstIds = array_map(static fn ($e): string => $e->id, $first->events);
        $secondIds = array_map(static fn ($e): string => $e->id, $second->events);

        self::assertSame([], array_intersect($firstIds, $secondIds));
        // The inserted row sorts before the cursor, so it is correctly not
        // returned again.
        self::assertNotContains('inserted', $secondIds);
    }

    public function test_a_cursor_whose_row_disappeared_still_resumes_by_position(): void
    {
        $all = $this->events(8);
        $paginator = new Paginator();

        $first = $paginator->page($all, 3, null);
        $cursor = $first->nextCursor;
        self::assertNotNull($cursor);

        // The row the cursor pointed at is deleted.
        $without = array_values(array_filter(
            $all,
            static fn ($e): bool => $e->id !== $first->events[2]->id,
        ));

        $second = $paginator->page($without, 3, $cursor);

        // Everything that sorts after the remembered point, which is the
        // honest continuation.
        self::assertNotSame([], $second->events);

        foreach ($second->events as $event) {
            self::assertGreaterThan($cursor->sortKey, $event->orderKey());
        }
    }

    public function test_rows_sharing_a_start_instant_are_paged_deterministically(): void
    {
        $tied = [
            $this->event('b', '2026-10-09T10:00:00+00:00', '2026-10-09T10:00:00Z'),
            $this->event('a', '2026-10-09T10:00:00+00:00', '2026-10-09T10:00:00Z'),
            $this->event('c', '2026-10-09T11:00:00+00:00', '2026-10-09T11:00:00Z'),
        ];
        usort($tied, static fn ($x, $y): int => [$x->orderKey(), $x->id] <=> [$y->orderKey(), $y->id]);

        $paginator = new Paginator();
        $first = $paginator->page($tied, 2, null);

        self::assertSame(['a', 'b'], array_map(static fn ($e): string => $e->id, $first->events));

        $second = $paginator->page($tied, 2, $first->nextCursor);

        self::assertSame(['c'], array_map(static fn ($e): string => $e->id, $second->events));
    }
}
