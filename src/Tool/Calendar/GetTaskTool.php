<?php

declare(strict_types=1);

namespace App\Tool\Calendar;

use App\Calendar\CalendarReader;
use App\Calendar\Domain\CalendarProblem;
use App\Calendar\Domain\CalendarTask;
use App\Calendar\Domain\TimeZoneRule;
use InvalidArgumentException;
use RuntimeException;

/**
 * `calendar_get_task` — one task by UID.
 *
 * A task has no occurrences, so there is nothing to disambiguate and the
 * UID is the whole address. A composite id from the event tools is accepted
 * but only its UID half is used, so a caller that reuses an id by mistake
 * gets the right task rather than an error.
 */
final readonly class GetTaskTool
{
    public function __construct(
        private CalendarReader $reader,
        private TimeZoneRule $timeZone,
    ) {
    }

    /**
     * Fetch one task.
     *
     * @param string $id a task `id`/`uid` from a listing
     *
     * @return array<string, mixed>
     */
    public function getTask(string $id): array
    {
        $id = trim($id);

        if ('' === $id) {
            throw new InvalidArgumentException('id must not be empty. Pass back the id from a task listing.');
        }

        $result = $this->reader->getTask($id);

        $tasks = array_map(
            static fn (CalendarTask $task): array => $task->toArray(),
            $result['tasks'],
        );

        if ([] === $tasks) {
            // Not found is an error naming the id, not an empty list that
            // looks like a valid answer.
            throw new RuntimeException(\sprintf('No task found with id "%s". The id may be stale, or may belong to a calendar this deployment does not expose.', $id));
        }

        $payload = [
            'tasks' => $tasks,
            'count' => \count($tasks),
            'timezone' => $this->timeZone->name(),
        ];

        $errors = $this->describeProblems($result['problems']);

        if (null !== $errors) {
            $payload['errors'] = $errors;
        }

        return $payload;
    }

    /**
     * @param list<CalendarProblem> $problems
     */
    private function describeProblems(array $problems): ?string
    {
        $count = \count($problems);

        if (0 === $count) {
            return null;
        }

        return \sprintf('There were %d malformed tasks', $count);
    }
}
