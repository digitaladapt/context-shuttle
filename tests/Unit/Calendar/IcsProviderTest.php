<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\CalendarReader;
use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\ComponentType;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Mapping\EventMapper;
use App\Calendar\Provider\CalDavProvider;
use App\Calendar\Provider\IcsProvider;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The ICS feed as a calendar source.
 *
 * The acceptance criterion is not that it fetches — it is that its output is
 * **indistinguishable from a read-only CalDAV calendar**, so the last test
 * here renders the same events through both providers and compares the rows
 * field by field. A caller that could tell the two apart would branch on it,
 * which is exactly the coupling the design refuses to ship.
 *
 * @internal
 *
 * @covers \App\Calendar\Provider\IcsProvider
 */
final class IcsProviderTest extends TestCase
{
    /** Two events, one recurring, in one feed. */
    private const FEED = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
        ."BEGIN:VEVENT\r\nUID:evt-utc@test\r\nDTSTART:20261003T120000Z\r\nDTEND:20261003T123000Z\r\nSUMMARY:UTC standup\r\nEND:VEVENT\r\n"
        ."BEGIN:VEVENT\r\nUID:evt-recurring@test\r\nDTSTART:20261006T100000Z\r\nDTEND:20261006T103000Z\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Weekly sync\r\nEND:VEVENT\r\n"
        ."END:VCALENDAR\r\n";

    private function provider(string $url = 'https://example.com/feed.ics', string $name = 'ics', ?string $body = null): IcsProvider
    {
        $client = new MockHttpClient(static function () use ($body): MockResponse {
            return new MockResponse($body ?? self::FEED);
        });

        return new IcsProvider($client, $url, $name);
    }

    public function test_is_configured_reflects_the_url(): void
    {
        self::assertTrue($this->provider()->isConfigured());
        self::assertFalse($this->provider(url: '')->isConfigured());
        self::assertFalse($this->provider(url: '   ')->isConfigured());
    }

    public function test_an_unconfigured_source_is_skipped_when_another_exists(): void
    {
        // This decision has to be made at runtime. A container parameter
        // backed by an env var is still the literal `%env(...)%` placeholder
        // while the container is being compiled, so a compiler pass cannot
        // tell a configured source from an unconfigured one — an earlier
        // attempt at exactly that concluded everything was set and left an
        // empty feed raising on every call.
        $rule = new TimeZoneRule('UTC');

        $reader = new CalendarReader(
            [$this->provider(url: ''), $this->provider()],
            new EventMapper($rule),
            $rule,
        );

        // Only the configured source's calendar appears.
        $calendars = $reader->selectCalendars(null);
        self::assertCount(1, $calendars);
        self::assertSame('https://example.com/feed.ics', $calendars[0]->href);

        $result = $reader->listEvents(
            new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')),
        );

        self::assertNotSame([], $result['events']);
        self::assertSame([], $result['problems'], 'an unconfigured source is absent, not an error');
    }

    public function test_no_configured_source_at_all_is_a_loud_error(): void
    {
        // Skipping *one* unset source is right; skipping *all* of them is
        // not. An empty listing would look like a calendar with nothing on
        // it rather than like a deployment nobody configured, so the failure
        // names both variables instead.
        $rule = new TimeZoneRule('UTC');
        $reader = new CalendarReader([$this->provider(url: '')], new EventMapper($rule), $rule);

        try {
            $reader->listEvents(
                new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')),
            );
            self::fail('a deployment with no calendar source must fail loudly');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('CALDAV_URL', $e->getMessage());
            self::assertStringContainsString('ICS_URL', $e->getMessage());
        }
    }

    public function test_one_synthetic_readonly_calendar(): void
    {
        $calendars = $this->provider()->listCalendars();

        self::assertCount(1, $calendars);
        self::assertTrue($calendars[0]->readonly, 'a feed is read-only by nature');
        self::assertSame('https://example.com/feed.ics', $calendars[0]->href);
        self::assertSame('ics', $calendars[0]->name);
    }

