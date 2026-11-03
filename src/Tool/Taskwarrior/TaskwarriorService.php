<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Wraps the `task` CLI for all taskwarrior tools.
 *
 * Handles optional sync with a taskchampion-sync-server before and after
 * mutations. Sync is enabled when TASKCHAMPION_SYNC_URL is set; when it is
 * absent the tools work against the local `.taskrc` / `~/.task` only.
 *
 * All output is collected from `exec()` and decoded as JSON where possible.
 * A non-zero exit code or empty output becomes a descriptive RuntimeException.
 */
final class TaskwarriorService
{
    private const SYNC_PRE = 'sync pre';
    private const SYNC_POST = 'sync post';

    private const TASK_BINARY = '/usr/local/bin/task';

    public function __construct(
        private readonly string $syncUrl,
        private readonly string $syncClientId,
        private readonly string $syncSecret,
    ) {
    }

    /**
     * Sync state: whether sync is configured and whether it succeeded last.
     *
     * @return array{configured: bool, last_sync: string|null, last_status: int|null, last_error: string|null}
     */
    public function syncStatus(): array
    {
        if ('' === $this->syncUrl) {
            return [
                'configured' => false,
                'last_sync' => null,
                'last_status' => null,
                'last_error' => null,
            ];
        }

        $result = $this->runSync(self::SYNC_PRE);

        return [
            'configured' => true,
            'last_sync' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'last_status' => $result['code'],
            'last_error' => $result['error'] ?? null,
        ];
    }

    /**
     * List tasks with optional filters.
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
    public function list(array $filters = []): array
    {
        $cmd = [self::TASK_BINARY, 'rc.jsonOutput=on', 'export'];

        $status = $filters['status'] ?? 'pending';
        if ('all' !== $status) {
            $cmd[] = "+{$status}";
        }

        if (isset($filters['project'])) {
            $cmd[] = '+PROJECT:'.escapeshellarg($filters['project']);
        }

        $tags = $filters['tag'] ?? [];
        foreach ($tags as $tag) {
            $cmd[] = '+'.escapeshellarg($tag);
        }

        $limit = $filters['limit'] ?? 200;
        $cmd[] = 'limit:'.$limit;

        $output = $this->run($cmd);
        $tasks = json_decode($output, true);

        if (!\is_array($tasks)) {
            throw new RuntimeException(\sprintf('task export returned invalid JSON: %s', $output));
        }

        $total = \count($tasks);
        $hasMore = $total >= $limit;

        return [
            'tasks' => $tasks,
            'count' => $total,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Create a new task.
     *
     * @param array{
     *     description: string,
     *     project?: string,
     *     tags?: list<string>,
     *     due?: string,
     *     priority?: string,
     *     end?: string,
     * } $attrs
     *
     * @return array{created: bool, id: int, description: string}
     */
    public function create(array $attrs): array
    {
        $this->syncBefore();

        $desc = trim($attrs['description']);
        if ('' === $desc) {
            throw new InvalidArgumentException('description must not be empty.');
        }

        $cmd = [self::TASK_BINARY, 'add', '--', $desc];

        if (isset($attrs['project'])) {
            $cmd[] = 'project:'.escapeshellarg($attrs['project']);
        }

        $tags = $attrs['tags'] ?? [];
        foreach ($tags as $tag) {
            $cmd[] = '+'.escapeshellarg($tag);
        }

        if (isset($attrs['due'])) {
            $cmd[] = 'due:'.escapeshellarg($attrs['due']);
        }

        if (isset($attrs['priority'])) {
            $cmd[] = 'priority:'.escapeshellarg($attrs['priority']);
        }

        if (isset($attrs['end'])) {
            $cmd[] = 'end:'.escapeshellarg($attrs['end']);
        }

        $output = $this->run($cmd);
        $id = (int) trim($output);

        if (0 === $id) {
            throw new RuntimeException(\sprintf('task add returned id=0. Output was: %s', $output));
        }

        $this->syncAfter();

        return [
            'created' => true,
            'id' => $id,
            'description' => $desc,
        ];
    }

