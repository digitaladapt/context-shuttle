<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

use InvalidArgumentException;

/**
 * `taskwarrior_create_task` — create a new pending task in taskwarrior.
 *
 * Description is the only required field. Project, tags, due date, priority,
 * and end date are optional. A due date of "today" or "tomorrow" is accepted;
 * any date string taskwarrior understands is valid.
 */
final class CreateTaskTool
{
    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    /**
     * @param array{
     *     description: string,
     *     project?: string,
     *     tags?: list<string>,
     *     due?: string,
     *     priority?: string,
     *     end?: string,
     * } $attrs
     *
     * @return array{created: bool, id: int, description: string}
     */
    public function createTask(array $attrs): array
    {
        $desc = trim($attrs['description'] ?? '');
        if ('' === $desc) {
            throw new InvalidArgumentException('description must not be empty.');
        }

        $tags = $attrs['tags'] ?? [];
        if (!\is_array($tags)) {
            throw new InvalidArgumentException('tags must be a list of strings.');
        }

        $priority = $attrs['priority'] ?? null;
        if (null !== $priority && !\in_array(strtoupper($priority), ['U', 'L', 'M', 'H'], true)) {
            throw new InvalidArgumentException('priority must be one of: U, L, M, H (or omitted).');
        }

        $due = $attrs['due'] ?? null;
        $end = $attrs['end'] ?? null;

        return $this->service->create([
            'description' => $desc,
            'project' => $attrs['project'] ?? null,
            'tags' => $tags,
            'due' => $due,
            'priority' => $priority,
            'end' => $end,
        ]);
    }
}
