<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

use InvalidArgumentException;

/**
 * `taskwarrior_update_task` — update one or more attributes of an existing
 * taskwarrior task, addressed by numeric id or uuid.
 *
 * All fields are optional; only the ones provided are changed. Pass
 * `tags_add` and `tags_remove` to manipulate tags without replacing them
 * wholesale. Prefer uuid addressing: numeric ids renumber after every
 * completion/deletion, so a stale list can point at the wrong task.
 */
final class UpdateTaskTool
{
    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    /**
     * @param int|string|null   $id          the task id, or null when addressing by uuid
     * @param string|null       $uuid        the task uuid (preferred over id)
     * @param string|null       $description new description
     * @param string|null       $project     new project name
     * @param list<string>|null $tags_add    tags to add
     * @param list<string>|null $tags_remove tags to remove
     * @param string|null       $due         new due date
     * @param string|null       $priority    new priority: L, M, H
     * @param string|null       $end         new deadline (maps to taskwarrior `until`)
     * @param string|null       $status      status change: pending, completed, deleted
     *
     * @return array{updated: bool, id: int, uuid: string}
     */
    public function updateTask(
        int|string|null $id = null,
        ?string $uuid = null,
        ?string $description = null,
        ?string $project = null,
        ?array $tags_add = null,
        ?array $tags_remove = null,
        ?string $due = null,
        ?string $priority = null,
        ?string $end = null,
        ?string $status = null,
    ): array {
        $resolvedId = null === $id ? null : (int) $id;
        if (null !== $resolvedId && $resolvedId < 1) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }
        if (null === $resolvedId && (null === $uuid || '' === $uuid)) {
            throw new InvalidArgumentException('A task id (positive integer) or uuid is required.');
        }

        $resolvedPriority = $priority;
        if (null !== $resolvedPriority && !\in_array(strtoupper($resolvedPriority), ['L', 'M', 'H'], true)) {
            throw new InvalidArgumentException('priority must be one of: L, M, H (or omitted).');
        }

        $resolvedStatus = $status;
        if (null !== $resolvedStatus && !\in_array($resolvedStatus, ['pending', 'completed', 'deleted'], true)) {
            throw new InvalidArgumentException('status must be one of: pending, completed, deleted (or omitted).');
        }

        $resolvedTagsAdd = $tags_add ?? [];
        $resolvedTagsRemove = $tags_remove ?? [];

        return $this->service->update(
            \array_filter([
                'id' => $resolvedId,
                'uuid' => $uuid,
            ], static fn ($v) => null !== $v)
            + [
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
