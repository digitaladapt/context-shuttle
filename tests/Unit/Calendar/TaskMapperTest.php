<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\CalendarObject;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Mapping\EventMapper;
use PHPUnit\Framework\TestCase;

/**
 * VTODO mapping.
 *
 * The task path deliberately does *not* go through recurrence expansion, and
 * its due dates follow the same never-convert-a-date rule as an event's
 * start. Both are asserted here because both are the kind of thing that
 * looks fine until a real calendar disagrees.
 *
 * @internal
 *
 * @covers \App\Calendar\Mapping\EventMapper
 */
final class TaskMapperTest extends TestCase
{
    private function calendar(bool $readonly = false): CalendarInfo
    {
        return new CalendarInfo('/cal/', 'cal', $readonly);
    }

    /**
     * @return array{0: list<\App\Calendar\Domain\CalendarTask>, 1: list<\App\Calendar\Domain\CalendarProblem>}
     */
    private function map(string $body, string $zone = 'America/New_York', bool $readonly = false): array
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n".$body."END:VCALENDAR\r\n";

        return (new EventMapper(new TimeZoneRule($zone)))->mapTask(
            new CalendarObject('/cal/t.ics', $ics, null, $this->calendar($readonly)),
        );
    }

    public function test_maps_a_timed_task_with_its_due_in_the_deployment_timezone(): void
    {
        [$tasks] = $this->map("BEGIN:VTODO\r\nUID:t1\r\nDTSTAMP:20260101T000000Z\r\nDUE:20261010T160000Z\r\nPERCENT-COMPLETE:20\r\nSTATUS:NEEDS-ACTION\r\nSUMMARY:Buy milk\r\nEND:VTODO\r\n");

        self::assertCount(1, $tasks);

        $task = $tasks[0];
        self::assertSame('t1', $task->id);
        self::assertSame('Buy milk', $task->summary);
        self::assertSame('2026-10-10T12:00:00-04:00', $task->due);
        self::assertFalse($task->dueIsDate);
        self::assertSame(20, $task->percentComplete);
        self::assertSame('NEEDS-ACTION', $task->status);
        self::assertFalse($task->isCompleted());
    }

    public function test_a_date_only_due_date_is_never_zone_converted(): void
    {
        // The same trap as an all-day event: converting a bare DATE through a
        // zone renders 2026-10-20 as the 19th in a negative offset.
        [$tasks] = $this->map("BEGIN:VTODO\r\nUID:t2\r\nDTSTAMP:20260101T000000Z\r\nDUE;VALUE=DATE:20261020\r\nSUMMARY:Date-only due\r\nEND:VTODO\r\n");

        self::assertSame('2026-10-20', $tasks[0]->due);
        self::assertTrue($tasks[0]->dueIsDate);
    }

    public function test_a_task_without_a_due_date_is_valid_and_sorts_last(): void
    {
        [$tasks] = $this->map("BEGIN:VTODO\r\nUID:t3\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:No deadline\r\nEND:VTODO\r\n");

        self::assertNull($tasks[0]->due);
        self::assertFalse($tasks[0]->hasDue());
        // A high sentinel, so it groups after every dated task rather than
        // sorting as an empty string among them.
        self::assertGreaterThan('9999-12-31', $tasks[0]->orderKey());
    }

    public function test_the_order_key_is_utc_so_ordering_survives_a_timezone_change(): void
    {
        $body = "BEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nDUE:20261010T160000Z\r\nSUMMARY:S\r\nEND:VTODO\r\n";

        [$ny] = $this->map($body, 'America/New_York');
        [$sydney] = $this->map($body, 'Australia/Sydney');

        // `due` renders differently in each zone, but the key that decides
        // the order — and what a cursor stores — does not move.
        self::assertNotSame($ny[0]->due, $sydney[0]->due);
        self::assertSame($ny[0]->orderKey(), $sydney[0]->orderKey());
    }

    public function test_completion_is_recognised_from_either_signal(): void
    {
        [$byStatus] = $this->map("BEGIN:VTODO\r\nUID:a\r\nDTSTAMP:20260101T000000Z\r\nSTATUS:COMPLETED\r\nSUMMARY:A\r\nEND:VTODO\r\n");
        [$byPercent] = $this->map("BEGIN:VTODO\r\nUID:b\r\nDTSTAMP:20260101T000000Z\r\nPERCENT-COMPLETE:100\r\nSUMMARY:B\r\nEND:VTODO\r\n");
        [$neither] = $this->map("BEGIN:VTODO\r\nUID:c\r\nDTSTAMP:20260101T000000Z\r\nPERCENT-COMPLETE:99\r\nSUMMARY:C\r\nEND:VTODO\r\n");

        // Clients disagree about which one they set, and a task showing as
        // open at 100% is a worse answer than treating it as done.
        self::assertTrue($byStatus[0]->isCompleted());
        self::assertTrue($byPercent[0]->isCompleted());
        self::assertFalse($neither[0]->isCompleted());
    }

    public function test_completed_at_is_normalized_like_any_other_instant(): void
    {
        [$tasks] = $this->map("BEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nSTATUS:COMPLETED\r\nCOMPLETED:20260930T120000Z\r\nSUMMARY:Done\r\nEND:VTODO\r\n");

        self::assertSame('2026-09-30T08:00:00-04:00', $tasks[0]->completedAt);
    }

    public function test_a_non_numeric_percent_is_absent_rather_than_zero(): void
    {
        // Reporting 0% would be a fabricated answer.
        [$tasks] = $this->map("BEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nPERCENT-COMPLETE:lots\r\nPRIORITY:high\r\nSUMMARY:S\r\nEND:VTODO\r\n");

        self::assertNull($tasks[0]->percentComplete);
        self::assertNull($tasks[0]->priority);
    }

    public function test_priority_zero_is_preserved_because_rfc_5545_defines_it(): void
    {
        [$tasks] = $this->map("BEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nPRIORITY:0\r\nSUMMARY:S\r\nEND:VTODO\r\n");

        // 0 means "undefined priority", which is distinct from absent.
        self::assertSame(0, $tasks[0]->priority);
    }

    public function test_readonly_comes_from_the_calendar(): void
    {
        [$tasks] = $this->map("BEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:S\r\nEND:VTODO\r\n", readonly: true);

        self::assertTrue($tasks[0]->readonly);
    }

    public function test_a_task_is_not_expanded_even_when_it_repeats(): void
    {
        // Expansion would turn one checklist item into a row per occurrence,
        // which is not what "what's outstanding?" is asking.
        [$tasks] = $this->map("BEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nDUE:20261010T160000Z\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\nSUMMARY:Repeating\r\nEND:VTODO\r\n");

        self::assertCount(1, $tasks);
        self::assertSame('t', $tasks[0]->uid);
    }

    public function test_an_unresolvable_tzid_on_a_due_date_is_reported(): void
    {
        [$tasks, $problems] = $this->map("BEGIN:VTODO\r\nUID:bad\r\nDTSTAMP:20260101T000000Z\r\nDUE;TZID=Custom/Zone:20261010T160000Z\r\nSUMMARY:Bad zone\r\nEND:VTODO\r\n");

        self::assertSame([], $tasks);
        self::assertCount(1, $problems);
        self::assertSame('bad', $problems[0]->uid);
    }

    public function test_several_todos_in_one_object_all_map(): void
    {
        [$tasks] = $this->map(
            "BEGIN:VTODO\r\nUID:a\r\nDTSTAMP:20260101T000000Z\r\nDUE:20261001T120000Z\r\nSUMMARY:First\r\nEND:VTODO\r\n".
            "BEGIN:VTODO\r\nUID:b\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:Second\r\nEND:VTODO\r\n",
        );

        self::assertCount(2, $tasks);
    }

    public function test_an_event_in_the_same_object_is_ignored_by_the_task_mapper(): void
    {
        // The client filters by component type, but a payload carrying both
        // must not leak an event into a task listing.
        [$tasks] = $this->map(
            "BEGIN:VEVENT\r\nUID:e\r\nDTSTART:20261001T120000Z\r\nDTEND:20261001T130000Z\r\nSUMMARY:An event\r\nEND:VEVENT\r\n".
            "BEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:A task\r\nEND:VTODO\r\n",
        );

        self::assertCount(1, $tasks);
        self::assertSame('t', $tasks[0]->uid);
    }

    public function test_the_output_shape_is_a_flat_self_describing_row(): void
    {
        [$tasks] = $this->map("BEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nDUE;VALUE=DATE:20261020\r\nCATEGORIES:work,urgent\r\nSUMMARY:S\r\nEND:VTODO\r\n");

        $row = $tasks[0]->toArray();

        self::assertSame(
            ['id', 'uid', 'summary', 'description', 'due', 'due_is_date', 'status', 'percent_complete', 'priority', 'completed_at', 'categories', 'readonly', 'calendar'],
            array_keys($row),
        );
        self::assertSame(['work', 'urgent'], $row['categories']);
        // readonly is flat, and the calendar holds only identity.
        self::assertFalse($row['readonly']);
        self::assertSame(['name', 'href'], array_keys($row['calendar']));
    }

    public function test_a_payload_that_will_not_parse_is_a_problem_not_an_exception(): void
    {
        [, $problems] = $this->map("this is not a calendar\r\n");

        self::assertCount(1, $problems);
        self::assertNotEmpty($problems[0]->reason);
    }

    public function test_a_fixed_offset_tzid_on_a_due_date_is_recovered(): void
    {
        [$tasks, $problems] = $this->map("BEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nDUE;TZID=UTC-04:00:20261010T090000\r\nSUMMARY:Fixed\r\nEND:VTODO\r\n");

        self::assertSame([], $problems);
        // 09:00 at -04:00 is 13:00Z, which renders back as 09:00 in New York.
        self::assertSame('2026-10-10T09:00:00-04:00', $tasks[0]->due);
    }
}
