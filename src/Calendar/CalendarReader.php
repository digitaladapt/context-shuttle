<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Calendar\CalDav\CalDavClient;
use App\Calendar\Domain\CalendarEvent;
use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\CalendarProblem;
use App\Calendar\Domain\ComponentType;
use App\Calendar\Mapping\EventMapper;
use App\Calendar\Paging\Cursor;
use App\Calendar\Paging\Page;
use App\Calendar\Paging\Paginator;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Assembles a listing: fetch, map, order, filter, page.
 *
 * Sits above the provider and owns the parts that must be identical for
 * every source — expansion, normalization and DTO shaping — so the timezone
 * and id rules are implemented once rather than drifting between sources at
 * exactly the places where drift is most dangerous (recurrence and DST).
 *
 * Nothing is cached: every call fetches.
 */
final readonly class CalendarReader
{
    public function __construct(
        private CalDavClient $client,
        private EventMapper $mapper,
        private Paginator $paginator = new Paginator(),
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * List event occurrences overlapping a date range.
     *
     * `$from` is inclusive and `$to` exclusive, both as instants in the
     * deployment's timezone — the inclusive-`to` translation from the
     * caller's `YYYY-MM-DD` happens in the tool layer, so this stays
     * instant-based.
     *
     * @param string|null $calendar restrict to one calendar by href or display name
     * @param string|null $search   case-insensitive substring over summary/description/location
     *
     * @return array{events: list<CalendarEvent>, page: Page, problems: list<CalendarProblem>, calendars: list<CalendarInfo>}
     */
    public function listEvents(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $calendar = null,
        ?string $search = null,
        ?Cursor $cursor = null,
        int $limit = 50,
    ): array {
        $calendars = $this->selectCalendars($calendar);
        $events = [];
        $problems = [];

        foreach ($calendars as $info) {
            foreach ($this->client->fetchByTimeRange($info, $from, $to, ComponentType::Event) as $object) {
                [$mapped, $objectProblems] = $this->mapper->map($object, $from, $to);

                foreach ($mapped as $event) {
                    $events[] = $event;
                }

                foreach ($objectProblems as $problem) {
                    $problems[] = $problem;
                }
            }
        }

        $events = $this->order($events);

        if (null !== $search && '' !== trim($search)) {
            $events = $this->filterBySearch($events, trim($search));
        }

        $page = $this->paginator->page($events, $limit, $cursor);

        $this->logProblems($problems);

        return [
            'events' => $page->events,
            'page' => $page,
            'problems' => $problems,
            'calendars' => $calendars,
        ];
    }

    /**
     * @return list<CalendarInfo>
     */
    public function selectCalendars(?string $filter): array
    {
        $calendars = $this->client->discoverCalendars();

        if (null === $filter || '' === trim($filter)) {
            return $calendars;
        }

        $wanted = trim($filter);
        $matched = [];

        foreach ($calendars as $info) {
            // `href` is the stable identifier; a display name is a
            // convenience, and on some servers it is not even a name.
            if ($info->href === $wanted || $info->name === $wanted) {
                $matched[] = $info;
            }
        }

        return $matched;
    }

    /**
     * Order by start, then id, so the order is stable across calls — a
     * non-deterministic order makes a model's narration of a day wrong, and
     * it is also what makes a cursor meaningful.
     *
     * @param list<CalendarEvent> $events
     *
     * @return list<CalendarEvent>
     */
    private function order(array $events): array
    {
        usort($events, static function (CalendarEvent $a, CalendarEvent $b): int {
            return [$a->orderKey(), $a->id] <=> [$b->orderKey(), $b->id];
        });

        return $events;
    }

    /**
     * @param list<CalendarEvent> $events
     *
     * @return list<CalendarEvent>
     */
    private function filterBySearch(array $events, string $needle): array
    {
        $needle = mb_strtolower($needle);

        return array_values(array_filter($events, static function (CalendarEvent $event) use ($needle): bool {
            foreach ([$event->summary, $event->description, $event->location] as $field) {
                if (null !== $field && str_contains(mb_strtolower($field), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Detail goes to the log, where an operator can find it; the tool result
     * carries only a count. The UID and calendar travel with each entry so a
     * line is actionable on its own.
     *
     * @param list<CalendarProblem> $problems
     */
    private function logProblems(array $problems): void
    {
        if (null === $this->logger || [] === $problems) {
            return;
        }

        foreach ($problems as $problem) {
            $this->logger->warning('Calendar component skipped.', $problem->toLogContext());
        }
    }
}
