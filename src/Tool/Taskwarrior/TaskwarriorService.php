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
 * Conventions, learned the hard way:
 *
 * - `task` reports success through its *exit code*; its stdout is human
 *   prose ("Created task 3.") and must never be parsed as data or as a
 *   status. Machine-readable output comes from `export` with
 *   `rc.json.array=on`, and from `_projects` / `_get`.
 * - Arguments are escaped exactly once, here in run(); callers build the
 *   argv array and never pre-escape. A bare word passed to `add` or
 *   `modify` replaces the task's *description*, so a mis-escaped
 *   `project:'x'` token silently corrupts the task.
 * - Attribute tokens (project:, due:, +tag) go *before* the `--` marker;
 *   the description goes after it.
 * - Numeric task ids only address the pending working set. Completed and
 *   deleted tasks keep their UUID forever, so mutations resolve the id to
 *   a UUID first and operate on that.
 * - `rc.confirmation=off` keeps `delete` (and `modify status:deleted`)
 *   from prompting; stdin is closed, so a prompt would mean an EOF and a
 *   guaranteed failure.
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
        // Filter args must precede the command: `task status:pending
        // export` filters, while `task export status:pending` silently
        // ignores the filter and returns every status (the root of the
        // old `+pending` bug, which also sat after the command).
        // `status:pending` is the real status filter. With no status
        // filter at all, export returns pending, completed and deleted
        // alike — which is what include_completed and status:all ask for.
        $cmd = [self::TASK_BINARY, 'rc.json.array=on'];

        $status = $filters['status'] ?? 'pending';
        $includeCompleted = $filters['include_completed'] ?? false;
        if ('all' !== $status && !$includeCompleted) {
            $cmd[] = 'status:pending';
        }

        if (isset($filters['project'])) {
            $cmd[] = 'project:'.$filters['project'];
        }

        $tags = $filters['tag'] ?? [];
        foreach ($tags as $tag) {
            $cmd[] = '+'.$tag;
        }

        $cmd[] = 'export';

        $tasks = $this->runJson($cmd);

        // `limit:N` is deliberately not passed to the CLI: with no other
        // filter it makes taskwarrior return an empty set, and even
        // paired with one it cannot distinguish "exactly N" from "more
        // than N". Export returns the whole matching set — small, for a
        // personal database — so the page is cut in PHP instead.
        $limit = $filters['limit'] ?? 200;
        if ($limit < 1) {
            $limit = 1;
        }

        $hasMore = \count($tasks) > $limit;
        if ($hasMore) {
            $tasks = \array_slice($tasks, 0, $limit);
        }

        return [
            'tasks' => $tasks,
            'count' => \count($tasks),
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
     * @return array{created: bool, id: int, uuid: string, description: string, task: array<string, mixed>}
     */
    public function create(array $attrs): array
    {
        $this->syncBefore();

        $desc = trim($attrs['description']);
        if ('' === $desc) {
            throw new InvalidArgumentException('description must not be empty.');
        }

        // Attribute tokens before `--`, description after it. `task add`
        // prints "Created task N." to stdout — the N is not reliably
        // parseable, so the created task is read back via +LATEST instead.
        $cmd = [self::TASK_BINARY, 'rc.confirmation=off', 'add'];

        if (isset($attrs['project'])) {
            $cmd[] = 'project:'.$attrs['project'];
        }

        $tags = $attrs['tags'] ?? [];
        foreach ($tags as $tag) {
            $cmd[] = '+'.$tag;
        }

        if (isset($attrs['due'])) {
            $cmd[] = 'due:'.$attrs['due'];
        }

        if (isset($attrs['priority'])) {
            $cmd[] = 'priority:'.$attrs['priority'];
        }

        if (isset($attrs['end'])) {
            $cmd[] = 'end:'.$attrs['end'];
        }

        $cmd[] = '--';
        $cmd[] = $desc;

        $this->run($cmd);

        $task = $this->runJson([self::TASK_BINARY, 'rc.json.array=on', '+LATEST', 'export'])[0]
            ?? throw new RuntimeException('task add succeeded but the created task could not be read back.');

        $this->syncAfter();

        return [
            'created' => true,
            'id' => (int) ($task['id'] ?? 0),
            'uuid' => (string) $task['uuid'],
            'description' => $desc,
            'task' => $task,
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
     * @return array{updated: bool, id: int, uuid: string, task: array<string, mixed>}
     */
    public function update(array $attrs): array
    {
        $id = (int) $attrs['id'];
        if (0 === $id) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }

        $this->syncBefore();

        $uuid = $this->resolveIdToUuid($id);

        $cmd = [self::TASK_BINARY, 'rc.confirmation=off', $uuid, 'modify'];

        if (isset($attrs['project'])) {
            $cmd[] = 'project:'.$attrs['project'];
        }

        $tagsAdd = $attrs['tags_add'] ?? [];
        foreach ($tagsAdd as $tag) {
            $cmd[] = '+'.$tag;
        }

        $tagsRemove = $attrs['tags_remove'] ?? [];
        foreach ($tagsRemove as $tag) {
            $cmd[] = '-'.$tag;
        }

        if (isset($attrs['due'])) {
            $cmd[] = 'due:'.$attrs['due'];
        }

        if (isset($attrs['priority'])) {
            $cmd[] = 'priority:'.$attrs['priority'];
        }

        if (isset($attrs['end'])) {
            $cmd[] = 'end:'.$attrs['end'];
        }

        if (isset($attrs['status'])) {
            match ($attrs['status']) {
                'pending' => $cmd[] = 'status:pending',
                'completed' => $cmd[] = 'status:completed',
                'deleted' => $cmd[] = 'status:deleted',
                default => throw new InvalidArgumentException(\sprintf('status must be one of: pending, completed, deleted (got %s)', $attrs['status'])),
            };
        }

        if (isset($attrs['description'])) {
            $cmd[] = '--';
            $cmd[] = $attrs['description'];
        }

        $this->run($cmd);

        $task = $this->fetchByUuid($uuid);
        $this->syncAfter();

        return [
            'updated' => true,
            'id' => $id,
            'uuid' => $uuid,
            'task' => $task,
        ];
    }

    /**
     * Complete a task.
     *
     * @return array{completed: bool, id: int, uuid: string, task: array<string, mixed>}
     */
    public function complete(int $id, ?string $annotation = null): array
    {
        if (0 === $id) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }

        $this->syncBefore();

        $uuid = $this->resolveIdToUuid($id);

        $cmd = [self::TASK_BINARY, 'rc.confirmation=off', $uuid, 'done'];

        if (null !== $annotation) {
            $cmd[] = '--';
            $cmd[] = $annotation;
        }

        $this->run($cmd);

        $task = $this->fetchByUuid($uuid);
        $this->syncAfter();

        return [
            'completed' => true,
            'id' => $id,
            'uuid' => $uuid,
            'task' => $task,
        ];
    }

    /**
     * Delete a task.
     *
     * @return array{deleted: bool, id: int, uuid: string, task: array<string, mixed>}
     */
    public function delete(int $id): array
    {
        if (0 === $id) {
            throw new InvalidArgumentException('id must be a positive integer.');
        }

        $this->syncBefore();

        $uuid = $this->resolveIdToUuid($id);

        $this->run([self::TASK_BINARY, 'rc.confirmation=off', $uuid, 'delete']);

        $task = $this->fetchByUuid($uuid);
        $this->syncAfter();

        return [
            'deleted' => true,
            'id' => $id,
            'uuid' => $uuid,
            'task' => $task,
        ];
    }

    /**
     * Get the highest-urgency ready pending task.
     *
     * Equivalent to `task next`: pending, not blocked, not waiting, sorted
     * by descending urgency. Returns null when nothing is ready.
     *
     * @return array<string, mixed>|null
     */
    public function nextAction(): ?array
    {
        $tasks = $this->runJson([self::TASK_BINARY, 'rc.json.array=on', 'status:pending', '+READY', 'export']);

        if ([] === $tasks) {
            return null;
        }

        usort($tasks, static fn (array $a, array $b): int => ($b['urgency'] ?? 0) <=> ($a['urgency'] ?? 0));

        return $tasks[0];
    }

    /**
     * List all projects.
     *
     * `_projects` is the machine-readable form: one project per line,
     * exit 0 even when there are none. The `projects` *report* used here
     * before exits 1 with "No projects." on an empty working set.
     *
     * @return list<string>
     */
    public function projectList(): array
    {
        $output = $this->run([self::TASK_BINARY, '_projects']);

        $projects = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $projects[] = $line;
        }

        return $projects;
    }

    /**
     * Fetch a single task by its permanent UUID.
     *
     * @return array<string, mixed>
     */
    public function get(string $uuid): array
    {
        return $this->fetchByUuid($uuid);
    }

    /**
     * Resolve a pending numeric id to the task's permanent UUID.
     *
     * @return string the task's permanent UUID
     */
    private function resolveIdToUuid(int $id): string
    {
        $tasks = $this->runJson([self::TASK_BINARY, 'rc.json.array=on', (string) $id, 'export']);

        if ([] === $tasks) {
            throw new RuntimeException(\sprintf('No task with id %d. Numeric ids only address pending tasks; completed or deleted tasks cannot be selected this way.', $id));
        }

        return (string) $tasks[0]['uuid'];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchByUuid(string $uuid): array
    {
        $tasks = $this->runJson([self::TASK_BINARY, 'rc.json.array=on', $uuid, 'export']);

        if ([] === $tasks) {
            throw new RuntimeException(\sprintf('Task %s could not be read back after the change.', $uuid));
        }

        return $tasks[0];
    }

    /**
     * Run a task command, require exit 0, and return its trimmed stdout.
     *
     * Arguments are escaped here and only here — callers pass raw argv
     * elements. A non-zero exit becomes a RuntimeException carrying both
     * streams, since task's diagnostics can land on either.
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
            throw new RuntimeException(\sprintf('Command failed (exit %d): %s%s', $code, false === $stdout ? '' : trim($stdout), \is_string($stderr) && '' !== trim($stderr) ? "\nSTDERR: ".trim($stderr) : ''));
        }

        return false === $stdout ? '' : trim($stdout);
    }

    /**
     * Run a command and decode its stdout as a JSON array of tasks.
     *
     * @param list<string> $cmd
     *
     * @return list<array<string, mixed>>
     */
    private function runJson(array $cmd): array
    {
        $output = $this->run($cmd);
        $decoded = '' !== $output ? json_decode($output, true) : [];

        if (!\is_array($decoded)) {
            throw new RuntimeException(\sprintf('task returned invalid JSON: %s', $output));
        }

        return $decoded;
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
            $error = trim((\is_string($stderr) && '' !== trim($stderr)) ? $stderr : (string) $stdout);
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
