<?php

declare(strict_types=1);

namespace App\Xml;

use RuntimeException;
use Sabre\Xml\Deserializer;
use Sabre\Xml\Reader;
use Sabre\Xml\Service;

/**
 * Parses a DAV `multistatus` response into a plain structure.
 *
 * `sabre/xml` rather than `SimpleXML` or regex: the entire job is namespace
 * handling (`DAV:`, `urn:ietf:params:xml:ns:caldav`), and the envelope nests
 * the same way for discovery and for both `REPORT`s.
 *
 * The obvious deserializer, `keyValue`, is **wrong here**: it documents that
 * "if elements with the same name appear twice in the list, only the last one
 * will be kept", and repeated `<response>` elements are exactly how a
 * multistatus carries its payload. This parser walks children explicitly so
 * every response survives.
 *
 * Every accessor tolerates absence, because servers differ wildly in which
 * properties they populate and in which `propstat` they put them.
 */
final class DavMultistatusParser
{
    private readonly Service $service;

    public function __construct()
    {
        $this->service = new Service();
        $this->service->elementMap = [
            '{DAV:}multistatus' => static function (Reader $reader): array {
                $responses = [];

                foreach ($reader->parseGetElements() as $element) {
                    if ('{DAV:}response' === $element['name']) {
                        $responses[] = $element['value'];
                    }
                }

                return $responses;
            },
            '{DAV:}response' => static function (Reader $reader): array {
                $href = null;
                $propstats = [];

                foreach ($reader->parseGetElements() as $element) {
                    if ('{DAV:}href' === $element['name']) {
                        $href = $element['value'];
                    } elseif ('{DAV:}propstat' === $element['name']) {
                        $propstats[] = $element['value'];
                    }
                }

                return ['href' => $href, 'propstats' => $propstats];
            },
            '{DAV:}propstat' => static function (Reader $reader): array {
                $prop = [];
                $status = null;

                foreach ($reader->parseGetElements() as $element) {
                    if ('{DAV:}prop' === $element['name']) {
                        $prop = $element['value'];
                    } elseif ('{DAV:}status' === $element['name']) {
                        $status = trim((string) $element['value']);
                    }
                }

                return ['prop' => $prop, 'status' => $status];
            },
            '{DAV:}prop' => static function (Reader $reader): array {
                $properties = [];

                foreach ($reader->parseGetElements() as $element) {
                    $properties[$element['name']] = $element['value'];
                }

                return $properties;
            },
            '{DAV:}resourcetype' => static function (Reader $reader): array {
                $types = [];

                foreach ($reader->parseGetElements() as $element) {
                    $types[] = $element['name'];
                }

                return $types;
            },
            '{DAV:}current-user-privilege-set' => static function (Reader $reader): array {
                $privileges = [];

                foreach ($reader->parseGetElements() as $element) {
                    if ('{DAV:}privilege' === $element['name'] && \is_array($element['value'])) {
                        foreach ($element['value'] as $privilege) {
                            $privileges[] = $privilege;
                        }
                    }
                }

                return $privileges;
            },
            '{DAV:}privilege' => static function (Reader $reader): array {
                $names = [];

                foreach ($reader->parseGetElements() as $element) {
                    $names[] = $element['name'];
                }

                return $names;
            },
        ];
    }

    /**
     * @return list<array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>}>
     */
    public function parse(string $xml): array
    {
        $parsed = $this->service->parse($xml);

        // The element map above guarantees this shape for a well-formed
        // multistatus; a document that is not one is a server problem worth
        // failing loudly on rather than silently treating as empty.
        if (!\is_array($parsed)) {
            throw new RuntimeException('The calendar server sent a response that is not a DAV multistatus document.');
        }

        $responses = [];

        foreach ($parsed as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $responses[] = [
                'href' => \is_string($entry['href'] ?? null) ? $entry['href'] : null,
                'propstats' => $this->normalizePropstats($entry['propstats'] ?? null),
            ];
        }

        return $responses;
    }

