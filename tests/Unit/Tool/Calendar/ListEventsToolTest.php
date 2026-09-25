<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Calendar;

use App\Calendar\CalDav\CalDavClient;
use App\Calendar\CalendarReader;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Mapping\EventMapper;
use App\Tool\Calendar\ListEventsTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The calendar tool, driven through a scripted HTTP client so no network is
 * needed and both the request and the parsed result can be asserted.
 *
 * Follows the get_transactions precedent: the same shape of test, with the
 * findings that matter here added on top.
 *
 * @internal
 *
 * @covers \App\Tool\Calendar\ListEventsTool
 * @covers \App\Calendar\CalDav\CalDavClient
 */
final class ListEventsToolTest extends TestCase
{
    private const BASE = 'https://dav.example.com';

    /** A minimal but valid multistatus naming one calendar. */
    private const DISCOVERY_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response>
            <href>/cal/</href>
            <propstat>
              <prop>
                <resourcetype><C:calendar/><collection/></resourcetype>
                <displayname>Work</displayname>
                <current-user-privilege-set>
                  <privilege><read/></privilege>
                  <privilege><write-content/></privilege>
                </current-user-privilege-set>
              </prop>
              <status>HTTP/1.1 200 OK</status>
            </propstat>
          </response>
        </multistatus>
        XML;

    private const PRINCIPAL_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:">
          <response>
            <href>/</href>
            <propstat>
              <prop><current-user-principal><href>/principal/</href></current-user-principal></prop>
              <status>HTTP/1.1 200 OK</status>
            </propstat>
          </response>
        </multistatus>
        XML;

    private const HOME_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response>
            <href>/principal/</href>
            <propstat>
              <prop><C:calendar-home-set><href>/cal/</href></C:calendar-home-set></prop>
              <status>HTTP/1.1 200 OK</status>
            </propstat>
          </response>
        </multistatus>
        XML;

