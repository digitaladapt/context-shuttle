<?php

declare(strict_types=1);

namespace App\Calendar\CalDav;

use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\CalendarObject;
use App\Calendar\Domain\ComponentType;
use App\Xml\DavMultistatusParser;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * The three CalDAV requests the read path needs: `PROPFIND` to discover
 * calendars, `REPORT calendar-query` to list, and `REPORT calendar-multiget`
 * to fetch by href.
 *
 * Deliberately hand-rolled over `symfony/http-client` rather than built on
 * `sabre/dav`'s client: the read surface is three XML-over-HTTP requests,
 * and the project already has one HTTP stack whose tests use
 * `MockHttpClient`. Basic auth only — what Radicale, Nextcloud, Baïkal,
 * Fastmail and Apple app passwords all accept.
 */
final readonly class CalDavClient
{
    /**
     * Idempotent requests get one retry when the connection drops.
     *
     * CalDAV servers restart, and a stale keep-alive socket turns a scary
     * failure into a slow success. Sent twice, `PROPFIND` and `REPORT` have
     * no side effects.
     */
    private const MAX_ATTEMPTS = 2;

    private const TIMEOUT_SECONDS = 15;

    private const NAMESPACE_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $configuredBaseUrl,
        private string $username,
        private string $password,
        private DavMultistatusParser $parser = new DavMultistatusParser(),
        private string $allowedCalendars = '',
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The base URL without a trailing slash, so joining a path to it cannot
     * produce a doubled separator.
     */
    private function baseUrl(): string
    {
        return rtrim(trim($this->configuredBaseUrl), '/');
    }

    /**
     * Calendars visible to this account, discovered by `resourcetype`.
     *
     * Discovery is property-driven, never name-driven: on the reference
     * server `displayname` returned the *path* (`lyra/work`), so a name is a
     * label and `href` is the identifier.
     *
     * The bootstrap is the standard DAV one, and it needs two extra
     * requests. The account's base URL is a *principal*, not a collection of
     * calendars: a `Depth: 1` PROPFIND against it stops at the principal
     * itself and finds no calendars at all. `current-user-principal`
     * locates the principal, then `calendar-home-set` locates the
     * collection that actually holds the calendars. On Radicale those are
     * the same href; on Nextcloud the home is
     * `/remote.php/dav/calendars/<user>/`, so hardcoding the base URL would
     * work against one server and silently find nothing on another.
     *
     * @return list<CalendarInfo>
     */
    public function discoverCalendars(): array
    {
        $home = $this->calendarHomeSet() ?? '/';

        $body = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <d:propfind xmlns:d="DAV:" xmlns:c="{$this->namespaceCalDav()}">
              <d:prop>
                <d:resourcetype/>
                <d:displayname/>
                <d:current-user-privilege-set/>
              </d:prop>
            </d:propfind>
            XML;

        $xml = $this->request('PROPFIND', $this->absolute($home), $body, depth: '1');

        $calendars = [];

        foreach ($this->parser->parse($xml) as $response) {
            if (!$this->parser->isCalendar($response)) {
                continue;
            }

            $href = $this->parser->href($response);

            if (null === $href) {
                continue;
            }

            $calendars[] = new CalendarInfo(
                href: $this->normalizeHref($href),
                name: $this->parser->displayName($response) ?? $href,
                readonly: !$this->parser->isWritable($response),
            );
        }

        return $this->applyAllowlist($calendars);
    }

    /**
     * Keep only the calendars the operator asked for.
     *
     * `CALDAV_CALENDARS` exists because a real account also sees subscribed
     * holidays and shared team calendars, and every one of those would
     * otherwise land in every answer. Empty means "all", so a deployment
     * that has not thought about it still works.
     *
     * The allowlist is *the* exposure boundary, so it fails closed: a listed
     * href that discovery did not return is not silently ignored, it is
     * reported, because the usual cause is a typo or a calendar the account
     * cannot see — and both are worth knowing about rather than quietly
     * serving a narrower answer than the operator intended.
     *
     * @param list<CalendarInfo> $calendars
     *
     * @return list<CalendarInfo>
     */
    private function applyAllowlist(array $calendars): array
    {
        $allowed = $this->allowedHrefs();

        if ([] === $allowed) {
            return $calendars;
        }

        $kept = [];

        $matched = [];

        foreach ($calendars as $info) {
            foreach ($allowed as $wanted) {
                if ($this->hrefMatches($info->href, $wanted)) {
                    $kept[] = $info;
                    $matched[$wanted] = true;

                    continue 2;
                }
            }
        }

        // An entry that matched nothing is nearly always a typo or a
        // calendar this account cannot see. Both are worth a log line,
        // because the alternative is a listing that looks merely empty.
        foreach ($allowed as $wanted) {
            if (!isset($matched[$wanted])) {
                $this->logger?->warning('CALDAV_CALENDARS entry matched no discovered calendar.', [
                    'entry' => $wanted,
                    'discovered' => array_map(static fn (CalendarInfo $info): string => $info->href, $calendars),
                ]);
            }
        }

        return $kept;
    }

    /**
     * The configured hrefs, as a list, ignoring empty entries.
     *
     * @return list<string>
     */
    private function allowedHrefs(): array
    {
        if ('' === trim($this->allowedCalendars)) {
            return [];
        }

        $hrefs = [];

        foreach (explode(',', $this->allowedCalendars) as $candidate) {
            $candidate = trim($candidate);

            if ('' !== $candidate) {
                $hrefs[] = $candidate;
            }
        }

        return $hrefs;
    }

    /**
     * Whether a discovered href is the one the operator named.
     *
     * Compared on the trailing path segment as well as exactly, so both
     * `/lyra/work/` and `work` match — the former is what a server returns
     * and the latter is what a human types when reading `.env`.
     */
    private function hrefMatches(string $href, string $wanted): bool
    {
        $trimmed = rtrim($href, '/');
        $wantedTrimmed = rtrim($wanted, '/');

        if ($trimmed === $wantedTrimmed) {
            return true;
        }

        // `work` should match `/lyra/work/` without matching `/lyra/homework/`.
        return basename($trimmed) === $wantedTrimmed;
    }

    /**
     * Objects in a calendar that carry this UID, wherever they fall in time.
     *
     * Used by `calendar_get_event` for a plain UID, where the caller has not
     * told us *when* the thing is — so no `<time-range>` can be applied and
     * the match has to be on the property. Radicale supports a `UID`
     * `prop-filter`; servers that do not simply return the calendar's
     * objects, which the caller filters on UID afterwards.
     *
     * @return list<CalendarObject>
     */
    public function fetchByUid(CalendarInfo $calendar, string $uid, ComponentType $type): array
    {
        $body = \sprintf(
            <<<'XML'
                <?xml version="1.0" encoding="utf-8"?>
                <c:calendar-query xmlns:d="DAV:" xmlns:c="%s">
                  <d:prop>
                    <d:getetag/>
                    <c:calendar-data/>
                  </d:prop>
                  <c:filter>
                    <c:comp-filter name="VCALENDAR">
                      <c:comp-filter name="%s">
                        <c:prop-filter name="UID">
                          <c:text-match collation="i;octet">%s</c:text-match>
                        </c:prop-filter>
                      </c:comp-filter>
                    </c:comp-filter>
                  </c:filter>
                </c:calendar-query>
                XML,
            $this->namespaceCalDav(),
            $type->value,
            htmlspecialchars($uid, \ENT_XML1),
        );

        $xml = $this->request('REPORT', $this->url($calendar), $body, depth: '1');

        return $this->collectObjects($xml, $calendar);
    }

    /**
     * Unexpanded objects overlapping the window.
     *
     * `calendar-query` is asked for a time range but **never** for
     * `<expand>`: server-side expansion was measured to apply the recurrence
     * interval to the first occurrence's UTC instant and never re-resolve
     * the offset, so a DST-crossing series came back an hour out. Expansion
     * happens locally instead.
     *
     * @return list<CalendarObject>
     */
    public function fetchByTimeRange(CalendarInfo $calendar, DateTimeImmutable $from, DateTimeImmutable $to, ComponentType $type): array
    {
        $body = \sprintf(
            <<<'XML'
                <?xml version="1.0" encoding="utf-8"?>
                <c:calendar-query xmlns:d="DAV:" xmlns:c="%s">
                  <d:prop>
                    <d:getetag/>
                    <c:calendar-data/>
                  </d:prop>
                  <c:filter>
                    <c:comp-filter name="VCALENDAR">
                      <c:comp-filter name="%s">
                        <c:time-range start="%s" end="%s"/>
                      </c:comp-filter>
                    </c:comp-filter>
                  </c:filter>
                </c:calendar-query>
                XML,
            $this->namespaceCalDav(),
            $type->value,
            $from->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            $to->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
        );

        $xml = $this->request('REPORT', $this->url($calendar), $body, depth: '1');

        return $this->collectObjects($xml, $calendar);
    }

    /**
     * Fetch specific objects by href in one round trip.
     *
     * @param list<string> $hrefs
     *
     * @return list<CalendarObject>
     */
    public function fetchByHref(CalendarInfo $calendar, array $hrefs): array
    {
        if ([] === $hrefs) {
            return [];
        }

        $links = '';

        foreach ($hrefs as $href) {
            $links .= \sprintf('<d:href>%s</d:href>', htmlspecialchars($href, \ENT_XML1));
        }

        $body = \sprintf(
            <<<'XML'
                <?xml version="1.0" encoding="utf-8"?>
                <c:calendar-multiget xmlns:d="DAV:" xmlns:c="%s">
                  <d:prop>
                    <d:getetag/>
                    <c:calendar-data/>
                  </d:prop>
                  %s
                </c:calendar-multiget>
                XML,
            $this->namespaceCalDav(),
            $links,
        );

        $xml = $this->request('REPORT', $this->url($calendar), $body, depth: '1');

        return $this->collectObjects($xml, $calendar);
    }

    /**
     * @return list<CalendarObject>
     */
    private function collectObjects(string $xml, CalendarInfo $calendar): array
    {
        $objects = [];

        foreach ($this->parser->parse($xml) as $response) {
            // A per-item failure (404 inside a 207) is skipped rather than
            // failing the batch: one missing object must not hide the rest.
            if (!$this->parser->isSuccess($response)) {
                continue;
            }

            $data = $this->parser->calendarData($response);

            if (null === $data) {
                continue;
            }

            $href = $this->parser->href($response);

            $objects[] = new CalendarObject(
                href: null === $href ? '' : $this->normalizeHref($href),
                data: $data,
                etag: $this->parser->etag($response),
                calendar: $calendar,
            );
        }

        return $objects;
    }

    /**
     * The collection holding this account's calendars, if the server names
     * one.
     */
    private function calendarHomeSet(): ?string
    {
        $principal = $this->currentUserPrincipal();

        if (null === $principal) {
            return null;
        }

        $body = \sprintf(
            <<<'XML'
                <?xml version="1.0" encoding="utf-8"?>
                <d:propfind xmlns:d="DAV:" xmlns:c="%s">
                  <d:prop><c:calendar-home-set/></d:prop>
                </d:propfind>
                XML,
            $this->namespaceCalDav(),
        );

        $xml = $this->request('PROPFIND', $this->absolute($principal), $body, depth: '0');

        foreach ($this->parser->parse($xml) as $response) {
            $href = $this->parser->nestedHref($response, '{'.$this->namespaceCalDav().'}calendar-home-set');

            if (null !== $href) {
                return $this->absolute($href);
            }
        }

        return null;
    }

    /**
     * This account's principal URL, if the server advertises one.
     */
    private function currentUserPrincipal(): ?string
    {
        $body = <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:propfind xmlns:d="DAV:">
              <d:prop><d:current-user-principal/></d:prop>
            </d:propfind>
            XML;

        $xml = $this->request('PROPFIND', $this->baseUrl().'/', $body, depth: '0');

        foreach ($this->parser->parse($xml) as $response) {
            $href = $this->parser->nestedHref($response, '{DAV:}current-user-principal');

            if (null !== $href) {
                return $this->absolute($href);
            }
        }

        return null;
    }

    /**
     * Perform one DAV request, retrying once on a transport error or `5xx`.
     */
    private function request(string $method, string $url, string $body, string $depth): string
    {
        $this->assertConfigured();

        $url = $this->absolute($url);

        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            try {
                $response = $this->httpClient->request($method, $url, [
                    'auth_basic' => [$this->username, $this->password],
                    'headers' => [
                        'Content-Type' => 'application/xml; charset=utf-8',
                        'Depth' => $depth,
                        'Accept' => 'application/xml, text/xml',
                    ],
                    'body' => $body,
                    'timeout' => self::TIMEOUT_SECONDS,
                ]);

                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $e) {
                $lastError = $e;
                $this->drain($response ?? null);

                continue;
            }

            if (401 === $status || 403 === $status) {
                // A misconfigured deployment, not bad data: fail the call,
                // name the variable, and never echo the password.
                throw new RuntimeException('The calendar server rejected the credentials. Check CALDAV_USERNAME and CALDAV_PASSWORD (an app password is usually required).');
            }

            if ($status >= 500 && $attempt < self::MAX_ATTEMPTS) {
                $lastError = new RuntimeException(\sprintf('calendar server returned HTTP %d', $status));
                $this->drain($response);

                continue;
            }

            if ($status >= 400) {
                $detail = $this->safeBody($response);

                throw new RuntimeException(\sprintf('The calendar server returned HTTP %d for %s.%s', $status, $method, '' === $detail ? '' : ' '.$detail));
            }

            return $response->getContent();
        }

        throw new RuntimeException(\sprintf('Could not reach the calendar server at %s: %s', $this->baseUrl(), $lastError?->getMessage() ?? 'unknown transport error'), 0, $lastError);
    }

    /**
     * Release a response we are abandoning, so the connection can be reused
     * instead of being torn down mid-flight.
     */
    private function drain(?ResponseInterface $response): void
    {
        if (null === $response) {
            return;
        }

        try {
            $response->getContent(false);
        } catch (Throwable) {
            // Best effort only.
        }
    }

    private function safeBody(ResponseInterface $response): string
    {
        try {
            $body = trim($response->getContent(false));
        } catch (Throwable) {
            return '';
        }

        // Never let a server's error page leak the credential back out.
        return '' === $this->password ? $body : str_replace($this->password, '***', $body);
    }

    private function assertConfigured(): void
    {
        if ('' === $this->baseUrl()) {
            throw new RuntimeException('CALDAV_URL is not configured. Set it in .env.local to the base URL of your CalDAV server (e.g. https://dav.example.com).');
        }
    }

    private function url(CalendarInfo $calendar): string
    {
        return $this->absolute($calendar->href);
    }

    /**
     * `href`s come back server-absolute (`/lyra/work/`); store them that way
     * so a calendar's identity does not depend on how the deployment is
     * addressed.
     */
    private function normalizeHref(string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            $path = parse_url($href, \PHP_URL_PATH);

            $href = \is_string($path) && '' !== $path ? $path : '/';
        }

        $basePath = parse_url($this->baseUrl(), \PHP_URL_PATH);
        $basePath = \is_string($basePath) ? rtrim($basePath, '/') : '';

        if ('' !== $basePath && str_starts_with($href, $basePath.'/')) {
            $href = substr($href, \strlen($basePath));
        }

        return $href;
    }

    /**
     * Turn a server-supplied href into a request-ready absolute URL.
     *
     * A server may answer with an absolute path (`/lyra/`) or a full URL, so
     * both are accepted. Anything path-shaped is resolved against the
     * configured base, so the deployment's scheme, host and any base path
     * are honoured rather than assumed.
     */
    private function absolute(string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return $this->baseUrl().'/'.ltrim($href, '/');
    }

    private function namespaceCalDav(): string
    {
        return self::NAMESPACE_CALDAV;
    }
}