    /**
     * @return list<array{prop: array<string, mixed>, status: string|null}>
     */
    private function normalizePropstats(mixed $propstats): array
    {
        if (!\is_array($propstats)) {
            return [];
        }

        $normalized = [];

        foreach ($propstats as $propstat) {
            if (!\is_array($propstat)) {
                continue;
            }

            $prop = $propstat['prop'] ?? null;
            $status = $propstat['status'] ?? null;

            $normalized[] = [
                'prop' => \is_array($prop) ? $prop : [],
                'status' => \is_string($status) ? $status : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>} $response
     */
    public function href(array $response): ?string
    {
        $href = $response['href'];

        return null === $href || '' === $href ? null : $href;
    }

    /**
     * @param array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>} $response
     */
    public function displayName(array $response): ?string
    {
        $value = $this->property($response, '{DAV:}displayname');

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * @param array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>} $response
     */
    public function etag(array $response): ?string
    {
        $value = $this->property($response, '{DAV:}getetag');

        return \is_string($value) && '' !== $value ? trim($value, '"') : null;
    }

    /**
     * @param array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>} $response
     */
    public function calendarData(array $response): ?string
    {
        $value = $this->property($response, '{urn:ietf:params:xml:ns:caldav}calendar-data');

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Whether a property is present at all, whatever its value.
     *
     * Note the distinction from `isCalendar()`: this asks about a property
     * *named* `$clarkName` on the resource. A calendar is not a property —
     * it is a child element of `resourcetype` — so asking for
     * `{…caldav}calendar` here always answers "no", which is a quiet way to
     * discover zero calendars.
     *
     * @param array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>} $response
     */
    public function hasProperty(array $response, string $clarkName): bool
    {
        foreach ($response['propstats'] as $propstat) {
            if (\array_key_exists($clarkName, $propstat['prop'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the resource is a calendar.
     *
     * Discovery must be property-driven rather than name-driven: on the
     * reference server `displayname` returned the *path* (`lyra/work`), so a
     * name is a label, never a key. The reliable signal is
     * `resourcetype` containing the CalDAV `calendar` element.
     *
     * @param array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>} $response
     */
    public function isCalendar(array $response): bool
    {
        $types = $this->property($response, '{DAV:}resourcetype');

        if (!\is_array($types)) {
            return false;
        }

        return \in_array('{urn:ietf:params:xml:ns:caldav}calendar', $types, true);
    }

    /**
     * Whether the calendar grants write access.
     *
     * A calendar advertises read-only by carrying `<C:read-only/>` or by
     * omitting `write`/`write-content`. An unprivileged response cannot
     * honestly be called writable, so absence means read-only.
     *
     * @param array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>} $response
     */
    public function isWritable(array $response): bool
    {
        $privileges = $this->property($response, '{DAV:}current-user-privilege-set');

        if (!\is_array($privileges)) {
            return false;
        }

        foreach ($privileges as $privilege) {
            if (!\is_string($privilege)) {
                continue;
            }

            if ('{DAV:}read-only' === $privilege) {
                return false;
            }
        }

        foreach ($privileges as $privilege) {
            if ('{DAV:}write-content' === $privilege || '{DAV:}write' === $privilege || '{DAV:}all' === $privilege) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the `propstat` carrying the data says 200.
     *
     * @param array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>} $response
     */
    public function isSuccess(array $response): bool
    {
        foreach ($response['propstats'] as $propstat) {
            $status = $propstat['status'];

            if (null === $status) {
                continue;
            }

            if (str_contains($status, '200') && \array_key_exists('{urn:ietf:params:xml:ns:caldav}calendar-data', $propstat['prop'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `<d:href>` nested inside a property, such as
     * `current-user-principal` or `calendar-home-set`.
     *
     * These properties do not carry text; they wrap an href element, and the
     * nested element is what the server actually wants to tell us.
     *
     * @param array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>} $response
     */
    public function nestedHref(array $response, string $clarkName): ?string
    {
        $value = $this->property($response, $clarkName);

        if (\is_string($value) && '' !== $value) {
            return $value;
        }

        if (!\is_array($value)) {
            return null;
        }

        // The element map represents children as a list of
        // {name, value} entries; the href is the one we want.
        foreach ($value as $child) {
            if (\is_string($child) && '' !== $child) {
                return $child;
            }

            if (\is_array($child) && isset($child['name'], $child['value']) && '{DAV:}href' === $child['name'] && \is_string($child['value'])) {
                return $child['value'];
            }
        }

        return null;
    }

    /**
     * The first value found for a property, across every `propstat`.
     *
     * Servers split properties across success and failure `propstat`s, so
     * looking only in the first one misses data that is present.
     *
     * @param array{href: string|null, propstats: list<array{prop: array<string, mixed>, status: string|null}>} $response
     */
    private function property(array $response, string $clarkName): mixed
    {
        foreach ($response['propstats'] as $propstat) {
            if (\array_key_exists($clarkName, $propstat['prop'])) {
                return $propstat['prop'][$clarkName];
            }
        }

        return null;
    }
}
