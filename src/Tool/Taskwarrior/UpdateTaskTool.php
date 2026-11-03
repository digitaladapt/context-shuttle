<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

use InvalidArgumentException;

/**
 * `taskwarrior_update_task` — update one or more attributes of an existing
 * taskwarrior task by its id.
 *
 * All fields are optional; only the ones provided are changed. Pass
 * `tags_add` and `tags_remove` to manipulate tags without replacing them
 * wholesale.
 */
final class UpdateTaskTool
{
    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    /**
     * @param int               $id          the task id (required)
     * @param string|null       $description new description
     * @param string|null       $project     new project name
     * @param list<string>|null $tags_add    tags to add
     * @param list<string>|null $tags_remove tags to remove
     * @param string|null       $due         new due date
     * @param string|null       $priority    new priority: U, L, M, H
     * @param string|null       $end         new deadline
     * @param string|null       $status      status change: pending, completed, deleted
     *
     * @return array{updated: bool, id: int}
     */
    public function updateTask(
        int $id,
        ?string $description = null,
        ?string $project = null,
        ?array $tags_add = null,
        ?array $tags_remove = null,
        ?string $due = null,
        ?string $priority = null,
        ?string $end = null,
        ?string $status = null,
    ): array {
        if (0 === $id) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }

        $resolvedPriority = $priority;
        if (null !== $resolvedPriority && !\in_array(strtoupper($resolvedPriority), ['U', 'L', 'M', 'H'], true)) {
            throw new InvalidArgumentException('priority must be one of: U, L, M, H (or omitted).');
        }

        $resolvedStatus = $status;
        if (null !== $resolvedStatus && !\in_array($resolvedStatus, ['pending', 'completed', 'deleted'], true)) {
            throw new InvalidArgumentException('status must be one of: pending, completed, deleted (or omitted).');
        }

        $resolvedTagsAdd = $tags_add ?? [];
        $resolvedTagsRemove = $tags_remove ?? [];

        return $this->service->update([
            'id' => $id,
            'description' => $description,
            'project' => $project,
            'tags_add' => $resolvedTagsAdd,
            'tags_remove' => $resolvedTagsRemove,
            'due' => $due,
            'priority' => $resolvedPriority,
            'end' => $end,
            'status' => $resolvedStatus,
        ]);
    }
}
