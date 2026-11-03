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
     * @param string            $description the task description (required)
     * @param string|null       $project     optional project name
     * @param list<string>|null $tags        optional tags
     * @param string|null       $due         optional due date
     * @param string|null       $priority    optional priority: U, L, M, H
     * @param string|null       $end         optional deadline
     *
     * @return array{created: bool, id: int, description: string}
     */
    public function createTask(
        string $description,
        ?string $project = null,
        ?array $tags = null,
        ?string $due = null,
        ?string $priority = null,
        ?string $end = null,
    ): array {
        $desc = trim($description);
        if ('' === $desc) {
            throw new InvalidArgumentException('description must not be empty.');
        }

        $resolvedTags = $tags ?? [];

        $resolvedPriority = $priority;
        if (null !== $resolvedPriority && !\in_array(strtoupper($resolvedPriority), ['U', 'L', 'M', 'H'], true)) {
            throw new InvalidArgumentException('priority must be one of: U, L, M, H (or omitted).');
        }

        return $this->service->create([
            'description' => $desc,
            'project' => $project,
            'tags' => $resolvedTags,
            'due' => $due,
            'priority' => $resolvedPriority,
            'end' => $end,
        ]);
    }
}
