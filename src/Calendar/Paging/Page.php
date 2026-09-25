<?php

declare(strict_types=1);

namespace App\Calendar\Paging;

use App\Calendar\Domain\CalendarEvent;

/**
 * One page of a listing, plus what a caller needs to ask for the next.
 *
 * Ordering is by start instant, then id, so it is stable across calls — a
 * non-deterministic order makes a model's narration of a day wrong, and it
 * is also what makes a cursor meaningful.
 */
final readonly class Page
{
    /**
     * @param list<CalendarEvent> $events
     */
    public function __construct(
        public array $events,
        public bool $hasMore,
        public ?Cursor $nextCursor,
    ) {
    }
}
