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
     * @param array{
     *     id: int,
     *     description?: string,
     *     project?: string,
     *     tags_add?: list<string>,
     *     tags_remove?: list<string>,
     *     due?: string,
     *     priority?: string,
     *     end?: string,
     *     status?: string,
     * } $attrs
     *
     * @return array{updated: bool, id: int}
     */
    public function updateTask(array $attrs): array
    {
        $id = (int) ($attrs['id'] ?? 0);
        if (0 === $id) {
            throw new InvalidArgumentException('id is required and must be a positive integer.');
        }

        $priority = $attrs['priority'] ?? null;
        if (null !== $priority && !\in_array(strtoupper($priority), ['U', 'L', 'M', 'H'], true)) {
            throw new InvalidArgumentException('priority must be one of: U, L, M, H (or omitted).');
        }

        $status = $attrs['status'] ?? null;
        if (null !== $status && !\in_array($status, ['pending', 'completed', 'deleted'], true)) {
            throw new InvalidArgumentException('status must be one of: pending, completed, deleted (or omitted).');
        }

        $tagsAdd = $attrs['tags_add'] ?? [];
        $tagsRemove = $attrs['tags_remove'] ?? [];
        if (!\is_array($tagsAdd) || !\is_array($tagsRemove)) {
            throw new InvalidArgumentException('tags_add and tags_remove must be lists of strings.');
        }

        return $this->service->update([
            'id' => $id,
            'description' => $attrs['description'] ?? null,
            'project' => $attrs['project'] ?? null,
            'tags_add' => $tagsAdd,
            'tags_remove' => $tagsRemove,
            'due' => $attrs['due'] ?? null,
            'priority' => $priority,
            'end' => $attrs['end'] ?? null,
            'status' => $status,
        ]);
    }
}
