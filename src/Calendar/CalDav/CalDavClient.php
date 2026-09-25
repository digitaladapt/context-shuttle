<?php

declare(strict_types=1);

namespace App\Calendar\CalDav;

use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\CalendarObject;
use App\Calendar\Domain\ComponentType;
use App\Calendar\Domain\EditableCalendar;
use App\Calendar\Write\ConcurrentModification;
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
     *
     * Writes are deliberately **excluded** from this — see `put()`. A `PUT`
     * retried after a timeout can land twice, and the second landing is not
     * the same request as the first once someone else has edited the object
     * in between.
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
        private EditableCalendar $editableCalendar = new EditableCalendar(''),
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
     * Whether a base URL has been configured.
     *
     * Checked at call time, never at boot: a calendar server being down (or
     * unset) must not stop context-shuttle from starting.
     */
    public function isConfigured(): bool
    {
        return '' !== $this->baseUrl();
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

            $normalized = $this->normalizeHref($href);
            $serverPermits = $this->parser->isWritable($response);

            $calendars[] = new CalendarInfo(
                href: $normalized,
                name: $this->parser->displayName($response) ?? $href,
                // `readonly` means "these tools can edit this row", which is a
                // narrower thing than "the server would let us". Both have to
                // hold: the server permits writing **and** this is the one
                // calendar the operator designated. Reporting the server's
                // opinion alone would mark every writable calendar editable,
                // so a caller would be told an edit was available on the four
                // calendars these tools will refuse — in the field that exists
                // to answer exactly that question.
                readonly: !($serverPermits && $this->editableCalendar->matchesHref($normalized)),
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
        // Shared with the write boundary rather than reimplemented: the read
        // allowlist and the one writable calendar guard opposite ends of the
        // same exposure, and two matchers that "obviously" agree are two
        // matchers that eventually do not.
        return EditableCalendar::hrefMatches($href, $wanted);
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
     * Every VTODO in a calendar, optionally narrowed to one UID.
     *
     * **No `<time-range>` is sent, on purpose.** Re-testing finding 12
     * showed the original "filtering is identical either way" result held
     * only for tasks whose `DUE` fell inside the tested window: given tasks
     * that straddle it, Radicale returns all five without a range and three
     * with one, and a task with no `DUE` escapes the filter entirely.
     * Servers therefore disagree about whether, and by which property, they
     * filter — so the fetch asks for everything and the reader filters, which
     * is the only shape that behaves the same everywhere.
     *
     * @return list<CalendarObject>
     */
    public function fetchTasks(CalendarInfo $calendar, ?string $uid = null): array
    {
        $uidFilter = '';

        if (null !== $uid) {
            $uidFilter = \sprintf(
                '<c:prop-filter name="UID"><c:text-match collation="i;octet">%s</c:text-match></c:prop-filter>',
                htmlspecialchars($uid, \ENT_XML1),
            );
        }

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
                      <c:comp-filter name="%s">%s</c:comp-filter>
                    </c:comp-filter>
                  </c:filter>
                </c:calendar-query>
                XML,
            $this->namespaceCalDav(),
            ComponentType::Task->value,
            $uidFilter,
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
     * Create or replace one calendar object, conditionally.
     *
     * `$ifMatch` is the etag last seen for this object and makes the write
     * fail rather than overwrite if someone changed it in the meantime;
     * `$mustNotExist` asserts the object is new. Exactly one is supplied per
     * call, because a request that both requires an etag and requires absence
     * is a request that contradicts itself.
     *
     * **Never retried.** The read path retries once on a transport error
     * because a `PROPFIND` is harmless twice. A `PUT` is not: if the first
     * attempt reached the server and only the response was lost, a retry
     * overwrites a change that arrived in between — which is precisely the
     * corruption `If-Match` exists to prevent, committed by our own retry
     * logic rather than by a caller's mistake. A timed-out write therefore
     * fails and lets the caller re-read and decide.
     */
    public function put(CalendarInfo $calendar, string $href, string $ics, ?string $ifMatch = null, bool $mustNotExist = false): ?string
    {
        $this->assertHrefWithin($href, $calendar);

        $headers = [];
        $headers['Content-Type'] = 'text/calendar; charset=utf-8';

        if ($mustNotExist) {
            $headers['If-None-Match'] = '*';
        } elseif (null !== $ifMatch) {
            $headers['If-Match'] = $this->quoteEtag($ifMatch);
        }

        $response = $this->sendWrite('PUT', $this->absolute($href), $ics, $headers);

        $etag = $response->getHeaders(false)['etag'][0] ?? null;

        return null === $etag ? null : trim($etag);
    }

    /**
     * Remove one calendar object, conditionally.
     *
     * `$ifMatch` is required by the caller rather than optional here: a
     * delete with no precondition removes whatever is at the href now, which
     * is a different object than the one the caller decided to delete.
     */
    public function delete(CalendarInfo $calendar, string $href, ?string $ifMatch): void
    {
        $this->assertHrefWithin($href, $calendar);

        $headers = [];

        if (null !== $ifMatch) {
            $headers['If-Match'] = $this->quoteEtag($ifMatch);
        }

        $this->sendWrite('DELETE', $this->absolute($href), '', $headers);
    }

    /**
     * Present an etag the way `If-Match` requires it.
     *
     * Etags are stored **unquoted** throughout — `DavMultistatusParser::etag()`
     * strips the quotes, and a `PUT` response is stripped to match, so the two
     * agree on what an etag *is*. RFC 7232 wants the quotes on the header
     * though, and the reference server enforces it: the same etag sends
     * unquoted returns `412 Precondition Failed` and quoted succeeds, which is
     * a difference that reads exactly like someone else having edited the
     * object. Quoting here keeps the internal form clean and the wire form
     * correct, in one place rather than at every call site.
     */
    private function quoteEtag(string $etag): string
    {
        return '"'.trim($etag, '"').'"';
    }

    /**
     * Send one write, mapping its failure modes to their own exceptions.
     *
     * `412` becomes `ConcurrentModification` because it is the one failure
     * with a specific remedy — re-read, then decide — and flattening it into
     * a generic error would leave a caller that lost a race knowing only that
     * writing is unreliable. `412` can also mean a failing `If-None-Match`,
     * i.e. the object already exists, which is the same advice.
     */
    /**
     * @param array<string, string> $headers
     */
    private function sendWrite(string $method, string $url, string $body, array $headers): ResponseInterface
    {
        $this->assertConfigured();

        try {
            $response = $this->httpClient->request($method, $url, [
                'auth_basic' => [$this->username, $this->password],
                'headers' => $headers,
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(\sprintf('Could not reach the calendar server at %s to write: %s', $this->baseUrl(), $e->getMessage()), 0, $e);
        }

        if (401 === $status || 403 === $status) {
            // A misconfigured or under-privileged account, not bad data.
            throw new RuntimeException('The calendar server rejected the write. Check that CALDAV_USERNAME has write access to the configured calendar, and that CALDAV_PASSWORD (often an app password) is current.');
        }

        if (412 === $status) {
            $this->drain($response);

            throw new ConcurrentModification('This event changed since it was read, so the change was not applied. Read it again and retry if it still needs changing.');
        }

        if (409 === $status) {
            $this->drain($response);

            throw new RuntimeException('The calendar server refused the write as a conflict. If the calendar it names does not exist, check CALDAV_EDITABLE_CALENDAR.');
        }

        if ($status >= 400) {
            $detail = $this->safeBody($response);

            throw new RuntimeException(\sprintf('The calendar server returned HTTP %d for %s.%s', $status, $method, '' === $detail ? '' : ' '.$detail));
        }

        return $response;
    }

    /**
     * Refuse a write whose href would not land inside the target calendar.
     *
     * Measured: a percent-encoded `..` in a request path escapes the
     * collection, producing an object *outside* the calendar the operator
     * designated as the only writable one (CALENDAR-WRITES-FINDINGS §3). The
     * server does not stop this, so the check has to be here.
     *
     * This is a second line of defence. The first is that hrefs are generated
     * rather than derived from anything a caller supplies — a UID never
     * becomes a path segment — so this method should never fire in practice,
     * and the fact that it exists is not a reason to relax that rule.
     */
    private function assertHrefWithin(string $href, CalendarInfo $calendar): void
    {
        $path = parse_url($href, \PHP_URL_PATH);
        $path = \is_string($path) ? $path : $href;

        // Both forms: the raw text and its percent-decoded reading, because
        // `..%2F..%2F` only becomes `../../` after decoding, and the server
        // sees the decoded form.
        foreach ([$path, rawurldecode($path)] as $candidate) {
            foreach (explode('/', $candidate) as $segment) {
                if ('..' === $segment || '.' === $segment) {
                    throw new UnsafeHref('Refusing to write: the target path contains a relative segment, which could escape the configured calendar.');
                }
            }
        }

        $decodedPath = rawurldecode($path);
        $collection = rtrim(parse_url($this->absolute($calendar->href), \PHP_URL_PATH) ?: '', '/');

        if ('' === $collection) {
            return;
        }

        if (!str_starts_with(rawurldecode($decodedPath), $collection.'/')) {
            throw new UnsafeHref(\sprintf('Refusing to write: the target path is not inside the configured calendar (%s).', $collection.'/'));
        }
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
