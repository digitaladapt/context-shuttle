<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

use InvalidArgumentException;

/**
 * `taskwarrior_complete_task` — mark a taskwarrior task as completed.
 *
 * An optional annotation string is recorded alongside the completion.
 * The task must exist and be in a pending state; deleting is done via
 * `taskwarrior_update_task` with `status: deleted`. Prefer uuid
 * addressing: numeric ids renumber after every completion/deletion.
 */
final class CompleteTaskTool
{
    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    /**
     * @param int|string|null $id         the task id, or null when addressing by uuid
     * @param string|null     $uuid       the task uuid (preferred over id)
     * @param string|null     $annotation optional comment recorded with the completion
     *
     * @return array{completed: bool, id: int, uuid: string}
     */
    public function completeTask(
        int|string|null $id = null,
        ?string $uuid = null,
        ?string $annotation = null,
    ): array {
        $resolvedId = null === $id ? null : (int) $id;
        if (null !== $resolvedId && $resolvedId < 1) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }
        if (null === $resolvedId && (null === $uuid || '' === $uuid)) {
            throw new InvalidArgumentException('A task id (positive integer) or uuid is required.');
        }

        $target = \array_filter(['id' => $resolvedId, 'uuid' => $uuid], static fn ($v) => null !== $v);

        return $this->service->complete($target, $annotation);
    }
}
