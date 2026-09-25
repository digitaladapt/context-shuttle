<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\CalDav\CalDavClient;
use App\Calendar\CalDav\UnsafeHref;
use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\EditableCalendar;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Write\ConcurrentModification;
use App\Calendar\Write\EventBuilder;
use App\Calendar\Write\EventDraft;
use App\Calendar\Write\EventWriter;
use App\Calendar\Write\When;
use App\Calendar\Write\WriteRefused;
use App\Calendar\Write\WriteScope;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The write path against a scripted server.
 *
 * Every request and response is pinned, which is what makes the concurrency
 * and refusal behaviour testable at all — those are the paths where being
 * wrong is expensive and where a live server would only ever exercise one
 * branch.
 *
 * @internal
 *
 * @covers \App\Calendar\Write\EventWriter
 * @covers \App\Calendar\Write\EventBuilder
 * @covers \App\Calendar\CalDav\CalDavClient
 */
final class EventWriterTest extends TestCase
{
    private const BASE = 'https://dav.example.com';

    private const DISCOVERY_PRINCIPAL = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:">
          <response><href>/</href><propstat><prop><current-user-principal><href>/p/</href></current-user-principal></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    private const DISCOVERY_HOME = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response><href>/p/</href><propstat><prop><C:calendar-home-set><href>/p/</href></C:calendar-home-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    private const DISCOVERY_CALENDARS = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response><href>/p/work/</href><propstat><prop><resourcetype><C:calendar/></resourcetype><displayname>Work</displayname><current-user-privilege-set><privilege><write-content/></privilege></current-user-privilege-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
          <response><href>/p/holidays/</href><propstat><prop><resourcetype><C:calendar/></resourcetype><displayname>Holidays</displayname><current-user-privilege-set><privilege><read/></privilege></current-user-privilege-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    private const EVENT_ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
        ."BEGIN:VEVENT\r\nUID:evt@test\r\nDTSTART:20261201T140000Z\r\nDTEND:20261201T150000Z\r\n"
        ."SUMMARY:Original\r\nLOCATION:Room 1\r\nATTENDEE;CN=Bob:mailto:bob@example.com\r\n"
        ."END:VEVENT\r\nEND:VCALENDAR\r\n";

    private const SERIES_ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
        ."BEGIN:VEVENT\r\nUID:series@test\r\nDTSTART:20261207T150000Z\r\nDTEND:20261207T160000Z\r\n"
        ."RRULE:FREQ=WEEKLY;COUNT=4\r\nSUMMARY:Weekly\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    /**
     * A recording client whose queue is the scripted responses, in order.
     *
     * Takes the same designate the writer gets, because `readonly` is narrowed
     * from it: in the container there is one `EditableCalendar` service and
     * both consumers share it, so wiring them differently here would test a
     * state the application cannot reach — and would have the writer refuse a
     * calendar the client had already marked read-only.
     *
     * @param list<array{method: string, url: string, headers: array<string, string>, body: string}> $requests  filled in as requests are made
     * @param list<MockResponse>                                                                     $responses scripted, in order
     */
    private function client(array &$requests, array $responses, string $editable = 'work'): CalDavClient
    {
        $queue = $responses;

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$queue, &$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => self::headerMap($options['headers'] ?? []),
                'body' => $options['body'] ?? '',
            ];

