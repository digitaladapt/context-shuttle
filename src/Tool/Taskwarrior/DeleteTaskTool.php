<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

use InvalidArgumentException;

/**
 * `taskwarrior_delete_task` — permanently remove a taskwarrior task.
 *
 * This is destructive: the task is removed from the local database and
 * synced out. There is no undo except restoring from a sync-server backup.
 * Use `taskwarrior_update_task` with `status: deleted` if you want the
 * task to contain in the completed/deleted list for recovery. Prefer
 * uuid addressing: numeric ids renumber after every completion/deletion,
 * so a stale list can point at the wrong task.
 */
final class DeleteTaskTool
{
    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    /**
     * @param int|string|null $id   the task id, or null when addressing by uuid
     * @param string|null     $uuid the task uuid (preferred over id)
     *
     * @return array{deleted: bool, id: int, uuid: string}
     */
    public function deleteTask(
        int|string|null $id = null,
        ?string $uuid = null,
    ): array {
        $resolvedId = null === $id ? null : (int) $id;
        if (null !== $resolvedId && $resolvedId < 1) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }
        if (null === $resolvedId && (null === $uuid || '' === $uuid)) {
            throw new InvalidArgumentException('A task id (positive integer) or uuid is required.');
        }

        $target = \array_filter(['id' => $resolvedId, 'uuid' => $uuid], static fn ($v) => null !== $v);

        return $this->service->delete($target);
    }
}
