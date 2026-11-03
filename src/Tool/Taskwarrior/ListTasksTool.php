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
     * @param array{
     *     project?: string,
     *     tag?: list<string>,
     *     status?: string,
     *     limit?: int,
     *     include_completed?: bool,
     * } $filters
     *
     * @return array{tasks: list<array<string, mixed>>, count: int, has_more: bool}
     */
    public function listTasks(array $filters = []): array
    {
        $limit = $filters['limit'] ?? self::DEFAULT_LIMIT;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(\sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        $status = $filters['status'] ?? 'pending';
        if (!\in_array($status, ['pending', 'all'], true)) {
            throw new InvalidArgumentException('status must be "pending" or "all".');
        }

        $tags = $filters['tag'] ?? [];
        if (!\is_array($tags)) {
            throw new InvalidArgumentException('tag must be a list of strings.');
        }

        $result = $this->service->list([
            'status' => $status,
            'limit' => $limit,
            'project' => $filters['project'] ?? null,
            'tag' => $tags,
            'include_completed' => $filters['include_completed'] ?? false,
        ]);

        // If include_completed is set and status was pending, switch to 'all'.
        if (($filters['include_completed'] ?? false) && 'pending' === $status) {
            $result = $this->service->list([
                'status' => 'all',
                'limit' => $limit,
                'project' => $filters['project'] ?? null,
                'tag' => $tags,
                'include_completed' => true,
            ]);
        }

        return $result;
    }
}
