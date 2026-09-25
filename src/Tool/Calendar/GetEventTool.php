<?php

declare(strict_types=1);

namespace App\Tool\Calendar;

use App\Calendar\CalendarReader;
use App\Calendar\Domain\CalendarEvent;
use App\Calendar\Domain\CalendarProblem;
use App\Calendar\Domain\TimeZoneRule;
use InvalidArgumentException;
use RuntimeException;

/**
 * `calendar_get_event` — one occurrence, or a whole series.
 *
 * The id is the handle for a specific occurrence: pass back what a listing
 * gave you and you get that occurrence. The same tool is also the address
 * for a series, so a caller holding only a UID from somewhere else can ask
 * what it is without first walking a listing to find it.
 */
final readonly class GetEventTool
{
    public function __construct(
        private CalendarReader $reader,
        private TimeZoneRule $timeZone,
    ) {
    }

    /**
     * Fetch one event by id.
     *
     * @param string $id a composite id from a listing, or a plain series UID
     *
     * @return array<string, mixed>
     */
    public function getEvent(string $id): array
    {
        $id = trim($id);

        if ('' === $id) {
            throw new InvalidArgumentException('id must not be empty. Pass back the id from a listing, or a series UID.');
        }

        $result = $this->reader->getEvent($id);

        $events = array_map(
            static fn (CalendarEvent $event): array => $event->toArray(),
            $result['events'],
        );

        if ([] === $events) {
            // Not found is a normal tool error naming the id, so a caller
            // learns *which* handle missed rather than inferring it from an
            // empty list that looks like a valid answer.
            throw new RuntimeException(\sprintf('No event found with id "%s". The id may be stale, or may belong to a calendar this deployment does not expose.', $id));
        }

        $payload = [
            'events' => $events,
            'count' => \count($events),
            'timezone' => $this->timeZone->name(),
        ];

        $errors = $this->describeProblems($result['problems']);

        if (null !== $errors) {
            $payload['errors'] = $errors;
        }

        return $payload;
    }

    /**
     * @param list<CalendarProblem> $problems
     */
    private function describeProblems(array $problems): ?string
    {
        $count = \count($problems);

        if (0 === $count) {
            return null;
        }

        return \sprintf('There were %d malformed events', $count);
    }
}
