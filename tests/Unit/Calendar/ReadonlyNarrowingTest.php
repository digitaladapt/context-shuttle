<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\CalDav\CalDavClient;
use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\EditableCalendar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What `readonly` means once writes exist.
 *
 * The design narrows it from "the server's own privilege set" to "**these
 * tools** can edit this row", and the reason is that the first reading is
 * actively misleading on a real account: a server may let us write to five
 * calendars while only one is designated, so the field whose entire purpose is
 * to answer "can I edit this?" would have said yes about four calendars the
 * write tools will refuse.
 *
 * Both conditions have to hold — the server permits it **and** it is the
 * configured calendar — so these tests cover the four combinations.
 *
 * @internal
 *
 * @covers \App\Calendar\CalDav\CalDavClient
 * @covers \App\Calendar\Domain\EditableCalendar
 */
final class ReadonlyNarrowingTest extends TestCase
{
    private const BASE = 'https://dav.example.com';

    private const PRINCIPAL = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:">
          <response><href>/</href><propstat><prop><current-user-principal><href>/p/</href></current-user-principal></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    private const HOME = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response><href>/p/</href><propstat><prop><C:calendar-home-set><href>/p/</href></C:calendar-home-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    private const CALENDARS = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response><href>/p/work/</href><propstat><prop><resourcetype><C:calendar/></resourcetype><displayname>Work</displayname><current-user-privilege-set><privilege><write-content/></privilege></current-user-privilege-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
          <response><href>/p/personal/</href><propstat><prop><resourcetype><C:calendar/></resourcetype><displayname>Personal</displayname><current-user-privilege-set><privilege><write-content/></privilege></current-user-privilege-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
          <response><href>/p/holidays/</href><propstat><prop><resourcetype><C:calendar/></resourcetype><displayname>Holidays</displayname><current-user-privilege-set><privilege><read/></privilege></current-user-privilege-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    /**
     * @return array<string, bool> href => readonly
     */
    private function discover(string $editable): array
    {
        $queue = [
            new MockResponse(self::PRINCIPAL),
            new MockResponse(self::HOME),
            new MockResponse(self::CALENDARS),
        ];

        // `$queue` is captured by reference: each request consumes one scripted
        // response, and a by-value capture would hand every request the first
        // one while running the queue out of step with the calls.
        $httpClient = new MockHttpClient(static function () use (&$queue): MockResponse {
            return array_shift($queue) ?? new MockResponse('', ['http_code' => 404]);
        }, self::BASE);

        $client = new CalDavClient(
            $httpClient,
            self::BASE,
            'u',
            'p',
            editableCalendar: new EditableCalendar($editable),
        );

        $result = [];
        foreach ($client->discoverCalendars() as $info) {
            $result[$info->href] = $info->readonly;
        }

        return $result;
    }

    /**
     * With nothing designated, every calendar is read-only — including the two
     * the server would happily accept a write for.
     */
    public function test_with_no_designate_every_row_is_read_only(): void
    {
        $calendars = $this->discover('');

        self::assertTrue($calendars['/p/work/']);
        self::assertTrue($calendars['/p/personal/']);
        self::assertTrue($calendars['/p/holidays/']);
    }

    /**
     * The case the narrowing exists for.
     *
     * `personal` is writable on the server, and reporting that as editable
     * would have a caller attempt an edit these tools refuse. It reads
     * read-only not because the server forbids it but because this deployment
     * will not do it.
     */
    public function test_a_writable_but_undesignated_calendar_reports_read_only(): void
    {
        $calendars = $this->discover('work');

        self::assertFalse($calendars['/p/work/'], 'the designated calendar is editable');
        self::assertTrue($calendars['/p/personal/'], 'a writable calendar we will not touch must not claim to be editable');
    }

    /**
     * Both conditions, not either: a designate the server forbids writing is
     * still read-only.
     *
     * The alternative would be to report it editable and let every write fail
     * at the server, which is the same lie in the opposite direction.
     */
    public function test_a_read_only_calendar_is_read_only_even_when_designated(): void
    {
        $calendars = $this->discover('holidays');

        self::assertTrue($calendars['/p/holidays/']);
    }

    public function test_the_designate_matches_by_href_or_bare_name(): void
    {
        // Both forms an operator might write, for the same calendar.
        foreach (['work', '/p/work', '/p/work/'] as $designate) {
            $calendars = $this->discover($designate);

            self::assertFalse(
                $calendars['/p/work/'],
                \sprintf('"%s" should designate /p/work/', $designate),
            );
        }
    }

    public function test_a_bare_name_does_not_match_a_similarly_ended_href(): void
    {
        $calendar = new CalendarInfo('/p/homework/', 'Homework', false);

        self::assertFalse(
            (new EditableCalendar('work'))->matches($calendar),
            '"work" must not match "/p/homework/"',
        );
    }
}
