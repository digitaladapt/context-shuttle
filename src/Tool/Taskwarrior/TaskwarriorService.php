<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Parses a user-supplied date string into an ISO Y-M-D date, with the
 * same month-name and relative forms taskwarrior accepts.
 *
 * Why this exists (#41 bug A): a token like `due:nov 15` reaches task
 * as a single argv element, and its date parser drops the day-of-month
 * and resolves to the 1st of the named month — silently, exit 0. The
 * tool schema documents `"nov 15"` as a valid input, so the silent
 * wrong date was the encouraged path. PHP's parser handles these
 * forms correctly, so dates are normalized here before they reach the
 * CLI, and anything unparseable fails loudly instead of being coerced.
 */
function normalizeDate(string $value): string
{
    $trimmed = trim($value);

    // Already ISO Y-M-D: pass through untouched.
    if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
        return $trimmed;
    }

    $ts = strtotime($trimmed);
    if (false === $ts) {
        throw new InvalidArgumentException(sprintf(
            'Invalid date "%s": expected an ISO date (YYYY-MM-DD) or a natural-language date taskwarrior accepts (e.g. "tomorrow", "friday", "nov 15").',
            $value,
        ));
    }

    return (new DateTimeImmutable('@'.$ts))->format('Y-m-d');
}

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

        $result = $this->runSync();

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
            $cmd[] = 'due:'.normalizeDate($attrs['due']);
        }

        if (isset($attrs['priority'])) {
            $cmd[] = 'priority:'.self::validatePriority($attrs['priority']);
        }

        if (isset($attrs['end'])) {
            // `end` in the tool schema means a deadline (when the task
            // stops being relevant), which is taskwarrior's `until` —
            // taskwarrior's own `end` is the completion timestamp and
            // cannot be set on a pending task (#41 bug B).
            $cmd[] = 'until:'.normalizeDate($attrs['end']);
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
        $uuid = $this->resolveTarget($attrs);
        $id = (int) ($attrs['id'] ?? 0);

        // Bug E (#41): an update that changes nothing reports success
        // while writing nothing — a caller bug that should be loud.
        $changeKeys = ['description', 'project', 'tags_add', 'tags_remove', 'due', 'priority', 'end', 'status'];
        $hasChange = false;
        foreach ($changeKeys as $key) {
            if (\array_key_exists($key, $attrs)
                && null !== $attrs[$key]
                && ([] !== $attrs[$key] || \is_string($attrs[$key]))) {
                $hasChange = true;
                break;
            }
        }
        if (!$hasChange) {
            throw new InvalidArgumentException('update requires at least one field to change (description, project, tags_add, tags_remove, due, priority, end, or status).');
        }

        $this->syncBefore();

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
            $cmd[] = 'due:'.normalizeDate($attrs['due']);
        }

        if (isset($attrs['priority'])) {
            $cmd[] = 'priority:'.self::validatePriority($attrs['priority']);
        }

        if (isset($attrs['end'])) {
            // See create(): the schema's `end` maps to `until` (#41 bug B).
            $cmd[] = 'until:'.normalizeDate($attrs['end']);
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
            'id' => (int) ($task['id'] ?? $id),
            'uuid' => $uuid,
            'task' => $task,
        ];
    }

    /**
     * Complete a task.
     *
     * @return array{completed: bool, id: int, uuid: string, task: array<string, mixed>}
     */
    public function complete(array $target, ?string $annotation = null): array
    {
        $uuid = $this->resolveTarget($target);
        $id = (int) ($target['id'] ?? 0);

        $this->syncBefore();

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
            'id' => (int) ($task['id'] ?? $id),
            'uuid' => $uuid,
            'task' => $task,
        ];
    }

    /**
     * Delete a task.
     *
     * @return array{deleted: bool, id: int, uuid: string, task: array<string, mixed>}
     */
    public function delete(array $target): array
    {
        $uuid = $this->resolveTarget($target);
        $id = (int) ($target['id'] ?? 0);

        $this->syncBefore();

        $this->run([self::TASK_BINARY, 'rc.confirmation=off', $uuid, 'delete']);

        $task = $this->fetchByUuid($uuid);
        $this->syncAfter();

        return [
            'deleted' => true,
            'id' => (int) ($task['id'] ?? $id),
            'uuid' => $uuid,
            'task' => $task,
        ];
    }

    /**
     * Bug C (#41): priority "U" looks like "urgency" in the schema but
     * taskwarrior rejects it at the CLI with exit 2, surfacing as a
     * generic command failure. Validate it here for a clear error.
     */
    private static function validatePriority(string $priority): string
    {
        $upper = strtoupper($priority);
        if (!\in_array($upper, ['L', 'M', 'H'], true)) {
            throw new InvalidArgumentException('priority must be one of: L, M, H.');
        }

        return $upper;
    }

    /**
     * Resolve a task target — numeric id or UUID — to the task's UUID.
     *
     * Bug D (#41): numeric ids only address the pending working set and
     * renumber after every completion/deletion, so an agent acting on a
     * stale list could delete the wrong task. Every task object returned
     * by list_tasks carries its uuid; callers that have one should pass
     * it. Exactly one of id/uuid must be provided.
     *
     * @param array{id?: int|string, uuid?: string} $target
     */
    private function resolveTarget(array $target): string
    {
        $uuid = $target['uuid'] ?? null;
        $id = isset($target['id']) ? (int) $target['id'] : 0;

        if (null !== $uuid && '' !== $uuid) {
            if ($id > 0) {
                throw new InvalidArgumentException('Pass either id or uuid, not both.');
            }

            return $uuid;
        }

        if (0 === $id) {
            throw new InvalidArgumentException('A task id (positive integer) or uuid is required.');
        }

        return $this->resolveIdToUuid($id);
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
    private function runSync(): array
    {
        if ('' === $this->syncUrl || '' === $this->syncClientId || '' === $this->syncSecret) {
            return ['code' => 0];
        }

        // Taskwarrior 3 reads sync config from the taskrc (sync.server.url,
        // sync.server.client_id, sync.encryption_secret — written there by
        // the container entrypoint from TASKCHAMPION_* env vars). The old
        // TW2-style `task sync pre on <url> ...` invocation is invalid in
        // TW3 and was the root cause of "No sync.* settings are configured"
        // while the wrapper believed sync was configured (#42).
        $cmd = [
            self::TASK_BINARY,
            'sync',
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

        $result = $this->runSync();
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

        $this->runSync();
    }
}
