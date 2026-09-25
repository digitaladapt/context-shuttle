<?php

declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\CalDav\CalDavClient;
use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\ComponentType;
use DateTimeImmutable;
use Override;

/**
 * The CalDAV source, adapting the DAV client to the provider contract.
 *
 * A thin adapter rather than a rewrite: `CalDavClient` already speaks the
 * three requests this needs, and the interface exists so the *other* source
 * can slot in beside it — not to abstract anything away from this one.
 */
final readonly class CalDavProvider implements CalendarProvider
{
    public function __construct(
        private CalDavClient $client,
    ) {
    }

    #[Override]
    public function name(): string
    {
        return 'caldav';
    }

    #[Override]
    public function isConfigured(): bool
    {
        // The client already fails with a message naming the env var when it
        // is not; this just lets the reader omit it entirely rather than
        // carrying a source that can only ever raise.
        return $this->client->isConfigured();
    }

    #[Override]
    public function listCalendars(): array
    {
        return $this->client->discoverCalendars();
    }

    #[Override]
    public function fetch(CalendarInfo $calendar, DateTimeImmutable $from, DateTimeImmutable $to, ComponentType $type): array
    {
        // Tasks are requested **without** a time range. Radicale returns every
        // task when no range is given and filters when one is — and passes
        // undated tasks through either way — so no server-side range can be
        // trusted to mean the same thing twice. The reader filters instead.
        if (ComponentType::Task === $type) {
            return $this->client->fetchTasks($calendar);
        }

        return $this->client->fetchByTimeRange($calendar, $from, $to, $type);
    }

    #[Override]
    public function fetchByUid(CalendarInfo $calendar, string $uid, ComponentType $type): array
    {
        if (ComponentType::Task === $type) {
            return $this->client->fetchTasks($calendar, $uid);
        }

        return $this->client->fetchByUid($calendar, $uid, ComponentType::Event);
    }
}
