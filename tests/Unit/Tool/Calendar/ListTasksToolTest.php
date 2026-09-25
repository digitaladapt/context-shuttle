<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Calendar;

use App\Calendar\CalDav\CalDavClient;
use App\Calendar\CalendarReader;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Mapping\EventMapper;
use App\Tool\Calendar\GetTaskTool;
use App\Tool\Calendar\ListTasksTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The task tools, driven through a scripted HTTP client.
 *
 * A note on what is *not* tested here: that the fetch sends no
 * `<time-range>`. That was verified against a live server instead, because
 * the point of omitting it is what a *server* does with the query, and a
 * mock can only confirm we sent what we sent.
 *
 * @internal
 *
 * @covers \App\Tool\Calendar\ListTasksTool
 * @covers \App\Tool\Calendar\GetTaskTool
 */
final class ListTasksToolTest extends TestCase
{
    private const BASE = 'https://dav.example.com';

    private const OBJECTS_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response><href>/</href><propstat><prop><current-user-principal><href>/p/</href></current-user-principal></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    private const HOME_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response><href>/p/</href><propstat><prop><C:calendar-home-set><href>/cal/</href></C:calendar-home-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    private const CALENDAR_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response><href>/cal/</href><propstat><prop><resourcetype><C:calendar/></resourcetype><displayname>Work</displayname><current-user-privilege-set><privilege><write-content/></privilege></current-user-privilege-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    /**
     * Five tasks: dated, date-only, completed, and undated.
     */
    private function tasksXml(): string
    {
        $bodies = [
            "BEGIN:VTODO\r\nUID:a-mid\r\nDTSTAMP:20260101T000000Z\r\nDUE:20261005T170000Z\r\nSTATUS:IN-PROCESS\r\nSUMMARY:Mid\r\nEND:VTODO\r\n",
            "BEGIN:VTODO\r\nUID:b-dated\r\nDTSTAMP:20260101T000000Z\r\nDUE;VALUE=DATE:20261020\r\nPRIORITY:1\r\nPERCENT-COMPLETE:50\r\nSTATUS:IN-PROCESS\r\nSUMMARY:All-day due\r\nCATEGORIES:work\r\nEND:VTODO\r\n",
            "BEGIN:VTODO\r\nUID:c-done\r\nDTSTAMP:20260101T000000Z\r\nDUE:20261001T090000Z\r\nSTATUS:COMPLETED\r\nPERCENT-COMPLETE:100\r\nSUMMARY:Done\r\nEND:VTODO\r\n",
            "BEGIN:VTODO\r\nUID:d-undated\r\nDTSTAMP:20260101T000000Z\r\nSTATUS:NEEDS-ACTION\r\nSUMMARY:No deadline\r\nEND:VTODO\r\n",
            "BEGIN:VTODO\r\nUID:e-bypercent\r\nDTSTAMP:20260101T000000Z\r\nDUE:20261010T090000Z\r\nPERCENT-COMPLETE:100\r\nSUMMARY:Done by percent\r\nEND:VTODO\r\n",
        ];

        $responses = '';

        foreach ($bodies as $i => $body) {
            $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n".$body."END:VCALENDAR\r\n";
            $responses .= '<response><href>/cal/t'.$i.'.ics</href><propstat><prop><getetag>"'.$i.'"</getetag><C:calendar-data>'
                .htmlspecialchars($ics, \ENT_XML1)
                .'</C:calendar-data></prop><status>HTTP/1.1 200 OK</status></propstat></response>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'.$responses.'</multistatus>';
    }

    /**
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $calls
     *
     * @return array{0: ListTasksTool, 1: GetTaskTool}
     */
    private function tools(array &$calls = [], string $zone = 'America/New_York'): array
    {
        // Discovery runs on *every* call, so the three bootstrap responses
        // are answered per invocation rather than consumed once. A queue
        // that only served them the first time made every later page answer
        // its discovery with the task XML, which collapsed the listing to a
        // single short page — a test artefact that looked like a paging bug.
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            $body = $options['body'] ?? '';

            if (str_contains($body, 'current-user-principal')) {
                return new MockResponse(self::OBJECTS_XML);
            }

            if (str_contains($body, 'calendar-home-set')) {
                return new MockResponse(self::HOME_XML);
            }

            if (str_contains($body, 'resourcetype')) {
                return new MockResponse(self::CALENDAR_XML);
            }

            return new MockResponse($this->tasksXml());
        }, self::BASE);