            return array_shift($queue) ?? new MockResponse('', ['http_code' => 404]);
        }, self::BASE);

        return new CalDavClient($httpClient, self::BASE, 'u', 'p', editableCalendar: new EditableCalendar($editable));
    }

    private function writer(CalDavClient $client, string $editable = 'work'): EventWriter
    {
        $timeZone = new TimeZoneRule('UTC');

        return new EventWriter(
            $client,
            new \App\Calendar\Provider\CalDavProvider($client),
            new EditableCalendar($editable),
            new EventBuilder($timeZone),
            $timeZone,
        );
    }

    /**
     * @return list<MockResponse>
     */
    private function discovery(): array
    {
        return [
            new MockResponse(self::DISCOVERY_PRINCIPAL),
            new MockResponse(self::DISCOVERY_HOME),
            new MockResponse(self::DISCOVERY_CALENDARS),
        ];
    }

    private function report(string $ics): MockResponse
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
            .'<response><href>/p/work/evt.ics</href><propstat><prop>'
            .'<getetag>"etag-1"</getetag>'
            .'<C:calendar-data>'.htmlspecialchars($ics, \ENT_XML1).'</C:calendar-data>'
            .'</prop><status>HTTP/1.1 200 OK</status></propstat></response></multistatus>';

        return new MockResponse($xml);
    }

    /**
     * Symfony flattens the `headers` option into `["Name: value", ...]` before
     * a mock transport sees it, so a test asserting on a header has to undo
     * that first. Kept here rather than in each test so the assertion reads as
     * the header name it cares about.
     *
     * @param array<int|string, mixed> $headers
     *
     * @return array<string, string>
     */
    private static function headerMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $name => $value) {
            if (\is_int($name)) {
                [$name, $value] = array_pad(explode(': ', (string) $value, 2), 2, '');

                // A header sent without a value is not a header we care about.
                if ('' !== (string) $name && '' !== (string) $value) {
                    $map[(string) $name] = (string) $value;
                }

                continue;
            }

            $map[(string) $name] = \is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value;
        }

        return $map;
    }

    /**
     * @param list<array{method: string, url: string, headers: array<string, mixed>, body: string}> $requests
     *
     * @return array{method: string, url: string, headers: array<string, mixed>, body: string}|null
     */
    private function lastWrite(array $requests): ?array
    {
        foreach (array_reverse($requests) as $request) {
            if (\in_array($request['method'], ['PUT', 'DELETE'], true)) {
                return $request;
            }
        }

        return null;
    }

    public function test_create_generates_an_opaque_href_and_never_uses_the_uid_as_a_path(): void
    {
        $requests = [];
        $client = $this->client($requests, [...$this->discovery(), new MockResponse('', ['http_code' => 201])]);

        $writer = $this->writer($client);
        $uid = $writer->create(new EventDraft(summary: 'New'), When::fromInstant(new DateTimeImmutable('2026-12-01T14:00:00Z')), null);

        $put = $this->lastWrite($requests);
        self::assertNotNull($put);
        self::assertSame('PUT', $put['method']);

        // The filename is generated, so a UID can never become a path. This is
        // the guard for a measured escape: a UID containing `..`, interpolated
        // into a request path, created an object outside the collection.
        self::assertStringNotContainsString($uid, $put['url']);
        self::assertMatchesRegularExpression('#^https://dav\.example\.com/p/work/[0-9a-f]{32}\.ics$#', $put['url']);

        // The UID does appear in the payload, which is where it belongs.
        self::assertStringContainsString('UID:'.$uid, $put['body']);
    }

    public function test_create_asserts_the_object_does_not_exist(): void
    {
        $requests = [];
        $client = $this->client($requests, [...$this->discovery(), new MockResponse('', ['http_code' => 201])]);

        $this->writer($client)->create(new EventDraft(summary: 'New'), When::fromInstant(new DateTimeImmutable('2026-12-01T14:00:00Z')), null);

        $put = $this->lastWrite($requests);
        self::assertNotNull($put);
        self::assertSame('*', $put['headers']['If-None-Match'] ?? null);
        self::assertArrayNotHasKey('If-Match', $put['headers']);
    }

    public function test_update_sends_the_quoted_etag_it_read(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            ...$this->discovery(),
            $this->report(self::EVENT_ICS),
            new MockResponse('', ['http_code' => 204, 'response_headers' => ['etag' => '"etag-2"']]),
        ]);

        $this->writer($client)->update(
            'evt@test',
            new EventDraft(summary: 'Renamed'),
            null,
            null,
            WriteScope::Series,
        );

        $put = $this->lastWrite($requests);
        self::assertNotNull($put);

        // Quoted on the wire, because RFC 7232 wants it and the reference
        // server enforces it: the same etag unquoted is answered with 412,
        // which reads exactly like someone else having edited the object.
        self::assertSame('"etag-1"', $put['headers']['If-Match'] ?? null);
    }

    public function test_update_leaves_unmentioned_fields_alone(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            ...$this->discovery(),
            $this->report(self::EVENT_ICS),
            new MockResponse('', ['http_code' => 204]),
        ]);

        $this->writer($client)->update('evt@test', new EventDraft(summary: 'Renamed'), null, null, WriteScope::Series);

        $put = $this->lastWrite($requests);
        self::assertNotNull($put);

        self::assertStringContainsString('SUMMARY:Renamed', $put['body']);
        // The whole reason an update re-reads before writing: properties this
        // application does not model survive.
        self::assertStringContainsString('LOCATION:Room 1', $put['body']);
        self::assertStringContainsString('ATTENDEE', $put['body']);
    }

    public function test_a_concurrent_change_is_reported_as_such(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            ...$this->discovery(),
            $this->report(self::EVENT_ICS),
            new MockResponse('', ['http_code' => 412]),
        ]);

        $this->expectException(ConcurrentModification::class);

        $this->writer($client)->update('evt@test', new EventDraft(summary: 'X'), null, null, WriteScope::Series);
    }

    public function test_a_failed_precondition_on_create_is_reported_as_a_concurrent_change(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            ...$this->discovery(),
            new MockResponse('', ['http_code' => 412]),
        ]);

        // `If-None-Match: *` failing means the object already exists, and the
        // remedy is the same one: re-read and decide.
        $this->expectException(ConcurrentModification::class);

        $this->writer($client)->create(new EventDraft(summary: 'X'), When::fromInstant(new DateTimeImmutable('2026-12-01T14:00:00Z')), null);
    }

    public function test_occurrence_edit_writes_an_override_into_the_same_object(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            ...$this->discovery(),
            $this->report(self::SERIES_ICS),
            new MockResponse('', ['http_code' => 204]),
        ]);

        $this->writer($client)->update(
            'series@test::2026-12-14T15:00:00Z',
            new EventDraft(summary: 'Moved'),
            When::parse('2026-12-14 15:00', new TimeZoneRule('UTC'), 'start'),
            null,
            WriteScope::Occurrence,
        );

        $put = $this->lastWrite($requests);
        self::assertNotNull($put);

        // An in-place PUT of the same href — not a second object, which the
        // server would reject, and not a DELETE, which would take the series.
        self::assertSame('https://dav.example.com/p/work/evt.ics', $put['url']);
        self::assertStringContainsString('RECURRENCE-ID:20261214T150000Z', $put['body']);
        self::assertStringContainsString('RRULE:FREQ=WEEKLY;COUNT=4', $put['body'], 'the series must survive an occurrence edit');
        self::assertSame(2, substr_count($put['body'], 'BEGIN:VEVENT'), 'master plus one override');
    }

    public function test_a_deleted_occurrence_is_excluded_not_deleted(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            ...$this->discovery(),
            $this->report(self::SERIES_ICS),
            new MockResponse('', ['http_code' => 204]),
        ]);

        $this->writer($client)->delete('series@test::2026-12-14T15:00:00Z', WriteScope::Occurrence);

        $put = $this->lastWrite($requests);
        self::assertNotNull($put);
        self::assertSame('PUT', $put['method'], 'removing one occurrence must not DELETE the object');
        self::assertStringContainsString('EXDATE:20261214T150000Z', $put['body']);
        self::assertStringContainsString('RRULE:FREQ=WEEKLY;COUNT=4', $put['body']);
    }

    public function test_deleting_a_whole_series_is_a_real_delete(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            ...$this->discovery(),
            $this->report(self::SERIES_ICS),
            new MockResponse('', ['http_code' => 200]),
        ]);

        $this->writer($client)->delete('series@test', WriteScope::Series);

        $delete = $this->lastWrite($requests);
        self::assertNotNull($delete);
        self::assertSame('DELETE', $delete['method']);
        self::assertSame('"etag-1"', $delete['headers']['If-Match'] ?? null);
    }

    /**
     * A moved occurrence must be findable by the id the caller was given.
     *
     * Regression, and a measured one: the lookup originally fetched a narrow
     * time window around the id, and a `calendar-query` with a `<time-range>`
     * returned **zero** objects for a series whose only occurrence in that
     * window had been moved out of it by an override. The id a caller holds is
     * most likely to name exactly such an occurrence — that is *why* they were
     * handed it — so the windowed lookup failed on the case that matters.
     *
     * Scripted as one REPORT answering with the master and an override whose
     * times fall outside the requested window, which is what the server does.
     */
    public function test_a_moved_occurrence_is_found_even_though_its_new_time_is_outside_any_window(): void
    {
        $moved = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\n"
            ."BEGIN:VEVENT\r\nUID:series@test\r\nDTSTART:20261207T150000Z\r\nDTEND:20261207T160000Z\r\n"
            ."RRULE:FREQ=WEEKLY;COUNT=4\r\nSUMMARY:Weekly\r\nEND:VEVENT\r\n"
            // The override sits a week away from the slot it replaces.
            ."BEGIN:VEVENT\r\nUID:series@test\r\nRECURRENCE-ID:20261214T150000Z\r\n"
            ."DTSTART:20261221T090000Z\r\nDTEND:20261221T100000Z\r\nSUMMARY:Moved\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";

        $requests = [];
        $client = $this->client($requests, [
            ...$this->discovery(),
            $this->report($moved),
            new MockResponse('', ['http_code' => 204]),
        ]);

        $this->writer($client)->update(
            'series@test::2026-12-21T09:00:00Z',
            new EventDraft(summary: 'Moved again'),
            null,
            null,
            WriteScope::Occurrence,
        );

        $put = $this->lastWrite($requests);
        self::assertNotNull($put);

        // The override is replaced rather than stacked beside itself, and its
        // `RECURRENCE-ID` is the *original* slot — recovered by expanding the
        // object, which is the only way the two can be told apart.
        self::assertSame(2, substr_count($put['body'], 'BEGIN:VEVENT'));
        self::assertStringContainsString('RECURRENCE-ID:20261214T150000Z', $put['body']);
        self::assertStringContainsString('SUMMARY:Moved again', $put['body']);
        self::assertStringContainsString('RRULE:FREQ=WEEKLY;COUNT=4', $put['body']);
    }

    /**
     * The lookup must not narrow by time at all.
     *
     * Asserted on the request the writer makes, because the failure above was
     * silent: a windowed query answers `200` with an empty `multistatus`, which
     * is indistinguishable from "no such event" and produced a refusal naming
     * a perfectly valid id.
     */
    public function test_the_lookup_sends_no_time_range(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            ...$this->discovery(),
            $this->report(self::SERIES_ICS),
            new MockResponse('', ['http_code' => 204]),
        ]);

        $this->writer($client)->update('series@test::2026-12-14T15:00:00Z', new EventDraft(summary: 'X'), null, null, WriteScope::Occurrence);

        $report = null;
        foreach ($requests as $request) {
            if (str_contains($request['body'], 'calendar-query')) {
                $report = $request;
            }
        }

        self::assertNotNull($report, 'the writer should resolve the object with a REPORT');
        self::assertStringContainsString('UID', $report['body']);
        self::assertStringNotContainsString('time-range', $report['body']);
    }

    public function test_an_unknown_id_is_refused_with_a_sentence(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            ...$this->discovery(),
            new MockResponse('<?xml version="1.0" encoding="utf-8"?><multistatus xmlns="DAV:"/>'),
        ]);

        $this->expectException(WriteRefused::class);
        $this->expectExceptionMessageMatches('/No event found/');

        $this->writer($client)->update('nope@test', new EventDraft(summary: 'X'), null, null, WriteScope::Series);
    }

    public function test_a_designate_matching_nothing_is_refused_and_names_what_was_offered(): void
    {
        $requests = [];
        $client = $this->client($requests, $this->discovery());

        $writer = $this->writer($client, 'typo-calendar');

        try {
            $writer->create(new EventDraft(summary: 'X'), When::fromInstant(new DateTimeImmutable('2026-12-01T14:00:00Z')), null);
            self::fail('expected a refusal');
        } catch (WriteRefused $e) {
            // The operator's typo has to be actionable, which means naming
            // what the server actually offered rather than "writes are broken".
            self::assertStringContainsString('typo-calendar', $e->getMessage());
            self::assertStringContainsString('/p/work/', $e->getMessage());
            self::assertStringContainsString('/p/holidays/', $e->getMessage());
        }

        self::assertNull($this->lastWrite($requests), 'nothing may be written when the target is unresolved');
    }

    public function test_a_read_only_designate_is_refused(): void
    {
        $requests = [];
        $client = $this->client($requests, $this->discovery());

        $writer = $this->writer($client, 'holidays');

        $this->expectException(WriteRefused::class);
        $this->expectExceptionMessageMatches('/does not allow writing/');

        $writer->create(new EventDraft(summary: 'X'), When::fromInstant(new DateTimeImmutable('2026-12-01T14:00:00Z')), null);
    }

    public function test_an_unset_designate_refuses_even_if_a_writer_is_built_by_hand(): void
    {
        $requests = [];
        $client = $this->client($requests, []);

        $this->expectException(WriteRefused::class);
        $this->expectExceptionMessageMatches('/CALDAV_EDITABLE_CALENDAR is not set/');

        $this->writer($client, '')->create(new EventDraft(summary: 'X'), When::fromInstant(new DateTimeImmutable('2026-12-01T14:00:00Z')), null);
    }

    /**
     * The measured escape: a `..` in an href walks out of the collection.
     *
     * Asserted here as well as at the client, because this is the boundary the
     * whole arrangement exists to hold — a write tool that can be pointed
     * outside its one calendar is worse than no write tool.
     */
    public function test_a_traversal_href_is_refused_before_any_request(): void
    {
        $requests = [];
        $client = $this->client($requests, []);

        $calendar = new CalendarInfo('/p/work/', 'Work', false);

        foreach ([
            '/p/work/../../pwned.ics',
            '/p/work/evil%2F..%2F..%2Fpwned.ics',
            '/p/pwned.ics',
        ] as $href) {
            try {
                $client->put($calendar, $href, 'x', mustNotExist: true);
                self::fail(\sprintf('%s should have been refused', $href));
            } catch (UnsafeHref) {
                // Expected.
            }
        }

        self::assertSame([], $requests, 'a refused write must not reach the network');
    }
}