    public function test_the_display_name_comes_from_configuration(): void
    {
        self::assertSame('Holidays', $this->provider(name: 'Holidays')->listCalendars()[0]->name);
    }

    public function test_an_empty_name_falls_back_rather_than_producing_an_empty_label(): void
    {
        self::assertSame('ics', $this->provider(name: '  ')->listCalendars()[0]->name);
    }

    public function test_the_feed_is_returned_as_one_object_so_vtimezones_travel_with_their_events(): void
    {
        // Splitting per component would strand an event whose TZID is
        // declared once at the top of the feed.
        $objects = $this->provider()->fetch(
            new CalendarInfo('https://example.com/feed.ics', 'ics', true),
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2027-01-01'),
            ComponentType::Event,
        );

        self::assertCount(1, $objects);
        self::assertStringContainsString('BEGIN:VCALENDAR', $objects[0]->data);
    }

    public function test_every_row_it_produces_is_readonly(): void
    {
        $rule = new TimeZoneRule('UTC');
        $reader = new CalendarReader([$this->provider()], new EventMapper($rule), $rule);

        $result = $reader->listEvents(
            new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')),
        );

        self::assertCount(4, $result['events'], 'the one-off plus three occurrences');

        foreach ($result['events'] as $event) {
            self::assertTrue($event->readonly);
        }
    }

    public function test_a_non_http_url_is_refused_with_the_reason(): void
    {
        // `file://` in particular: symfony/http-client refuses the scheme, and
        // supporting it would mean a second fetch path for a rare case.
        $provider = $this->provider(url: 'file:///etc/passwd');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be an http(s) URL');

        $provider->fetch(new CalendarInfo('x', 'x', true), new DateTimeImmutable(), new DateTimeImmutable(), ComponentType::Event);
    }

    public function test_an_empty_url_reports_the_env_var(): void
    {
        $provider = $this->provider(url: '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ICS_URL');

        $provider->fetch(new CalendarInfo('x', 'x', true), new DateTimeImmutable(), new DateTimeImmutable(), ComponentType::Event);
    }

    public function test_a_response_that_is_not_a_calendar_is_an_error_not_an_empty_feed(): void
    {
        // A login page or error document would otherwise look like a feed with
        // no events, which reads as "nothing on today" rather than as the
        // misconfiguration it is.
        $provider = $this->provider(body: '<html><body>Please sign in</body></html>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return iCalendar data');

        $provider->fetch(new CalendarInfo('x', 'x', true), new DateTimeImmutable(), new DateTimeImmutable(), ComponentType::Event);
    }

    public function test_an_http_error_is_reported_plainly(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('gone', ['http_code' => 404]));
        $provider = new IcsProvider($client, 'https://example.com/feed.ics');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');

