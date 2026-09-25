<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\CalDav\CalDavClient;
use App\Calendar\CalendarReader;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Mapping\EventMapper;
use App\Tool\Calendar\GetEventTool;
use App\Tool\Calendar\ListEventsTool;
use App\Tool\Calendar\ListTasksTool;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\NativeHttpClient;

/**
 * The read path against a **real** CalDAV server.
 *
 * Skipped unless `CALDAV_LIVE_URL` is set, so CI stays hermetic and no
 * credentials are needed to run the suite. The unit tests script every
 * server interaction, which is what makes them fast and precise — but they
 * cannot catch a server whose XML, discovery bootstrap or query support
 * differs from the fixtures. This class is the counterweight, and it exists
 * because each of those has already bitten once.
 *
 * Run it against the Radicale fixture set with:
 *
 *     CALDAV_LIVE_URL=http://localhost:5232 \
 *     CALDAV_LIVE_USERNAME=lyra \
 *     CALDAV_LIVE_PASSWORD=lyra-secret \
 *     CALDAV_LIVE_TZ=America/New_York \
 *     php bin/phpunit --group live
 *
 * @internal
 *
 * @group live
 */
#[Group('live')]
final class CalDavLiveTest extends TestCase
{
    /**
     * Built on first use rather than in setUp(), so the properties are
     * definitely initialised and static analysis can see it. Every test
     * reaches them only after `requireConfiguration()`, which skips when
     * the environment is not configured.
     */
    private ?CalendarReader $reader = null;

    private function timeZone(): TimeZoneRule
    {
        $this->requireConfiguration();

        return new TimeZoneRule(getenv('CALDAV_LIVE_TZ') ?: 'UTC');
    }

    private function reader(): CalendarReader
    {
        $this->requireConfiguration();

        return $this->reader ??= new CalendarReader(
            new CalDavClient(
                new NativeHttpClient(),
                (string) getenv('CALDAV_LIVE_URL'),
                getenv('CALDAV_LIVE_USERNAME') ?: '',
                getenv('CALDAV_LIVE_PASSWORD') ?: '',
            ),
            new EventMapper($this->timeZone()),
            $this->timeZone(),
        );
    }

    private function requireConfiguration(): void
    {
        $url = getenv('CALDAV_LIVE_URL');

        if (false === $url || '' === $url) {
            self::markTestSkipped('Set CALDAV_LIVE_URL (and friends) to run the live CalDAV tests.');
        }
    }

    public function test_discovery_finds_calendars_through_the_principal_bootstrap(): void
    {
        // The bootstrap is the part a fixture cannot prove: a server whose
        // calendar home is not the configured URL only works if
        // `current-user-principal` -> `calendar-home-set` is honoured.
        $calendars = $this->reader()->selectCalendars(null);

        self::assertNotSame([], $calendars, 'discovery found no calendars: check CALDAV_LIVE_URL');

        foreach ($calendars as $calendar) {
            self::assertNotSame('', $calendar->href);
            self::assertNotSame('', $calendar->name, 'a discovered calendar must be nameable');
        }
    }

    public function test_a_listing_returns_events_with_normalized_timestamps(): void
    {
        $result = $this->listEvents();

        self::assertNotSame([], $result['events'], 'the fixture server should have events in this window');

        foreach ($result['events'] as $event) {
            // Every timestamp carries the deployment's offset, never a 'Z'.
            self::assertMatchesRegularExpression(
                $event['all_day'] ? '/^\d{4}-\d{2}-\d{2}$/' : '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
                $event['start'],
                'a start must be rendered in the deployment timezone',
            );

            self::assertStringNotContainsString('Z', $event['start']);
        }
    }

    public function test_the_id_names_an_instant_that_a_lookup_round_trips(): void
    {
        $result = $this->listEvents();
        $event = $result['events'][0];

        $fetched = (new GetEventTool($this->reader(), $this->timeZone()))->getEvent($event['id']);

        self::assertSame(1, $fetched['count'], 'an id from a listing must fetch exactly that occurrence');
        self::assertSame($event['id'], $fetched['events'][0]['id']);
        self::assertSame($event['start'], $fetched['events'][0]['start']);
        self::assertSame($event['uid'], $fetched['events'][0]['uid']);
    }

