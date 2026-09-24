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
    ) {
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