        $rule = new TimeZoneRule($zone);
        $reader = new CalendarReader(new CalDavClient($client, self::BASE, 'u', 'p'), new EventMapper($rule), $rule);

        return [new ListTasksTool($reader, $rule), new GetTaskTool($reader, $rule)];
    }

    public function test_open_tasks_only_by_default(): void
    {
        [$list] = $this->tools();

        $result = $list->listTasks();

        // c-done is completed by status, e-bypercent by percentage; both are
        // excluded, and the three open ones are not.
        self::assertSame(3, $result['count']);
        self::assertSame(['a-mid', 'b-dated', 'd-undated'], array_column($result['tasks'], 'id'));
    }

    public function test_include_completed_returns_everything(): void
    {
        [$list] = $this->tools();

        $result = $list->listTasks(include_completed: true);

        self::assertSame(5, $result['count']);
    }

    public function test_undated_tasks_sort_after_dated_ones(): void
    {
        [$list] = $this->tools();

        $ids = array_column($list->listTasks()['tasks'], 'id');

        self::assertSame('d-undated', end($ids), 'a task with no deadline has no date to sort by, so it goes last');
    }

    public function test_a_date_only_due_date_is_flagged_and_not_shifted(): void
    {
        [$list] = $this->tools();

        $result = $list->listTasks();
        $dated = null;

        foreach ($result['tasks'] as $task) {
            if ('b-dated' === $task['id']) {
                $dated = $task;
            }
        }

        self::assertNotNull($dated);
        self::assertSame('2026-10-20', $dated['due']);
        self::assertTrue($dated['due_is_date']);
    }

    public function test_a_timed_due_date_is_rendered_in_the_deployment_timezone(): void
    {
        [$list] = $this->tools();

        $result = $list->listTasks();
        $timed = null;

        foreach ($result['tasks'] as $task) {
            if ('a-mid' === $task['id']) {
                $timed = $task;
            }
        }

        self::assertNotNull($timed);
        self::assertSame('2026-10-05T13:00:00-04:00', $timed['due']);
        self::assertFalse($timed['due_is_date']);
    }

    public function test_the_envelope_reports_the_timezone_and_completion(): void
    {
        [$list] = $this->tools();

        $result = $list->listTasks();

        self::assertSame('America/New_York', $result['timezone']);
        self::assertFalse($result['has_more']);
        self::assertArrayNotHasKey('next_cursor', $result);
        self::assertArrayNotHasKey('errors', $result);
    }

    public function test_search_matches_the_summary(): void
    {
        [$list] = $this->tools();

        $result = $list->listTasks(search: 'DEADLINE');

        self::assertSame(1, $result['count']);
        self::assertSame('d-undated', $result['tasks'][0]['id']);
    }

    public function test_a_due_range_excludes_undated_tasks(): void
    {
        [$list] = $this->tools();

        // A task with no due date has nothing to fall inside a range.
        $result = $list->listTasks(from: '2026-10-01', to: '2026-10-31');

        $ids = array_column($result['tasks'], 'id');
        self::assertNotContains('d-undated', $ids);
    }

    public function test_a_due_range_is_inclusive_of_the_final_day(): void
    {
        [$list] = $this->tools();

        // `b-dated` is due 2026-10-20 as a bare date; asking for that single
        // day must include it rather than cutting it off at midnight.
        $result = $list->listTasks(from: '2026-10-20', to: '2026-10-20');

        self::assertSame(['b-dated'], array_column($result['tasks'], 'id'));
    }

    public function test_a_reversed_range_is_rejected(): void
    {
        [$list] = $this->tools();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'to' date");

        $list->listTasks(from: '2026-10-31', to: '2026-10-01');
    }

    public function test_a_malformed_range_date_is_rejected(): void
    {
        [$list] = $this->tools();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM-DD');

        $list->listTasks(from: '01/10/2026');
    }

    public function test_get_task_returns_one_row_by_uid(): void
    {
        [, $get] = $this->tools();

        $result = $get->getTask('b-dated');

        self::assertSame(1, $result['count']);
        self::assertSame('b-dated', $result['tasks'][0]['id']);
        self::assertSame(1, $result['tasks'][0]['priority']);
        self::assertSame(['work'], $result['tasks'][0]['categories']);
    }

    public function test_get_task_accepts_a_composite_id_and_uses_the_uid_half(): void
    {
        [, $get] = $this->tools();

        // A caller reusing an event-style id by mistake should still get the
        // right task rather than an error.
        $result = $get->getTask('b-dated::2026-10-20');

        self::assertSame(1, $result['count']);
        self::assertSame('b-dated', $result['tasks'][0]['id']);
    }

    public function test_get_task_is_an_error_naming_an_unknown_id(): void
    {
        [, $get] = $this->tools();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nope@test');

        $get->getTask('nope@test');
    }

    public function test_get_task_rejects_an_empty_id(): void
    {
        [, $get] = $this->tools();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('id must not be empty');

        $get->getTask('  ');
    }

    public function test_paging_walks_every_task_exactly_once(): void
    {
        $calls = [];
        [$list] = $this->tools($calls);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $result = $list->listTasks(include_completed: true, limit: 2, cursor: $cursor);
            ++$pages;

            foreach ($result['tasks'] as $task) {
                $seen[] = $task['id'];
            }

            $cursor = $result['next_cursor'] ?? null;
        } while (null !== $cursor && $pages < 10);

        self::assertSame(3, $pages);
        self::assertCount(5, $seen);
        self::assertSame($seen, array_values(array_unique($seen)), 'no task may be paged twice');
    }

    public function test_a_cursor_from_an_event_listing_is_rejected_rather_than_misread(): void
    {
        [$list] = $this->tools();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cursor');

        $list->listTasks(cursor: 'not-a-real-cursor!!');
    }

    public function test_a_readonly_calendar_is_reported_on_every_row(): void
    {
        $readonly = str_replace('<privilege><write-content/></privilege>', '<privilege><read/></privilege>', self::CALENDAR_XML);

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($readonly): MockResponse {
            $body = $options['body'] ?? '';

            if (str_contains($body, 'current-user-principal')) {
                return new MockResponse(self::OBJECTS_XML);
            }

            if (str_contains($body, 'calendar-home-set')) {
                return new MockResponse(self::HOME_XML);
            }

            if (str_contains($body, 'resourcetype')) {
                return new MockResponse($readonly);
            }

            $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:S\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

            return new MockResponse('<?xml version="1.0"?><multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><response><href>/cal/t.ics</href><propstat><prop><getetag>"1"</getetag><C:calendar-data>'.htmlspecialchars($ics, \ENT_XML1).'</C:calendar-data></prop><status>HTTP/1.1 200 OK</status></propstat></response></multistatus>');
        }, self::BASE);

        $rule = new TimeZoneRule('UTC');
        $reader = new CalendarReader(new CalDavClient($client, self::BASE, 'u', 'p'), new EventMapper($rule), $rule);
        $list = new ListTasksTool($reader, $rule);

        $result = $list->listTasks();

        self::assertCount(1, $result['tasks']);
        self::assertTrue($result['tasks'][0]['readonly']);
    }
}
