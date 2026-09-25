<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Calendar\CalDav\CalDavClient;
use App\Calendar\Domain\CalendarEvent;
use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\CalendarObject;
use App\Calendar\Domain\CalendarProblem;
use App\Calendar\Domain\CalendarTask;
use App\Calendar\Domain\ComponentType;
use App\Calendar\Domain\CompositeId;
use App\Calendar\Domain\TimeZoneRule;
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
        private TimeZoneRule $timeZone,
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
     * List tasks, open ones first, ordered by due date with undated last.
     *
     * No date range is required because "what's outstanding?" is the
     * dominant question, and a task with no deadline is a normal thing to
     * have — requiring a range would exclude exactly those rows. When a
     * caller *does* pass one, it filters on `due` client-side.
     *
     * The fetch is deliberately made **without** a server-side
     * `<time-range>`. Finding 12's correction: Radicale returns every task
     * when no range is given, but filters when one is — and passes undated
     * tasks through untouched. Since servers disagree about whether and how
     * to filter, the only way one code path serves all of them is to fetch
     * everything and filter here.
     *
     * @return array{tasks: list<CalendarTask>, page: Page, problems: list<CalendarProblem>, calendars: list<CalendarInfo>}
     */
    public function listTasks(
        bool $includeCompleted = false,
        ?string $calendar = null,
        ?string $search = null,
        ?DateTimeImmutable $dueFrom = null,
        ?DateTimeImmutable $dueTo = null,
        ?Cursor $cursor = null,
        int $limit = 50,
    ): array {
        $calendars = $this->selectCalendars($calendar);
        $tasks = [];
        $problems = [];

        foreach ($calendars as $info) {
            foreach ($this->client->fetchTasks($info) as $object) {
                [$mapped, $objectProblems] = $this->mapper->mapTask($object);

                foreach ($mapped as $task) {
                    $tasks[] = $task;
                }

                foreach ($objectProblems as $problem) {
                    $problems[] = $problem;
                }
            }
        }

        $tasks = $this->orderTasks($tasks);

        $tasks = array_values(array_filter($tasks, static function (CalendarTask $task) use ($includeCompleted): bool {
            return $includeCompleted || !$task->isCompleted();
        }));

        if (null !== $search && '' !== trim($search)) {
            $tasks = $this->filterTasksBySearch($tasks, trim($search));
        }

        if (null !== $dueFrom || null !== $dueTo) {
            $tasks = $this->filterTasksByDue($tasks, $dueFrom, $dueTo);
        }

        $page = $this->paginator->pageTasks($tasks, $limit, $cursor);

        $this->logProblems($problems);

        return [
            'tasks' => $page->tasks,
            'page' => $page,
            'problems' => $problems,
            'calendars' => $calendars,
        ];
    }

    /**
     * Fetch one task by id.
     *
     * A task is addressed by its UID alone: unlike an event, it has no
     * occurrence to disambiguate, so a composite id here would be noise.
     *
     * @return array{tasks: list<CalendarTask>, problems: list<CalendarProblem>}
     */
    public function getTask(string $id, ?string $calendar = null): array
    {
        $uid = CompositeId::parse($id)->uid;
        $tasks = [];
        $problems = [];

        foreach ($this->selectCalendars($calendar) as $info) {
            foreach ($this->client->fetchTasks($info, $uid) as $object) {
                [$mapped, $objectProblems] = $this->mapper->mapTask($object);

                foreach ($mapped as $task) {
                    if ($task->uid === $uid) {
                        $tasks[] = $task;
                    }
                }

                foreach ($objectProblems as $problem) {
                    $problems[] = $problem;
                }
            }
        }

        $this->logProblems($problems);

        return ['tasks' => $this->orderTasks($tasks), 'problems' => $problems];
    }

    /**
     * Order tasks by due date, then id — with undated tasks last.
     *
     * @param list<CalendarTask> $tasks
     *
     * @return list<CalendarTask>
     */
    private function orderTasks(array $tasks): array
    {
        usort($tasks, static function (CalendarTask $a, CalendarTask $b): int {
            return [$a->orderKey(), $a->uid] <=> [$b->orderKey(), $b->uid];
        });

        return $tasks;
    }

    /**
     * @param list<CalendarTask> $tasks
     *
     * @return list<CalendarTask>
     */
    private function filterTasksBySearch(array $tasks, string $needle): array
    {
        $needle = mb_strtolower($needle);

        return array_values(array_filter($tasks, static function (CalendarTask $task) use ($needle): bool {
            foreach ([$task->summary, $task->description] as $field) {
                if (null !== $field && str_contains(mb_strtolower($field), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Filter by due date, client-side.
     *
     * An undated task is excluded by a range, which is the only defensible
     * reading: it has no due date to fall inside one.
     *
     * @param list<CalendarTask> $tasks
     *
     * @return list<CalendarTask>
     */
    private function filterTasksByDue(array $tasks, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        return array_values(array_filter($tasks, static function (CalendarTask $task) use ($from, $to): bool {
            if (null === $task->due || !$task->hasDue()) {
                return false;
            }

            // Compare instants. A date-only due is read in the deployment's
            // timezone so it lands on the day a caller would name.
            $due = new DateTimeImmutable($task->due, $from?->getTimezone() ?? $to?->getTimezone());

            if (null !== $from && $due < $from) {
                return false;
            }

            return null === $to || $due <= $to;
        }));
    }

    /**
     * Fetch one event by id.
     *
     * Two shapes, and the difference is entirely about how much the caller
     * told us:
     *
     * - **A composite id** names one occurrence, so the instant in its tail
     *   bounds the query to a narrow window: a `calendar-query` over a day
     *   or two, which the server answers without walking the calendar. The
     *   design's finding 11 -- that `calendar-multiget` fetches by href --
     *   remains true, but it needs an href the caller never sees, so the
     *   narrow window is the cheaper route to the same object.
     * - **A plain UID** names a series without saying when. There is no
     *   window to narrow by, so the series is looked up by property and then
     *   expanded over a bounded range, because v1 always expands and a
     *   caller asking about `standup@test` is asking what it *is*.
     *
     * The returned occurrences keep the same shape a listing produces, so a
     * caller can switch between the two tools without re-learning the row.
     *
     * @param list<CalendarInfo>|null $calendars pre-discovered calendars, to avoid a second PROPFIND
     *
     * @return array{events: list<CalendarEvent>, problems: list<CalendarProblem>}
     */
    public function getEvent(
        string $id,
        ?string $calendar = null,
        ?DateTimeImmutable $seriesFrom = null,
        ?DateTimeImmutable $seriesTo = null,
        ?array $calendars = null,
    ): array {
        $composite = CompositeId::parse($id);
        $calendars ??= $this->selectCalendars($calendar);

        $events = [];
        $problems = [];

        foreach ($calendars as $info) {
            if ($composite->hasOccurrence) {
                // The id names an instant or a date, so the query can be
                // narrowed to that occurrence before anything is fetched.
                $objects = $this->client->fetchByTimeRange(
                    $info,
                    $this->occurrenceWindow($composite),
                    $this->occurrenceWindowEnd($composite),
                    ComponentType::Event,
                );
            } else {
                $objects = $this->client->fetchByUid($info, $composite->uid, ComponentType::Event);
            }

            foreach ($objects as $object) {
                [$from, $to] = $this->windowFor($object, $composite, $seriesFrom, $seriesTo);

                [$mapped, $objectProblems] = $this->mapper->map($object, $from, $to);

                foreach ($mapped as $event) {
                    if ($event->uid !== $composite->uid) {
                        continue;
                    }

                    // A composite id names one occurrence, so anything else
                    // the expansion surfaced is not an answer to this
                    // question. The window has to be wide enough for the
                    // server to find the object; the result is narrowed to
                    // the row the caller asked for.
                    if ($composite->hasOccurrence && $event->id !== $composite->toString()) {
                        continue;
                    }

                    $events[] = $event;
                }

                foreach ($objectProblems as $problem) {
                    $problems[] = $problem;
                }
            }
        }

        $this->logProblems($problems);

        return ['events' => $this->order($events), 'problems' => $problems];
    }

    /**
     * The expansion window for one fetched object.
     *
     * A named occurrence pins it. A plain UID does not, so the window is
     * anchored on **the series' own declared start** rather than on the
     * current date — otherwise a historical series expands to nothing, and a
     * caller asking "what is this?" gets an empty answer that looks like a
     * missing event. The clamp upstream caps the span at 366 days, so
     * anchoring on the series and running forward from it is the only
     * ordering that cannot silently truncate the whole window into the past.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function windowFor(
        CalendarObject $object,
        CompositeId $composite,
        ?DateTimeImmutable $seriesFrom,
        ?DateTimeImmutable $seriesTo,
    ): array {
        if ($composite->hasOccurrence) {
            return [$this->occurrenceWindow($composite), $this->occurrenceWindowEnd($composite)];
        }

        $anchor = $this->mapper->seriesStart($object)
            ?? new DateTimeImmutable('today', $this->timeZone->zone());

        $from = $seriesFrom ?? $anchor->modify('-1 day');
        $to = $seriesTo ?? $from->modify('+1 year');

        return [$from, $to];
    }

    /**
     * The window an occurrence id implies, opened out by a day either side so
     * an event that starts before the instant and runs into it is still
     * returned. The expansion pass trims by start, so the slack costs nothing
     * but a slightly wider server-side filter.
     */
    private function occurrenceWindow(CompositeId $composite): DateTimeImmutable
    {
        if (null !== $composite->instant) {
            return $composite->instant->modify('-1 day');
        }

        // An all-day occurrence is a date, so the window is that day in the
        // deployment's timezone — the same reading the listing would use.
        return $this->timeZone->startOfDay((string) $composite->date)->modify('-1 day');
    }

    private function occurrenceWindowEnd(CompositeId $composite): DateTimeImmutable
    {
        if (null !== $composite->instant) {
            return $composite->instant->modify('+1 day');
        }

        return $this->timeZone->endOfDayExclusive((string) $composite->date)->modify('+1 day');
    }

    /**
     * The expansion window for a lookup: the occurrence narrows it, a plain
     * UID falls back to the caller's bounds.
     */
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
