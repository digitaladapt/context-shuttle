<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

use InvalidArgumentException;

/**
 * `taskwarrior_list_tasks` — list tasks from the local taskwarrior data,
 * optionally synced from the configured sync server.
 *
 * Pending tasks by default, with an optional filter for project, tag, and
 * status. Pass `include_completed` to also see done/deleted tasks.
 */
final readonly class ListTasksTool
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 500;

    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    /**
     * List tasks.
     *
     * @param string|null       $status            'pending' (default) or 'all'
     * @param string|null       $project           restrict to one project
     * @param list<string>|null $tag               restrict to tasks carrying all these tags
     * @param bool|null         $include_completed include completed/deleted tasks
     * @param int|null          $limit             page size, 1-500
     *
     * @return array{tasks: list<array<string, mixed>>, count: int, has_more: bool}
     */
    public function listTasks(
        ?string $status = null,
        ?string $project = null,
        ?array $tag = null,
        ?bool $include_completed = null,
        ?int $limit = null,
    ): array {
        $resolvedStatus = $status ?? 'pending';

        if (!\in_array($resolvedStatus, ['pending', 'all'], true)) {
            throw new InvalidArgumentException('status must be "pending" or "all".');
        }

        $resolvedTag = $tag ?? [];

        $resolvedLimit = $limit ?? self::DEFAULT_LIMIT;
        if ($resolvedLimit < 1 || $resolvedLimit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(\sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        $includeCompleted = $include_completed ?? false;

        return $this->service->list([
            'status' => $resolvedStatus,
            'project' => $project,
            'tag' => $resolvedTag,
            'limit' => $resolvedLimit,
            'include_completed' => $includeCompleted,
        ]);
    }
}