        $provider->fetch(new CalendarInfo('x', 'x', true), new DateTimeImmutable(), new DateTimeImmutable(), ComponentType::Event);
    }

    public function test_the_feed_is_refetched_on_every_call(): void
    {
        // A feed changes whenever it likes and has no ETag to lean on, so
        // freshness is the default and there is no "refresh" concept.
        $bodies = [
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:one\r\nDTSTART:20261003T120000Z\r\nDTEND:20261003T123000Z\r\nSUMMARY:First\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:two\r\nDTSTART:20261004T120000Z\r\nDTEND:20261004T123000Z\r\nSUMMARY:Second\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        ];

        $client = new MockHttpClient(static function () use (&$bodies): MockResponse {
            return new MockResponse(array_shift($bodies) ?? '');
        });

        $rule = new TimeZoneRule('UTC');
        $reader = new CalendarReader([new IcsProvider($client, 'https://example.com/feed.ics')], new EventMapper($rule), $rule);

        $window = [new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC')), new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC'))];

        self::assertSame('First', $reader->listEvents($window[0], $window[1])['events'][0]->summary);
        self::assertSame('Second', $reader->listEvents($window[0], $window[1])['events'][0]->summary);
    }

    public function test_its_rows_are_indistinguishable_from_a_readonly_caldav_calendar(): void
    {
        // The acceptance criterion. The same two events, once through a feed
        // and once through a CalDAV server that reports itself read-only, then
        // compared field by field — including the absence of any field that
        // would give the source away.
        $rule = new TimeZoneRule('America/New_York');

        $icsReader = new CalendarReader(
            [new IcsProvider(new MockHttpClient(static fn (): MockResponse => new MockResponse(self::FEED)), 'https://example.com/feed.ics')],
            new EventMapper($rule),
            $rule,
        );

        $caldavReader = new CalendarReader(
            [new CalDavProvider(new \App\Calendar\CalDav\CalDavClient($this->caldavStub(), 'https://dav.example.com', 'u', 'p'))],
            new EventMapper($rule),
            $rule,
        );

        $window = [
            new DateTimeImmutable('2026-10-01', new DateTimeZone('America/New_York')),
            new DateTimeImmutable('2026-11-01', new DateTimeZone('America/New_York')),
        ];

        // `calendar` carries each source's own identity — an href and a
        // label — which says *which* calendar a row came from, never *what
        // kind* of source it was. That is the one field allowed to differ, so
        // it is compared separately and the rest must match exactly.
        $strip = static function (array $row): array {
            unset($row['calendar']);

            return $row;
        };

        $icsRows = $icsReader->listEvents($window[0], $window[1])['events'];
        $caldavRows = $caldavReader->listEvents($window[0], $window[1])['events'];

        $fromIcs = array_map(static fn ($e): array => $e->toArray(), $icsRows);
        $fromCalDav = array_map(static fn ($e): array => $e->toArray(), $caldavRows);

        self::assertCount(\count($fromCalDav), $fromIcs);
        self::assertSame(
            array_map($strip, $fromCalDav),
            array_map($strip, $fromIcs),
            'a caller must not be able to tell the two sources apart',
        );

        // Both read-only, and neither carrying a field that would give the
        // source away.
        foreach ($fromIcs as $index => $row) {
            self::assertTrue($row['readonly']);
            self::assertTrue($fromCalDav[$index]['readonly']);
            self::assertSame(array_keys($fromCalDav[$index]), array_keys($row), 'the two sources must produce the same shape');

            foreach (['source', 'provider', 'kind'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $row);
            }
        }

        // The provider's own name() never reaches a row.
        foreach ($fromIcs as $row) {
            self::assertStringNotContainsString('ics', strtolower((string) json_encode($strip($row))));
        }
    }

    /**
     * A CalDAV server answering as a read-only calendar serving the same feed.
     */
    private function caldavStub(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $body = $options['body'] ?? '';

            if (str_contains($body, 'current-user-principal')) {
                return new MockResponse('<?xml version="1.0"?><multistatus xmlns="DAV:"><response><href>/</href><propstat><prop><current-user-principal><href>/p/</href></current-user-principal></prop><status>HTTP/1.1 200 OK</status></propstat></response></multistatus>');
            }

            if (str_contains($body, 'calendar-home-set')) {
                return new MockResponse('<?xml version="1.0"?><multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><response><href>/p/</href><propstat><prop><C:calendar-home-set><href>/cal/</href></C:calendar-home-set></prop><status>HTTP/1.1 200 OK</status></propstat></response></multistatus>');
            }

            if (str_contains($body, 'resourcetype')) {
                // Read-only: only a `read` privilege is advertised.
                return new MockResponse('<?xml version="1.0"?><multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><response><href>/cal/</href><propstat><prop><resourcetype><C:calendar/></resourcetype><displayname>ics</displayname><current-user-privilege-set><privilege><read/></privilege></current-user-privilege-set></prop><status>HTTP/1.1 200 OK</status></propstat></response></multistatus>');
            }

            return new MockResponse('<?xml version="1.0"?><multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><response><href>/cal/feed.ics</href><propstat><prop><getetag>"x"</getetag><C:calendar-data>'.htmlspecialchars(self::FEED, \ENT_XML1).'</C:calendar-data></prop><status>HTTP/1.1 200 OK</status></propstat></response></multistatus>');
        }, 'https://dav.example.com');
    }
}
