<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tool\Taskwarrior;

use App\Tool\Taskwarrior\TaskwarriorService;
use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The taskwarrior service, against a real `task` binary.
 *
 * The bugs this suite pins down were all invisible to the unit layer:
 * double shell-escaping turned attribute tokens into bare words (which
 * `task add`/`modify` treat as a new description), and stdout ("Created
 * task 3.") was parsed as if it were an exit code or a task id. Only a
 * round-trip through the real CLI catches those, so this is an
 * integration test with a throwaway TASKDATA/TASKRC — it never touches
 * the developer's own task database.
 *
 * It requires the `task` binary and skips otherwise; it is hermetic
 * otherwise (own taskrc with confirmation=off and color=off).
 *
 * @internal
 *
 * @covers \App\Tool\Taskwarrior\TaskwarriorService
 */
final class TaskwarriorServiceTest extends TestCase
{
    private string $tmpDir = '';

    private function svc(): TaskwarriorService
    {
        // TASKRC/TASKDATA are set per-test in setUp(); the service itself
        // is stateless, so a fresh instance per call is equivalent.
        return new TaskwarriorService('', '', '');
    }

    #[Override]
    public static function setUpBeforeClass(): void
    {
        if (!is_executable('/usr/local/bin/task')) {
            self::markTestSkipped('The task binary is not installed; skipping taskwarrior integration tests.');
        }
    }

    #[Override]
    protected function setUp(): void
    {
        $this->tmpDir = (string) realpath(sys_get_temp_dir()).'/taskwarrior-service-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir.'/data', 0777, true);

        file_put_contents($this->tmpDir.'/taskrc', <<<EOF
            data.location={$this->tmpDir}/data
            confirmation=off
            color=off
            verbose=nothing
            news.version=2.6.0
            EOF);

        putenv('TASKRC='.$this->tmpDir.'/taskrc');
        putenv('TASKDATA='.$this->tmpDir.'/data');
    }

    #[Override]
    protected function tearDown(): void
    {
        putenv('TASKRC');
        putenv('TASKDATA');

        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir.'/data');
        @rmdir($this->tmpDir);
    }

    public function test_list_defaults_to_pending_only(): void
    {
        $this->svc()->create(['description' => 'pending one']);
        $this->svc()->create(['description' => 'pending two']);
        $second = $this->svc()->list()['tasks'];
        $uuids = array_column($second, 'uuid');
        $this->svc()->complete(['id' => (int) $second[1]['id']], 'done with it');

        $result = $this->svc()->list();

        self::assertSame(['pending one'], array_column($result['tasks'], 'description'));
        self::assertFalse($result['has_more']);
        self::assertCount(2, $uuids);
    }

    public function test_include_completed_and_status_all_show_finished_tasks(): void
    {
        $created = $this->svc()->create(['description' => 'do and finish']);
        $this->svc()->complete(['uuid' => $created['uuid']]);

        foreach ([['include_completed' => true], ['status' => 'all']] as $filters) {
            $result = $this->svc()->list($filters);
            self::assertSame(['do and finish'], array_column($result['tasks'], 'description'));
        }

        // default stays pending-only
        self::assertSame([], $this->svc()->list()['tasks']);
    }

    public function test_create_sets_attributes_without_corrupting_the_description(): void
    {
        $result = $this->svc()->create([
            'description' => 'Tool test: full attribute create',
            'project' => 'tools-test',
            'tags' => ['testing', 'smoke'],
            'due' => '2026-11-15',
            'priority' => 'H',
            'end' => '2026-11-30',
        ]);

        $task = $result['task'];

        self::assertTrue($result['created']);
        self::assertSame('Tool test: full attribute create', $task['description']);
        self::assertSame('tools-test', $task['project']);
        $tags = $task['tags'];
        sort($tags);
        self::assertSame(['smoke', 'testing'], $tags);
        self::assertSame('H', $task['priority']);
        self::assertNotEmpty($task['due']);
        // `end` is taskwarrior's completion timestamp, not a deadline:
        // it is only set once the task is done. The `end:` attribute is
        // accepted at creation but does not appear on a pending task.
        self::assertArrayNotHasKey('end', $task);
        self::assertSame('pending', $task['status']);
        self::assertNotSame('', $result['uuid']);
        self::assertGreaterThan(0, $result['id']);
    }

    public function test_create_reports_success_rather_than_an_error(): void
    {
        // The old code parsed "Created task 3." as an int (0) and threw
        // after the task had already been created.
        $result = $this->svc()->create(['description' => 'no phantom error']);

        self::assertTrue($result['created']);
        self::assertGreaterThan(0, $result['id']);
    }

    public function test_update_changes_attributes_and_keeps_the_description(): void
    {
        $created = $this->svc()->create([
            'description' => 'before update',
            'project' => 'old-project',
        ]);

        $this->svc()->update([
            'id' => $created['id'],
            'project' => 'new-project',
            'priority' => 'M',
            'tags_add' => ['verify'],
        ]);

        $task = $this->svc()->get($created['uuid']);

        self::assertSame('before update', $task['description']);
        self::assertSame('new-project', $task['project']);
        self::assertSame('M', $task['priority']);
        self::assertContains('verify', $task['tags']);
    }

    public function test_update_can_replace_the_description(): void
    {
        $created = $this->svc()->create(['description' => 'old words']);

        $this->svc()->update([
            'id' => $created['id'],
            'description' => 'new words',
        ]);

        self::assertSame('new words', $this->svc()->get($created['uuid'])['description']);
    }

    public function test_update_status_completed_finishes_the_task(): void
    {
        $created = $this->svc()->create(['description' => 'finish me via update']);

        $result = $this->svc()->update([
            'id' => $created['id'],
            'status' => 'completed',
        ]);

        self::assertSame('completed', $result['task']['status']);
        self::assertSame('finish me via update', $result['task']['description']);
    }

    public function test_update_status_deleted_removes_the_task(): void
    {
        $created = $this->svc()->create(['description' => 'delete me via update']);

        $result = $this->svc()->update([
            'id' => $created['id'],
            'status' => 'deleted',
        ]);

        self::assertSame('deleted', $result['task']['status']);
    }

    public function test_complete_records_the_annotation(): void
    {
        $created = $this->svc()->create(['description' => 'complete me']);

        $result = $this->svc()->complete(['uuid' => $created['uuid']], 'cleanup after test');

        $annotations = $result['task']['annotations'] ?? [];
        self::assertSame('completed', $result['task']['status']);
        self::assertSame('cleanup after test', $annotations[0]['description'] ?? null);
    }

    public function test_delete_actually_deletes(): void
    {
        $created = $this->svc()->create(['description' => 'delete me']);

        $result = $this->svc()->delete(['uuid' => $created['uuid']]);

        self::assertSame('deleted', $result['task']['status']);
        self::assertSame([], $this->svc()->list()['tasks']);
    }

    public function test_project_list_returns_projects_and_empty_is_not_an_error(): void
    {
        // The old code used the `projects` report, which exits 1 with
        // "No projects." on an empty working set.
        self::assertSame([], $this->svc()->projectList());

        $this->svc()->create(['description' => 'in a project', 'project' => 'tools-test']);

        self::assertSame(['tools-test'], $this->svc()->projectList());
    }

    public function test_next_action_returns_the_most_urgent_ready_task(): void
    {
        $this->svc()->create(['description' => 'low key', 'priority' => 'L']);
        $this->svc()->create(['description' => 'high stakes', 'priority' => 'H']);

        $next = $this->svc()->nextAction();

        self::assertNotNull($next);
        self::assertSame('high stakes', $next['description']);
    }

    public function test_next_action_returns_null_when_nothing_is_ready(): void
    {
        self::assertNull($this->svc()->nextAction());
    }

    public function test_next_action_ignores_completed_tasks(): void
    {
        $this->svc()->create(['description' => 'plain pending', 'priority' => 'L']);
        $urgent = $this->svc()->create(['description' => 'urgent but done', 'priority' => 'H']);
        $this->svc()->complete(['uuid' => $urgent['uuid']]);

        $next = $this->svc()->nextAction();

        // The completed task must never be returned, and with a plain
        // pending task in the working set there is always something
        // ready, so null is a failure here too.
        self::assertNotNull($next);
        self::assertNotSame($urgent['uuid'], $next['uuid']);
        self::assertSame('plain pending', $next['description']);
    }

    public function test_filters_by_project_and_tag(): void
    {
        $this->svc()->create(['description' => 'alpha', 'project' => 'proj-a', 'tags' => ['work']]);
        $this->svc()->create(['description' => 'beta', 'project' => 'proj-b']);

        $byProject = $this->svc()->list(['project' => 'proj-a']);
        self::assertSame(['alpha'], array_column($byProject['tasks'], 'description'));

        $byTag = $this->svc()->list(['tag' => ['work']]);
        self::assertSame(['alpha'], array_column($byTag['tasks'], 'description'));
    }

    public function test_has_more_is_true_when_more_tasks_exist(): void
    {
        $this->svc()->create(['description' => 'one']);
        $this->svc()->create(['description' => 'two']);

        $result = $this->svc()->list(['limit' => 1]);

        self::assertCount(1, $result['tasks']);
        self::assertTrue($result['has_more']);
    }

    public function test_descriptions_with_special_characters_survive(): void
    {
        $tricky = 'quotes \'here\' and "there" plus -- and $HOME and `backticks`';

        $result = $this->svc()->create([
            'description' => $tricky,
            'project' => 'weird;project name',
        ]);

        $task = $result['task'];
        self::assertSame($tricky, $task['description']);
        self::assertSame('weird;project name', $task['project']);
    }

    public function test_unknown_id_throws_a_clear_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No task with id 99');

        $this->svc()->update(['id' => 99, 'priority' => 'M']);
    }

    public function test_zero_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->svc()->update(['id' => 0, 'priority' => 'M']);
    }

    /**
     * Bug A (#41): `due:nov 15` passed as one argv element makes task drop
     * the day and resolve to the 1st of the month. Dates must be normalized
     * to ISO in PHP before reaching the CLI.
     */
    public function test_month_name_dates_resolve_to_the_exact_day(): void
    {
        $result = $this->svc()->create([
            'description' => 'due nov 15',
            'due' => 'nov 15',
            'end' => 'nov 20',
        ]);

        $task = $result['task'];
        // Expected value is next year's Nov 15 — see dateSentinel().
        $expected = $this->dateSentinel('nov 15');
        self::assertSame($expected, $this->isoDate($task['due']));
        self::assertSame($this->dateSentinel('nov 20'), $this->isoDate($task['until']));
    }

    /**
     * Relative forms pass through strtotime and land on the right day too.
     */
    public function test_relative_dates_resolve_correctly(): void
    {
        $result = $this->svc()->create([
            'description' => 'due tomorrow',
            'due' => 'tomorrow',
        ]);

        self::assertSame(
            (new DateTimeImmutable('+1 day'))->format('Y-m-d'),
            $this->isoDate($result['task']['due']),
        );
    }

    public function test_invalid_date_is_rejected_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date');

        $this->svc()->create(['description' => 'never', 'due' => 'flurgle']);
    }

    /**
     * Bug B (#41): the schema's `end` mapped to taskwarrior's `end`, which
     * is the completion timestamp and cannot be set on a pending task.
     */
    public function test_end_maps_to_until_not_end(): void
    {
        $result = $this->svc()->create([
            'description' => 'has a deadline',
            'end' => '2026-12-31',
        ]);

        $task = $result['task'];
        self::assertSame('2026-12-31', $this->isoDate($task['until'] ?? null));
        self::assertArrayNotHasKey('end', $task);
    }

    /**
     * Bug D (#41): uuid addressing must work everywhere id does, and uuid
     * must win over stale ids (ids renumber after every completion).
     */
    public function test_uuid_addressing_works_for_update_complete_delete(): void
    {
        $first = $this->svc()->create(['description' => 'first', 'priority' => 'L']);
        $second = $this->svc()->create(['description' => 'second', 'priority' => 'L']);

        // Complete the first so ids renumber: second was id 2, is now 1.
        $this->svc()->complete(['uuid' => $first['uuid']]);

        // A stale id=2 would now address nothing; uuid still hits.
        $updated = $this->svc()->update(['uuid' => $second['uuid'], 'priority' => 'H']);
        self::assertTrue($updated['updated']);
        self::assertSame('H', $updated['task']['priority']);
        self::assertSame($second['uuid'], $updated['uuid']);

        $completed = $this->svc()->complete(['uuid' => $second['uuid']], 'by uuid');
        self::assertSame('completed', $completed['task']['status']);
        self::assertSame($second['uuid'], $completed['uuid']);

        $deleted = $this->svc()->delete(['uuid' => $second['uuid']]);
        self::assertTrue($deleted['deleted']);
        self::assertSame($second['uuid'], $deleted['uuid']);
    }

    public function test_both_id_and_uuid_is_rejected(): void
    {
        $created = $this->svc()->create(['description' => 'ambiguous']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not both');

        $this->svc()->update(['id' => $created['id'], 'uuid' => $created['uuid'], 'priority' => 'M']);
    }

    /**
     * Bug E (#41): an update that changes nothing reported success while
     * writing nothing — a caller bug that should be loud.
     */
    public function test_update_with_no_change_fields_is_rejected(): void
    {
        $created = $this->svc()->create(['description' => 'untouched']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one field');

        $this->svc()->update(['uuid' => $created['uuid']]);
    }

    public function test_priority_u_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('L, M, H');

        $this->svc()->create(['description' => 'urgent?', 'priority' => 'U']);
    }

    private function isoDate(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        // TW 2.6 exports compact (20261105T000000Z), TW 3 exports
        // ISO (2026-11-05T00:00:00Z); accept both.
        if (1 === preg_match('/^(\d{4})(\d{2})(\d{2})T/', $value, $m)) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }

        return substr($value, 0, 10);
    }

    /**
     * strtotime resolves "nov 15" to the next upcoming Nov 15 relative to
     * today, so the expected due date depends on today's date. Compute it
     * the same way the service does, but independently.
     */
    private function dateSentinel(string $natural): string
    {
        return (new DateTimeImmutable('@'.strtotime($natural)))->format('Y-m-d');
    }
}
