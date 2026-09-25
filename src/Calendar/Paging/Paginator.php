<?php

declare(strict_types=1);

namespace App\Calendar\Paging;

use App\Calendar\Domain\CalendarEvent;
use App\Calendar\Domain\CalendarTask;

/**
 * Slices a fully-materialized, ordered list of occurrences into a page.
 *
 * The listing is small by construction — one HTTP round trip per calendar
 * for one bounded window — so paging is applied to the assembled list
 * rather than pushed down to the provider. That keeps the cursor honest:
 * its position is a row in the final ordering, not a server-side offset
 * that only means something in one response.
 */
final readonly class Paginator
{
    /**
     * @param list<CalendarEvent> $events all occurrences, already ordered
     */
    public function page(array $events, int $limit, ?Cursor $cursor): Page
    {
        $remaining = $events;

        if (null !== $cursor) {
            $remaining = $this->after($events, $cursor);
        }

        if (\count($remaining) <= $limit) {
            return new Page($remaining, false, null);
        }

        $page = \array_slice($remaining, 0, $limit);
        $last = $page[\count($page) - 1];

        return new Page(
            $page,
            true,
            new Cursor($last->orderKey(), $last->id),
        );
    }

    /**
     * Slice an ordered task list into a page.
     *
     * Shares the cursor encoding and the "anchor on the last row's position"
     * rule with events, so a caller pages both tools the same way. The
     * position is `(order key, uid)` — a task's order key already puts
     * undated tasks last, so no special case is needed here.
     *
     * @param list<CalendarTask> $tasks all tasks, already ordered
     */
    public function pageTasks(array $tasks, int $limit, ?Cursor $cursor): Page
    {
        $remaining = $tasks;

        if (null !== $cursor) {
            $position = $this->position($cursor->sortKey, $cursor->id);
            $after = [];
            $passed = false;

            foreach ($tasks as $task) {
                if (!$passed) {
                    if ($this->position($task->orderKey(), $task->uid) === $position) {
                        $passed = true;
                    }

                    continue;
                }

                $after[] = $task;
            }

            $remaining = $passed ? $after : array_values(array_filter(
                $tasks,
                fn (CalendarTask $task): bool => $this->position($task->orderKey(), $task->uid) > $position,
            ));
        }

        if (\count($remaining) <= $limit) {
            return new Page(tasks: $remaining, hasMore: false, nextCursor: null);
        }

        $page = \array_slice($remaining, 0, $limit);
        $last = $page[\count($page) - 1];

        return new Page(
            tasks: $page,
            hasMore: true,
            nextCursor: new Cursor($last->orderKey(), $last->uid),
        );
    }

    /**
     * Everything strictly after the cursor's position.
     *
     * Comparison is on `(orderKey, id)`, matching the listing order, so two
     * rows sharing a start instant are still paged deterministically.
     *
     * @param list<CalendarEvent> $events
     *
     * @return list<CalendarEvent>
     */
    private function after(array $events, Cursor $cursor): array
    {
        $position = $this->position($cursor->sortKey, $cursor->id);
        $out = [];
        $passed = false;

        foreach ($events as $event) {
            if (!$passed) {
                if ($this->position($event->orderKey(), $event->id) === $position) {
                    $passed = true;
                }

                continue;
            }

            $out[] = $event;
        }

        // The cursor's row is gone — deleted, or moved out of the window
        // between pages. Resuming by comparison rather than by position still
        // yields a correct continuation: everything that sorts after the
        // remembered point.
        if (!$passed) {
            $out = [];

            foreach ($events as $event) {
                if ($this->position($event->orderKey(), $event->id) > $position) {
                    $out[] = $event;
                }
            }
        }

        return $out;
    }

    private function position(string $orderKey, string $id): string
    {
        return $orderKey."\u{1F}".$id;
    }
}
