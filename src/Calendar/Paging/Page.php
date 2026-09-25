<?php

declare(strict_types=1);

namespace App\Calendar\Paging;

use App\Calendar\Domain\CalendarEvent;
use App\Calendar\Domain\CalendarTask;

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
     * @param list<CalendarEvent> $events events in this page, empty for a task page
     * @param list<CalendarTask>  $tasks  tasks in this page, empty for an event page
     */
    public function __construct(
        public array $events = [],
        public bool $hasMore = false,
        public ?Cursor $nextCursor = null,
        public array $tasks = [],
    ) {
    }
}