    public function test_an_all_day_event_is_a_date_on_the_server_too(): void
    {
        $result = $this->listEvents();
        $allDay = array_values(array_filter($result['events'], static fn (array $e): bool => true === $e['all_day']));

        if ([] === $allDay) {
            self::markTestSkipped('No all-day event in the fixture window.');
        }

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $allDay[0]['start']);
        self::assertSame(
            (new DateTimeImmutable($allDay[0]['start']))->format('Y-m-d'),
            $allDay[0]['start'],
            'an all-day start must not shift when parsed',
        );
    }

    public function test_a_rendered_offset_matches_the_deployment_timezone(): void
    {
        $result = $this->listEvents();
        $timed = null;

        foreach ($result['events'] as $event) {
            if (true !== $event['all_day']) {
                $timed = $event;
                break;
            }
        }

        if (null === $timed) {
            self::markTestSkipped('No timed event in the fixture window.');
        }

        $rendered = new DateTimeImmutable($timed['start']);

        // Compare the *offset*, not the zone name: parsing an ISO string
        // yields a fixed-offset zone, so the name is `-04:00` even though
        // the value was rendered from `America/New_York`. Re-projecting
        // the instant into the configured zone must be a no-op, which is
        // the property that actually matters.
        self::assertSame(
            $rendered->format('Y-m-d\TH:i:sP'),
            $rendered->setTimezone($this->timeZone()->zone())->format('Y-m-d\TH:i:sP'),
            'a start must already be in the configured timezone',
        );
    }

    public function test_tasks_are_fetched_whole_and_filtered_client_side(): void
    {
        // The fetch deliberately sends no `<time-range>`: Radicale returns
        // every task without one but filters when one is given, and passes
        // undated tasks through either way, so no server-side range can be
        // trusted to mean the same thing twice.
        $rule = $this->timeZone();
        $tool = new ListTasksTool($this->reader(), $rule);

        $all = $tool->listTasks(include_completed: true, limit: 200);
        $open = $tool->listTasks(limit: 200);

        self::assertSame([], $all['errors'] ?? [], 'the fixture tasks should all parse');
        self::assertGreaterThanOrEqual(\count($open['tasks']), \count($all['tasks']));
        self::assertSame($rule->name(), $all['timezone']);

        // Every task carries a due in the deployment's timezone, or a bare
        // date, or nothing at all — never a UTC `Z`, and never converted
        // between the two forms.
        foreach ($all['tasks'] as $task) {
            if (null === $task['due']) {
                continue;
            }

            if ($task['due_is_date']) {
                self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $task['due']);
                continue;
            }

            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $task['due']);
        }
    }

    public function test_a_completed_task_is_excluded_by_default_and_included_on_request(): void
    {
        $tool = new ListTasksTool($this->reader(), $this->timeZone());

        $all = $tool->listTasks(include_completed: true, limit: 200);
        $open = $tool->listTasks(limit: 200);

        $allIds = array_column($all['tasks'], 'id');
        $openIds = array_column($open['tasks'], 'id');

        $completed = array_values(array_diff($allIds, $openIds));

        if ([] === $completed) {
            self::markTestSkipped('No completed task in the fixture calendar.');
        }

        foreach ($completed as $id) {
            self::assertContains($id, $allIds);
            self::assertNotContains($id, $openIds, 'a completed task must not appear by default');
        }
    }

    public function test_a_task_round_trips_through_get_task(): void
    {
        $list = new ListTasksTool($this->reader(), $this->timeZone());
        $result = $list->listTasks(include_completed: true, limit: 200);

        if ([] === $result['tasks']) {
            self::markTestSkipped('No tasks in the fixture calendar.');
        }

        $first = $result['tasks'][0];
        $fetched = (new \App\Tool\Calendar\GetTaskTool($this->reader(), $this->timeZone()))->getTask($first['id']);

        self::assertSame(1, $fetched['count']);
        self::assertSame($first['id'], $fetched['tasks'][0]['id']);
        self::assertSame($first['due'], $fetched['tasks'][0]['due']);
    }

    /**
     * @return array{events: list<array<string, mixed>>}
     */
    private function listEvents(): array
    {
        $tool = new ListEventsTool($this->reader(), $this->timeZone());

        /** @var array{events: list<array<string, mixed>>} $result */
        $result = $tool->listEvents(
            from: '2026-10-01',
            to: '2026-11-30',
            limit: 200,
        );

        return $result;
    }
}
