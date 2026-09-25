<?php

declare(strict_types=1);

namespace App\Calendar\Domain;

/**
 * One occurrence, in the shape the tools emit.
 *
 * This is the *output* contract, and it is deliberately flat: every row is
 * self-describing, carrying its own `readonly` rather than making a caller
 * correlate against a sibling object. Because v1 expands every recurring
 * event, a row may mean "the standup on Oct 9", and a row that means one
 * occurrence has to be readable on its own.
 *
 * `start`/`end` are rendered in the deployment's timezone; `id` is UTC (or a
 * date). They are therefore *intentionally different strings* for the same
 * occurrence, which is worth stating in the tool description because it
 * looks like a bug.
 */
final readonly class CalendarEvent
{
    /**
     * @param list<string> $categories
     * @param string       $orderKey   comparable position, **not serialized**
     */
    public function __construct(
        public string $id,
        public string $uid,
        public ?string $recurrenceId,
        public string $summary,
        public ?string $description,
        public string $start,
        public ?string $end,
        public bool $allDay,
        public bool $endExclusive,
        public ?string $location,
        public array $categories,
        public ?string $status,
        public bool $readonly,
        public CalendarInfo $calendar,
        private string $orderKey = '',
    ) {
    }

    /**
     * The position of this row in the listing order.
     *
     * Deliberately *not* part of the output shape: it exists so that the
     * ordering and the page cursor cannot disagree, and so neither has to
     * compare `start` as text. `start` is rendered in `TZ`, where two rows an
     * hour apart can be two hours apart in string order across a DST
     * boundary; this key is UTC (or a bare date) and compares correctly.
     *
     * The format is chosen to sort lexicographically as a total order:
     * `2026-10-09T15:00:00Z` for a timed occurrence, `2026-11-01` for an
     * all-day one — a date sorts before any instant on the same day, which is
     * a stable and reasonable place for an all-day event to sit.
     */
    public function orderKey(): string
    {
        return $this->orderKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'recurrence_id' => $this->recurrenceId,
            'summary' => $this->summary,
            'description' => $this->description,
            'start' => $this->start,
            'end' => $this->end,
            'all_day' => $this->allDay,
            'end_exclusive' => $this->endExclusive,
            'location' => $this->location,
            'categories' => $this->categories,
            'status' => $this->status,
            'readonly' => $this->readonly,
            'calendar' => $this->calendar->toReference(),
        ];
    }
}