    /**
     * Update an existing task.
     *
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
    public function update(array $attrs): array
    {
        $id = (int) $attrs['id'];
        if (0 === $id) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }

        $this->syncBefore();

        $cmd = [self::TASK_BINARY, (string) $id, 'modify'];

        if (isset($attrs['description'])) {
            $cmd[] = 'description:'.escapeshellarg($attrs['description']);
        }

        if (isset($attrs['project'])) {
            $cmd[] = 'project:'.escapeshellarg($attrs['project']);
        }

        $tagsAdd = $attrs['tags_add'] ?? [];
        foreach ($tagsAdd as $tag) {
            $cmd[] = '+'.escapeshellarg($tag);
        }

        $tagsRemove = $attrs['tags_remove'] ?? [];
        foreach ($tagsRemove as $tag) {
            $cmd[] = '-'.escapeshellarg($tag);
        }

        if (isset($attrs['due'])) {
            $cmd[] = 'due:'.escapeshellarg($attrs['due']);
        }

        if (isset($attrs['priority'])) {
            $cmd[] = 'priority:'.escapeshellarg($attrs['priority']);
        }

        if (isset($attrs['end'])) {
            $cmd[] = 'end:'.escapeshellarg($attrs['end']);
        }

        if (isset($attrs['status'])) {
            match ($attrs['status']) {
                'pending' => $cmd[] = 'pending',
                'completed' => $cmd[] = 'done',
                'deleted' => $cmd[] = 'delete',
                default => throw new InvalidArgumentException(\sprintf('status must be one of: pending, completed, deleted (got %s)', $attrs['status'])),
            };
        }

        $output = $this->run($cmd);

        if ('' !== trim($output)) {
            throw new RuntimeException(\sprintf('task modify returned unexpected output: %s', trim($output)));
        }

        $this->syncAfter();

        return [
            'updated' => true,
            'id' => $id,
        ];
    }

    /**
     * Complete a task.
     *
     * @return array{completed: bool, id: int}
     */
    public function complete(int $id, ?string $annotation = null): array
    {
        if (0 === $id) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }

        $this->syncBefore();

        $cmd = [self::TASK_BINARY, (string) $id, 'done'];

        if (null !== $annotation) {
            $cmd[] = '--';
            $cmd[] = $annotation;
        }

        $output = $this->run($cmd);

        if (0 !== (int) trim($output)) {
            throw new RuntimeException(\sprintf('task done returned non-zero: %s', trim($output)));
        }

        $this->syncAfter();

        return [
            'completed' => true,
            'id' => $id,
        ];
    }

    /**
     * Delete a task.
     *
     * @return array{deleted: bool, id: int}
     */
    public function delete(int $id): array
    {
        if (0 === $id) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }

        $this->syncBefore();

        $cmd = [self::TASK_BINARY, (string) $id, 'delete'];
        $output = $this->run($cmd);

        if (0 !== (int) trim($output)) {
            throw new RuntimeException(\sprintf('task delete returned non-zero: %s', trim($output)));
        }

        $this->syncAfter();

        return [
            'deleted' => true,
            'id' => $id,
        ];
    }

    /**
     * Get the highest-urgency ready task.
     *
     * @return array<string, mixed>|null
     */
    public function nextAction(): ?array
    {
        $cmd = [self::TASK_BINARY, 'rc.jsonOutput=on', 'export', 'urgency', 'limit:1'];
        $output = $this->run($cmd);
        $tasks = json_decode($output, true);

        if (!\is_array($tasks) || [] === $tasks) {
            return null;
        }

        return $tasks[0];
    }

    /**
     * List all projects.
     *
     * @return list<string>
     */
    public function projectList(): array
    {
        $cmd = [self::TASK_BINARY, 'projects'];
        $output = $this->run($cmd);

        $projects = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ('' === $line || str_contains($line, 'project')) {
                continue;
            }
            $projects[] = $line;
        }

        return $projects;
    }

    /**
     * Run a single task command and return its stdout.
     *
     * @param list<string> $cmd
     */
    private function run(array $cmd): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(implode(' ', array_map('escapeshellarg', $cmd)), $descriptors, $pipes);

        if (!\is_resource($process)) {
            throw new RuntimeException(\sprintf('Failed to start process: %s', $cmd[0]));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $code = proc_close($process);

        if (0 !== $code) {
            $stderr = trim($stderr);
            throw new RuntimeException(\sprintf('Command failed (exit %d): %s%s', $code, trim($stdout), $stderr ? "\nSTDERR: $stderr" : ''));
        }

        return trim($stdout);
    }

    /**
     * Run a sync command and return status + optional error.
     *
     * @return array{code: int, error?: string}
     */
    private function runSync(string $operation): array
    {
        if ('' === $this->syncUrl || '' === $this->syncClientId || '' === $this->syncSecret) {
            return ['code' => 0];
        }

        $cmd = [
            self::TASK_BINARY,
            'sync',
            $operation,
            'on',
            $this->syncUrl,
            $this->syncClientId,
            $this->syncSecret,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(implode(' ', array_map('escapeshellarg', $cmd)), $descriptors, $pipes);

        if (!\is_resource($process)) {
            return ['code' => 1, 'error' => 'Failed to start sync process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $code = proc_close($process);

        $error = null;
        if (0 !== $code) {
            $error = trim($stderr ?: $stdout);
        }

        return ['code' => $code] + (null !== $error ? ['error' => $error] : []);
    }

    private function syncBefore(): void
    {
        if ('' === $this->syncUrl) {
            return;
        }

        $result = $this->runSync(self::SYNC_PRE);
        if (0 !== $result['code']) {
            // Non-fatal: continue with local changes even if sync fails.
            // The operator can retry manually.
        }
    }

    private function syncAfter(): void
    {
        if ('' === $this->syncUrl) {
            return;
        }

        $this->runSync(self::SYNC_POST);
    }
}
