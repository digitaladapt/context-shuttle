<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\CalDav\CalDavClient;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The `CALDAV_CALENDARS` allowlist.
 *
 * This is the exposure boundary: it decides which calendars a deployment
 * serves at all, so a misconfiguration must be visible rather than quietly
 * serving a narrower (or wider) set than the operator intended.
 *
 * @internal
 *
 * @covers \App\Calendar\CalDav\CalDavClient
 */
final class CalendarAllowlistTest extends TestCase
{
    private const BASE = 'https://dav.example.com';

    private const PRINCIPAL_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:">
          <response><href>/</href><propstat><prop><current-user-principal><href>/p/</href></current-user-principal></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    private const HOME_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response><href>/p/</href><propstat><prop><C:calendar-home-set><href>/p/</href></C:calendar-home-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    private const CALENDARS_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response><href>/p/</href><propstat><prop><resourcetype><principal/></resourcetype></prop><status>HTTP/1.1 200 OK</status></propstat></response>
          <response><href>/p/work/</href><propstat><prop><resourcetype><C:calendar/></resourcetype><displayname>Work</displayname><current-user-privilege-set><privilege><write-content/></privilege></current-user-privilege-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
          <response><href>/p/personal/</href><propstat><prop><resourcetype><C:calendar/></resourcetype><displayname>Personal</displayname><current-user-privilege-set><privilege><write-content/></privilege></current-user-privilege-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
          <response><href>/p/holidays/</href><propstat><prop><resourcetype><C:calendar/></resourcetype><displayname>Holidays</displayname><current-user-privilege-set><privilege><read/></privilege></current-user-privilege-set></prop><status>HTTP/1.1 200 OK</status></propstat></response>
        </multistatus>
        XML;

    private function client(string $allowlist, ?RecordingLogger $logger = null): CalDavClient
    {
        $queue = [
            new MockResponse(self::PRINCIPAL_XML),
            new MockResponse(self::HOME_XML),
            new MockResponse(self::CALENDARS_XML),
        ];

        $logger ??= new RecordingLogger();

        return new CalDavClient(
            new MockHttpClient(static function () use (&$queue): MockResponse {
                return array_shift($queue) ?? new MockResponse('', ['http_code' => 404]);
            }, self::BASE),
            self::BASE,
            'u',
            'p',
            allowedCalendars: $allowlist,
            logger: $logger,
        );
    }

    /**
     * @return list<string>
     */
    private function hrefs(CalDavClient $client): array
    {
        return array_map(static fn ($info): string => $info->href, $client->discoverCalendars());
    }

    public function test_empty_means_every_discovered_calendar(): void
    {
        self::assertSame(['/p/work/', '/p/personal/', '/p/holidays/'], $this->hrefs($this->client('')));
    }

    public function test_whitespace_only_means_every_calendar(): void
    {
        self::assertSame(['/p/work/', '/p/personal/', '/p/holidays/'], $this->hrefs($this->client('   ')));
    }

    public function test_a_full_href_selects_one_calendar(): void
    {
        self::assertSame(['/p/work/'], $this->hrefs($this->client('/p/work/')));
    }

    public function test_a_bare_segment_selects_one_calendar(): void
    {
        // What a human types when reading .env.
        self::assertSame(['/p/personal/'], $this->hrefs($this->client('personal')));
    }

    public function test_several_entries_select_several_calendars(): void
    {
        self::assertSame(['/p/work/', '/p/holidays/'], $this->hrefs($this->client('/p/work/, holidays')));
    }

    public function test_surrounding_whitespace_and_empty_entries_are_tolerated(): void
    {
        self::assertSame(['/p/work/', '/p/personal/'], $this->hrefs($this->client('  /p/work/ , , personal ,  ')));
    }

    public function test_a_bare_segment_does_not_match_a_longer_name(): void
    {
        // `work` must not match `/p/homework/`: matching on a substring would
        // make the allowlist quietly wider than it reads.
        self::assertSame([], $this->hrefs($this->client('home')));
    }

    public function test_an_entry_that_matches_nothing_is_logged_with_the_discovered_hrefs(): void
    {
        $logger = new RecordingLogger();
        $this->client('/p/typo/', $logger)->discoverCalendars();

        $warnings = $logger->warnings();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('CALDAV_CALENDARS', $warnings[0]['message']);
        self::assertSame('/p/typo/', $warnings[0]['context']['entry']);
        // The discovered list is in the context, so the operator can see what
        // the server actually offered instead of guessing at the spelling.
        self::assertSame(['/p/work/', '/p/personal/', '/p/holidays/'], $warnings[0]['context']['discovered']);
    }

    public function test_a_matching_entry_is_not_logged(): void
    {
        $logger = new RecordingLogger();
        $this->client('/p/work/', $logger)->discoverCalendars();

        self::assertSame([], $logger->warnings());
    }

    public function test_the_allowlist_does_not_affect_a_calendars_readonly_flag(): void
    {
        $calendars = $this->client('/p/holidays/')->discoverCalendars();

        self::assertCount(1, $calendars);
        self::assertTrue($calendars[0]->readonly, 'a read-only calendar stays read-only through the allowlist');
    }
}

/**
 * A PSR logger that keeps what it was told, so a test can assert on it.
 *
 * @internal
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private array $entries = [];

    #[Override]
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->entries, static fn (array $e): bool => 'warning' === $e['level']));
    }
}
