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
 * task to remain in the completed/deleted list for recovery.
 */
final class DeleteTaskTool
{
    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    /**
     * @return array{deleted: bool, id: int}
     */
    public function deleteTask(int $id): array
    {
        if (0 === $id) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }

        return $this->service->delete($id);
    }
}
