<?php

declare(strict_types=1);

namespace App\Calendar\Domain;

/**
 * A raw, unexpanded calendar object as fetched from a provider.
 *
 * `data` is the verbatim iCalendar payload. It is kept unparsed so that a
 * component which will not parse can be isolated to this one object
 * (finding 5) without failing the listing it travelled in.
 */
final readonly class CalendarObject
{
    public function __construct(
        public string $href,
        public string $data,
        public ?string $etag,
        public CalendarInfo $calendar,
    ) {
    }
}
