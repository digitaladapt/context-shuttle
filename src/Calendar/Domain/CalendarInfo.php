<?php

declare(strict_types=1);

namespace App\Calendar\Domain;

/**
 * A calendar as discovered from the provider.
 *
 * `href` is the stable identifier (finding 10: `displayname` returned the
 * path on the reference server, so it is a label, never a key).
 * `readonly` comes from the calendar's privileges and is the single source
 * of truth for whether its events can be written — which is why it is
 * carried here and copied onto every event row this calendar produces.
 */
final readonly class CalendarInfo
{
    public function __construct(
        public string $href,
        public string $name,
        public bool $readonly,
    ) {
    }

    /**
     * The `calendar` object on each event row.
     *
     * @return array{name: string, href: string}
     */
    public function toReference(): array
    {
        return [
            'name' => $this->name,
            'href' => $this->href,
        ];
    }
}
