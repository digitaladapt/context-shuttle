<?php

declare(strict_types=1);

namespace App\Calendar\Domain;

/**
 * One task, in the shape the tools emit.
 *
 * Deliberately a sibling of `CalendarEvent` rather than a shared base: the
 * two share their addressing (`id`/`uid`/`readonly`/`calendar`) and their
 * timezone discipline, but almost nothing else, and a common parent would
 * mostly exist to be downcast. The fields a caller reads are what matter,
 * and a task has `due`/`percent_complete`/`priority` where an event has
 * `start`/`end`/`location`.
 *
 * `due` follows the same rule as an event's start: an instant rendered in
 * the deployment's timezone, or a bare date when the task is date-only, and
 * never converted between the two. It is nullable, because a task without a
 * deadline is normal — it is a note as often as it is a commitment.
 */
final readonly class CalendarTask
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public string $id,
        public string $uid,
        public string $summary,
        public ?string $description,
        public ?string $due,
        public bool $dueIsDate,
        public ?string $status,
        public ?int $percentComplete,
        public ?int $priority,
        public ?string $completedAt,
        public array $categories,
        public bool $readonly,
        public CalendarInfo $calendar,
        private string $orderKey = '',
        private bool $hasDue = false,
    ) {
    }

    /**
     * The position of this row in the listing order.
     *
     * Like an event's, this is deliberately **not** serialized. Tasks order
     * by due date with undated ones last, so a task with no deadline needs a
     * key that sorts after every real date without pretending to be one.
     */
    public function orderKey(): string
    {
        return $this->orderKey;
    }

    /**
     * Whether the task has a deadline at all, which is what decides whether
     * it sorts among dated tasks or after them.
     */
    public function hasDue(): bool
    {
        return $this->hasDue;
    }

    /**
     * Whether the task is finished.
     *
     * `STATUS:COMPLETED` is authoritative; `PERCENT-COMPLETE:100` is accepted
     * as well, because clients disagree about which one they set and a task
     * showing as open at 100% is a worse answer than treating it as done.
     */
    public function isCompleted(): bool
    {
        if (null !== $this->status && 'COMPLETED' === strtoupper($this->status)) {
            return true;
        }

        return 100 === $this->percentComplete;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'summary' => $this->summary,
            'description' => $this->description,
            'due' => $this->due,
            'due_is_date' => $this->dueIsDate,
            'status' => $this->status,
            'percent_complete' => $this->percentComplete,
            'priority' => $this->priority,
            'completed_at' => $this->completedAt,
            'categories' => $this->categories,
            'readonly' => $this->readonly,
            'calendar' => $this->calendar->toReference(),
        ];
    }
}
