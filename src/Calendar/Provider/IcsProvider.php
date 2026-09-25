<?php

declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\CalendarObject;
use App\Calendar\Domain\ComponentType;
use DateTimeImmutable;
use Override;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * An ICS feed as a calendar source.
 *
 * The whole point is that this is **not** special: one synthetic calendar,
 * every row marked read-only, and otherwise the same objects the reader
 * already knows how to expand and normalize. Nothing in the output says
 * "ICS", because a caller that could tell would branch on it.
 *
 * Three properties follow from what a feed *is*:
 *
 * - **Fetched whole, every call.** A feed has no `ETag`/`Last-Modified` to
 *   lean on and no way to ask for part of it, so `$from`/`$to` are ignored
 *   and nothing is cached. Freshness is the default; there is no "refresh"
 *   concept to expose.
 * - **`http(s)` only** (finding 13). `symfony/http-client` refuses
 *   `file://`, and supporting it would mean a second fetch path and a second
 *   test idiom for a case that is rare, awkward in a container, and has no
 *   cache headers to be clever with.
 * - **Always read-only**, which is the one difference a caller sees — and
 *   the design keeps that difference to exactly one flag.
 */
final readonly class IcsProvider implements CalendarProvider
{
    /**
     * A large body is a misconfiguration or a hostile feed, not a calendar.
     * Bounded so a surprise cannot exhaust memory before anything notices.
     */
    private const MAX_BYTES = 20 * 1024 * 1024;

    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $url,
        private string $name = 'ics',
    ) {
    }

    #[Override]
    public function name(): string
    {
        return 'ics';
    }

    /**
     * Whether this source is configured at all.
     *
     * False for an empty URL, so the wiring can treat "no feed" as absent
     * rather than as a source that fails on every call.
     */
    #[Override]
    public function isConfigured(): bool
    {
        return '' !== trim($this->url);
    }

    /**
     * The one synthetic calendar a feed represents.
     *
     * A feed is flat: there is no discovery, no hierarchy, and no per-feed
     * privileges, so there is exactly one calendar and it is read-only.
     *
     * @return list<CalendarInfo>
     */
    #[Override]
    public function listCalendars(): array
    {
        return [$this->calendar()];
    }

    /**
     * The whole feed, as unexpanded components.
     *
     * `$from`/`$to` are deliberately ignored: a feed cannot be asked for a
     * window, and pretending otherwise would mean filtering here, in a second
     * place that could disagree with the reader about what a window means.
     *
     * @return list<CalendarObject>
     */
    #[Override]
    public function fetch(CalendarInfo $calendar, DateTimeImmutable $from, DateTimeImmutable $to, ComponentType $type): array
    {
        return [$this->download()];
    }

    /**
     * The whole feed, for a UID lookup.
     *
     * The client side is where the UID is matched, so this is the same fetch:
     * there is no server to ask, and a second code path would only be a
     * second place to get it wrong.
     *
     * @return list<CalendarObject>
     */
    #[Override]
    public function fetchByUid(CalendarInfo $calendar, string $uid, ComponentType $type): array
    {
        return [$this->download()];
    }

    private function calendar(): CalendarInfo
    {
        return new CalendarInfo(
            href: $this->url,
            name: '' === trim($this->name) ? 'ics' : trim($this->name),
            readonly: true,
        );
    }

    /**
     * Fetch and validate the feed, as one calendar object.
     *
     * The body is returned as a single object rather than split per component
     * because the payload's own `VTIMEZONE` definitions have to travel with
     * the events that reference them — splitting would strand an event whose
     * `TZID` is declared once at the top of the feed.
     */
    private function download(): CalendarObject
    {
        $url = $this->assertConfigured();

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'text/calendar, text/plain, */*'],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(\sprintf('Could not reach the calendar feed: %s', $e->getMessage()), 0, $e);
        }

        if ($status >= 400) {
            throw new RuntimeException(\sprintf('The calendar feed returned HTTP %d.', $status));
        }

        try {
            $body = $response->getContent();
        } catch (Throwable $e) {
            throw new RuntimeException(\sprintf('Could not read the calendar feed: %s', $e->getMessage()), 0, $e);
        }

        if (\strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException(\sprintf('The calendar feed is larger than %d MB and was not read.', intdiv(self::MAX_BYTES, 1024 * 1024)));
        }

        if (!str_contains($body, 'BEGIN:VCALENDAR')) {
            // A login page or an error document would otherwise parse as an
            // empty calendar, which looks like a feed with no events rather
            // than like the misconfiguration it is.
            throw new RuntimeException('The calendar feed did not return iCalendar data (no BEGIN:VCALENDAR in the response).');
        }

        return new CalendarObject(
            href: $url,
            data: $body,
            etag: null,
            calendar: $this->calendar(),
        );
    }

    /**
     * @return string the validated URL
     */
    private function assertConfigured(): string
    {
        $url = trim($this->url);

        if ('' === $url) {
            throw new RuntimeException('ICS_URL is not configured. Set it in .env.local to the http(s) URL of a calendar feed.');
        }

        // `file://` and friends are refused with the reason, rather than
        // ignored, so an operator who tries one learns why (finding 13).
        if (1 !== preg_match('#^https?://#i', $url)) {
            throw new RuntimeException(\sprintf('ICS_URL must be an http(s) URL, and "%s" is not. Local files are not supported: fetch them over HTTP instead.', $url));
        }

        return $url;
    }
}
