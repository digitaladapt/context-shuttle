<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

use InvalidArgumentException;

/**
 * `taskwarrior_complete_task` — mark a taskwarrior task as completed.
 *
 * An optional annotation string is recorded alongside the completion.
 * The task must exist and be in a pending state; deleting is done via
 * `taskwarrior_update_task` with `status: deleted`.
 */
final class CompleteTaskTool
{
    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    /**
     * @return array{completed: bool, id: int}
     */
    public function completeTask(int $id, ?string $annotation = null): array
    {
        if (0 === $id) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }

        return $this->service->complete($id, $annotation);
    }
}
