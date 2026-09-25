<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Calendar;

use App\Calendar\CalDav\CalDavClient;
use App\Calendar\CalendarReader;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Mapping\EventMapper;
use App\Tool\Calendar\GetEventTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `calendar_get_event`, driven through a scripted HTTP client.
 *
 * The behaviour worth protecting: a composite id returns exactly one
 * occurrence (not the series, and not the neighbours the server-side window
 * also matched), and a plain UID expands the series without needing the
 * caller to know when it happens.
 *
 * @internal
 *
 * @covers \App\Tool\Calendar\GetEventTool
 */
final class GetEventToolTest extends TestCase
{
    private const BASE = 'https://dav.example.com';

    private const DISCOVERY_XML = <<<'XML'
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

    /** A daily series of five, with the third occurrence moved to 15:00Z. */
    private const OVERRIDE_ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
        ."BEGIN:VEVENT\r\nUID:evt-override\r\nDTSTART:20261007T080000Z\r\nDTEND:20261007T081500Z\r\nRRULE:FREQ=DAILY;COUNT=5\r\nSUMMARY:Standup (series)\r\nEND:VEVENT\r\n"
        ."BEGIN:VEVENT\r\nUID:evt-override\r\nRECURRENCE-ID:20261009T080000Z\r\nDTSTART:20261009T150000Z\r\nDTEND:20261009T151500Z\r\nSUMMARY:Standup (moved)\r\nEND:VEVENT\r\n"
        ."END:VCALENDAR\r\n";

    private function objectsXml(string $ics, string $href = '/cal/e.ics'): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
            .'<response><href>'.$href.'</href><propstat><prop><getetag>"x"</getetag><C:calendar-data>'
            .htmlspecialchars($ics, \ENT_XML1)
            .'</C:calendar-data></prop><status>HTTP/1.1 200 OK</status></propstat></response>'
            .'</multistatus>';
    }

    /**
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $calls
     */
    private function tool(string $ics, array &$calls = [], string $zone = 'America/New_York'): GetEventTool
    {
        $queue = [
            new MockResponse(self::DISCOVERY_XML),
            new MockResponse(self::HOME_XML),
            new MockResponse(self::CALENDAR_XML),
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue, &$calls, $ics): MockResponse {
            $calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if ([] !== $queue) {
                return array_shift($queue);
            }

            return new MockResponse($this->objectsXml($ics));
        }, self::BASE);

        $rule = new TimeZoneRule($zone);

        return new GetEventTool(
            new CalendarReader(
                new CalDavClient($client, self::BASE, 'user', 'secret'),
                new EventMapper($rule),
                $rule,
            ),
            $rule,
        );
    }

    public function test_a_composite_id_returns_exactly_one_occurrence(): void
    {
        $result = $this->tool(self::OVERRIDE_ICS)->getEvent('evt-override::2026-10-09T15:00:00Z');

        self::assertSame(1, $result['count']);

        $event = $result['events'][0];
        self::assertSame('evt-override::2026-10-09T15:00:00Z', $event['id']);
        self::assertSame('Standup (moved)', $event['summary']);
        // The original slot survives, so "moved to 15:00" is distinguishable
        // from "always 15:00".
        self::assertSame('2026-10-09T08:00:00Z', $event['recurrence_id']);
    }

    public function test_a_composite_id_does_not_leak_neighbouring_occurrences(): void
    {
        // The server-side window has to be wider than one occurrence for the
        // fetch to find the object, so the result is narrowed explicitly.
        // Without that, this returned two rows.
        $result = $this->tool(self::OVERRIDE_ICS)->getEvent('evt-override::2026-10-08T08:00:00Z');

        self::assertSame(1, $result['count']);
        self::assertSame('evt-override::2026-10-08T08:00:00Z', $result['events'][0]['id']);
    }

    public function test_a_plain_uid_expands_the_whole_series(): void
    {
        $result = $this->tool(self::OVERRIDE_ICS)->getEvent('evt-override');

        self::assertSame(5, $result['count']);

        $ids = array_column($result['events'], 'id');
        self::assertContains('evt-override::2026-10-09T15:00:00Z', $ids);
    }

    public function test_a_plain_uid_lookup_uses_a_property_filter_not_a_time_range(): void
    {
        $calls = [];
        $this->tool(self::OVERRIDE_ICS, $calls)->getEvent('evt-override');

        $reports = array_values(array_filter($calls, static fn (array $c): bool => 'REPORT' === $c['method']));
        self::assertNotEmpty($reports);

        $body = $reports[0]['options']['body'];

        // A UID says nothing about when, so no time-range can be applied.
        self::assertStringContainsString('prop-filter', $body);
        self::assertStringContainsString('evt-override', $body);
        self::assertStringNotContainsString('time-range', $body);
    }

    public function test_a_composite_id_lookup_uses_a_narrow_time_range(): void
    {
        $calls = [];
        $this->tool(self::OVERRIDE_ICS, $calls)->getEvent('evt-override::2026-10-09T15:00:00Z');

        $reports = array_values(array_filter($calls, static fn (array $c): bool => 'REPORT' === $c['method']));
        self::assertNotEmpty($reports);

        $body = $reports[0]['options']['body'];
        self::assertStringContainsString('time-range', $body);
        // An occurrence id bounds the query; a property filter would waste a
        // full calendar walk.
        self::assertStringNotContainsString('prop-filter', $body);
    }

    public function test_an_all_day_composite_id_returns_the_date_unchanged(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
            ."BEGIN:VEVENT\r\nUID:holiday\r\nDTSTART;VALUE=DATE:20261005\r\nDTEND;VALUE=DATE:20261006\r\nSUMMARY:Company holiday\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $result = $this->tool($ics)->getEvent('holiday::2026-10-05');

        self::assertSame(1, $result['count']);

        $event = $result['events'][0];
        self::assertTrue($event['all_day']);
        // Never zone-converted, or the day shifts.
        self::assertSame('2026-10-05', $event['start']);
        self::assertTrue($event['end_exclusive']);
    }

    public function test_a_historical_one_off_is_found_by_plain_uid(): void
    {
        // The window anchors on the series' own start. Anchoring on "now"
        // would return nothing here, and looking like a missing event is
        // worse than an error.
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
            ."BEGIN:VEVENT\r\nUID:old\r\nDTSTART:20261003T120000Z\r\nDTEND:20261003T123000Z\r\nSUMMARY:UTC standup\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $result = $this->tool($ics)->getEvent('old');

        self::assertSame(1, $result['count']);
        self::assertSame('old::2026-10-03T12:00:00Z', $result['events'][0]['id']);
    }

    public function test_an_unknown_id_is_an_error_naming_it(): void
    {
        $tool = $this->tool(self::OVERRIDE_ICS);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nope@test');

        $tool->getEvent('nope@test::2026-10-09T15:00:00Z');
    }

    public function test_an_empty_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('id must not be empty');

        $this->tool(self::OVERRIDE_ICS)->getEvent('   ');
    }

    public function test_the_result_reports_the_timezone(): void
    {
        $result = $this->tool(self::OVERRIDE_ICS, zone: 'UTC')->getEvent('evt-override::2026-10-08T08:00:00Z');

        self::assertSame('UTC', $result['timezone']);
    }
}