    private function objectsXml(string $icsBody, string $href = '/cal/evt.ics', string $etag = 'abc'): string
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n".$icsBody."END:VCALENDAR\r\n";

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
            .'<response><href>'.$href.'</href><propstat><prop>'
            .'<getetag>"'.$etag.'"</getetag>'
            .'<C:calendar-data>'.htmlspecialchars($ics, \ENT_XML1).'</C:calendar-data>'
            .'</prop><status>HTTP/1.1 200 OK</status></propstat></response>'
            .'</multistatus>';
    }

    /**
     * A client that answers the three discovery requests in order and then
     * whatever the caller supplies for the data request.
     *
     * @param array<array{method: string, url: string, options: array<string, mixed>}> $calls
     */
    private function tool(callable $dataResponse, array &$calls = [], string $zone = 'America/New_York', bool $discovery = true): ListEventsTool
    {
        $queue = [];

        if ($discovery) {
            $queue[] = new MockResponse(self::PRINCIPAL_XML);
            $queue[] = new MockResponse(self::HOME_XML);
            $queue[] = new MockResponse(self::DISCOVERY_XML);
        }

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$queue, &$calls, $dataResponse): MockResponse {
            $calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if ([] !== $queue) {
                return array_shift($queue);
            }

            return $dataResponse($method, $url, $options);
        }, self::BASE);

        $reader = new CalendarReader(
            new CalDavClient($client, self::BASE, 'user', 'secret'),
            new EventMapper(new TimeZoneRule($zone)),
            new TimeZoneRule($zone),
        );

        return new ListEventsTool($reader, new TimeZoneRule($zone));
    }

    public function test_lists_events_for_a_valid_range(): void
    {
        $calls = [];
        $tool = $this->tool(
            fn (): MockResponse => new MockResponse($this->objectsXml(
                "BEGIN:VEVENT\r\nUID:evt-utc\r\nDTSTART:20261003T120000Z\r\nDTEND:20261003T123000Z\r\nSUMMARY:UTC standup\r\nEND:VEVENT\r\n",
            )),
            $calls,
        );

        $result = $tool->listEvents('2026-10-01', '2026-10-31');

        self::assertSame(1, $result['count']);
        self::assertFalse($result['has_more']);
        self::assertSame('America/New_York', $result['timezone']);
        self::assertArrayNotHasKey('next_cursor', $result);
        self::assertArrayNotHasKey('errors', $result);

        $event = $result['events'][0];
        self::assertSame('evt-utc', $event['uid']);
        self::assertSame('2026-10-03T08:00:00-04:00', $event['start']);
        self::assertSame('evt-utc::2026-10-03T12:00:00Z', $event['id']);
    }

    public function test_sends_basic_auth_and_a_calendar_query_report(): void
    {
        $calls = [];
        $tool = $this->tool(fn (): MockResponse => new MockResponse($this->objectsXml("BEGIN:VEVENT\r\nUID:e\r\nDTSTART:20261003T120000Z\r\nDTEND:20261003T130000Z\r\nSUMMARY:S\r\nEND:VEVENT\r\n")), $calls);

        $tool->listEvents('2026-10-01', '2026-10-31');

        $reports = array_values(array_filter($calls, static fn (array $call): bool => 'REPORT' === $call['method']));

        self::assertNotEmpty($reports, 'a calendar-query REPORT must be issued');

        $report = $reports[0];
        self::assertSame('https://dav.example.com/cal/', $report['url']);
        self::assertStringContainsString('calendar-query', $report['options']['body']);
        self::assertStringContainsString('time-range', $report['options']['body']);
        // Server-side expansion produced wrong instants across a DST
        // boundary (finding 2), so it must never be requested.
        self::assertStringNotContainsString('expand', $report['options']['body']);
        // Symfony turns auth_basic into an Authorization header before the
        // transport sees it, so assert on that rather than the option.
        $authorization = $report['options']['normalized_headers']['authorization'][0]
            ?? $report['options']['normalized_headers']['Authorization'][0]
            ?? '';
        self::assertSame('Authorization: Basic '.base64_encode('user:secret'), $authorization);
    }

    public function test_an_unresolvable_tzid_is_counted_in_errors_and_the_rest_still_returned(): void
    {
        $tool = $this->tool(static fn (): MockResponse => new MockResponse(
            '<?xml version="1.0" encoding="utf-8"?>'
            .'<multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
            .'<response><href>/cal/bad.ics</href><propstat><prop><getetag>"1"</getetag><C:calendar-data>'
            .htmlspecialchars("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:bad\r\nDTSTART;TZID=Custom/Zone:20261015T090000\r\nSUMMARY:Bad zone\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", \ENT_XML1)
            .'</C:calendar-data></prop><status>HTTP/1.1 200 OK</status></propstat></response>'
            .'<response><href>/cal/good.ics</href><propstat><prop><getetag>"2"</getetag><C:calendar-data>'
            .htmlspecialchars("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:good\r\nDTSTART:20261015T130000Z\r\nDTEND:20261015T140000Z\r\nSUMMARY:Fine\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", \ENT_XML1)
            .'</C:calendar-data></prop><status>HTTP/1.1 200 OK</status></propstat></response>'
            .'</multistatus>',
        ));

        $result = $tool->listEvents('2026-10-01', '2026-10-31');

        // A listing that hit bad data still succeeds.
        self::assertSame(1, $result['count']);
        self::assertSame('There were 1 malformed events', $result['errors']);
        self::assertSame('good', $result['events'][0]['uid']);
    }

    public function test_a_readonly_calendar_is_reported_on_every_row(): void
    {
        $readonlyDiscovery = str_replace(
            '<privilege><write-content/></privilege>',
            '<privilege><read/></privilege>',
            self::DISCOVERY_XML,
        );

        $queue = [
            new MockResponse(self::PRINCIPAL_XML),
            new MockResponse(self::HOME_XML),
            new MockResponse($readonlyDiscovery),
        ];

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$queue): MockResponse {
            if ([] !== $queue) {
                return array_shift($queue);
            }

            $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:e\r\nDTSTART:20261006T100000Z\r\nDTEND:20261006T103000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Series\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

            return new MockResponse(
                '<?xml version="1.0" encoding="utf-8"?>'
                .'<multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
                .'<response><href>/cal/e.ics</href><propstat><prop><getetag>"1"</getetag><C:calendar-data>'
                .htmlspecialchars($ics, \ENT_XML1)
                .'</C:calendar-data></prop><status>HTTP/1.1 200 OK</status></propstat></response>'
                .'</multistatus>',
            );
        }, self::BASE);

        $zone = new TimeZoneRule('UTC');
        $reader = new CalendarReader(new CalDavClient($client, self::BASE, 'u', 'p'), new EventMapper($zone), $zone);
        $tool = new ListEventsTool($reader, $zone);

        $result = $tool->listEvents('2026-10-01', '2026-10-31');

        self::assertCount(3, $result['events']);

        foreach ($result['events'] as $event) {
            self::assertTrue($event['readonly'], 'every expanded occurrence must be self-describing');
        }
    }

    public function test_a_401_names_the_env_vars_and_never_leaks_the_password(): void
    {
        $calls = [];
        $tool = $this->tool(
            static fn (): MockResponse => new MockResponse('denied secret', ['http_code' => 401]),
            $calls,
        );

        try {
            $tool->listEvents('2026-10-01', '2026-10-31');
            self::fail('a 401 must fail the call');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('CALDAV_USERNAME', $e->getMessage());
            self::assertStringContainsString('CALDAV_PASSWORD', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function test_a_transport_error_is_retried_once_then_reported(): void
    {
        $calls = [];
        $attempts = 0;

        $tool = $this->tool(
            static function () use (&$attempts): MockResponse {
                ++$attempts;

                return new MockResponse('', ['error' => 'connection reset']);
            },
            $calls,
        );

        try {
            $tool->listEvents('2026-10-01', '2026-10-31');
            self::fail('an unreachable server must fail the call');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Could not reach', $e->getMessage());
        }

        self::assertSame(2, $attempts, 'an idempotent request gets exactly one retry');
    }

    public function test_a_5xx_is_retried_once_and_can_then_succeed(): void
    {
        $calls = [];
        $attempts = 0;

        $tool = $this->tool(
            function () use (&$attempts): MockResponse {
                ++$attempts;

                if (1 === $attempts) {
                    return new MockResponse('server restarting', ['http_code' => 503]);
                }

                return new MockResponse($this->objectsXml("BEGIN:VEVENT\r\nUID:e\r\nDTSTART:20261003T120000Z\r\nDTEND:20261003T130000Z\r\nSUMMARY:Recovered\r\nEND:VEVENT\r\n"));
            },
            $calls,
        );

        $result = $tool->listEvents('2026-10-01', '2026-10-31');

        self::assertSame(1, $result['count']);
        self::assertSame(2, $attempts);
    }

    public function test_a_404_is_not_retried_and_is_reported_clearly(): void
    {
        $calls = [];
        $attempts = 0;

        $tool = $this->tool(
            static function () use (&$attempts): MockResponse {
                ++$attempts;

                return new MockResponse('nope', ['http_code' => 404]);
            },
            $calls,
        );

        try {
            $tool->listEvents('2026-10-01', '2026-10-31');
            self::fail('a 404 must fail the call');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('404', $e->getMessage());
        }

        self::assertSame(1, $attempts, 'only transport errors and 5xx are worth retrying');
    }

    public function test_unconfigured_reports_the_env_var_rather_than_a_type_error(): void
    {
        $zone = new TimeZoneRule('UTC');
        $reader = new CalendarReader(
            new CalDavClient(new MockHttpClient(), '', 'u', 'p'),
            new EventMapper($zone),
            $zone,
        );

        $tool = new ListEventsTool($reader, $zone);

        try {
            $tool->listEvents('2026-10-01', '2026-10-31');
            self::fail('an unconfigured tool must fail with a clear message');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('CALDAV_URL', $e->getMessage());
        }
    }

    // ── input validation ──────────────────────────────────────────────────

    public function test_rejects_a_malformed_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM-DD');

        $this->tool(static fn (): MockResponse => new MockResponse(''), discovery: false)
            ->listEvents('01/10/2026', '2026-10-31');
    }

    public function test_rejects_a_date_that_does_not_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid calendar dates');

        $this->tool(static fn (): MockResponse => new MockResponse(''), discovery: false)
            ->listEvents('2026-02-30', '2026-03-01');
    }

    public function test_rejects_a_reversed_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'to' date");

        $this->tool(static fn (): MockResponse => new MockResponse(''), discovery: false)
            ->listEvents('2026-10-31', '2026-10-01');
    }

    public function test_rejects_a_limit_above_the_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be between');

        $this->tool(static fn (): MockResponse => new MockResponse(''), discovery: false)
            ->listEvents('2026-10-01', '2026-10-31', limit: 201);
    }

    public function test_a_malformed_cursor_is_a_named_error_not_a_silent_restart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cursor');

        $this->tool(static fn (): MockResponse => new MockResponse(''), discovery: false)
            ->listEvents('2026-10-01', '2026-10-31', cursor: 'garbage!!');
    }
}
