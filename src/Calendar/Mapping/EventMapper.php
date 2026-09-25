<?php

declare(strict_types=1);

namespace App\Calendar\Mapping;

use App\Calendar\Domain\CalendarEvent;
use App\Calendar\Domain\CalendarObject;
use App\Calendar\Domain\CalendarProblem;
use App\Calendar\Domain\CalendarTask;
use App\Calendar\Domain\CompositeId;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Ics\FixedOffsetTzidRewriter;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Component\VTimeZone;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Turns raw iCalendar payloads into normalized occurrence rows.
 *
 * This is where the timezone rule is actually implemented, and it is the
 * only place that knows how many ways a timestamp can be expressed: `Z`, a
 * named `TZID` with or without a `VTIMEZONE`, a fixed-offset pseudo-`TZID`,
 * or a floating local time. All of them come out the same way — an instant
 * rendered in `TZ`, or a bare date for an all-day event.
 *
 * Two hard-won behaviours are encoded here:
 *
 * - **Expansion is always client-side** (finding 2). Server-side `expand`
 *   applied the interval to the first occurrence's UTC instant and never
 *   re-resolved the offset, so a DST-crossing series came back an hour out.
 * - **One bad component never fails a listing** (finding 5). A payload or
 *   occurrence that cannot be normalized is skipped and reported as a
 *   `CalendarProblem`, never thrown at the caller.
 *
 * Access to sabre's components goes through `select()` with an `instanceof`
 * narrowing rather than the magic `__get` properties: sabre's own
 * `@property` annotations name classes that do not resolve, so the magic
 * route cannot be statically checked at all. `select()` also expresses
 * "absent" honestly, which is what the optional properties need.
 */
final readonly class EventMapper
{
    /**
     * The date-time properties whose values decide when an event happens.
     * These are the ones worth checking for a trustworthy zone.
     */
    private const TIME_PROPERTIES = ['DTSTART', 'DTEND', 'RECURRENCE-ID', 'DUE'];

    /**
     * A bounded expansion window, so a pathological `RRULE` cannot be used
     * to burn the process.
     */
    public const MAX_EXPANSION_DAYS = 366;

    public function __construct(
        private TimeZoneRule $timeZone,
        private FixedOffsetTzidRewriter $rewriter = new FixedOffsetTzidRewriter(),
    ) {
    }

    /**
     * Map one calendar object's VTODOs to task rows.
     *
     * Tasks do **not** go through `calendar->expand()`. A VTODO is not a
     * recurring meeting: `RRULE` on a task is rare, and expanding one would
     * turn a single checklist item into a row per occurrence, which is not
     * what "what's outstanding?" is asking. The VTODO components are mapped
     * as they stand, in declared order.
     *
     * @return array{0: list<CalendarTask>, 1: list<CalendarProblem>}
     */
    public function mapTask(CalendarObject $object): array
    {
        try {
            $calendar = Reader::read($this->rewriter->rewrite($object->data), Reader::OPTION_FORGIVING);
        } catch (Throwable $e) {
            return [[], [new CalendarProblem(
                reason: 'unparseable payload: '.$e->getMessage(),
                calendarHref: $object->calendar->href,
                href: $object->href,
            )]];
        }

        if (!$calendar instanceof VCalendar) {
            return [[], [new CalendarProblem(
                reason: 'payload is not a calendar',
                calendarHref: $object->calendar->href,
                href: $object->href,
            )]];
        }

        // Collected once, so resolving a TZID never re-parses the document.
        $declaredTimezones = $this->declaredTimezones($calendar);

        // The same check the event path makes before mapping anything. A
        // task's `DUE` can carry an unresolvable TZID exactly as an event's
        // `DTSTART` can, and sabre resolves it to UTC with no complaint — a
        // fabricated instant presented as fact. Events had this guard from
        // the start; tasks were missing it until a fixture showed one
        // returning a confident time in the wrong zone.
        $problems = [];
        $unreliableUids = $this->unreliableUids($calendar, $declaredTimezones, $object, $problems);

        // Captured before sabre's coercion, so a non-numeric integer is
        // reported as absent rather than as a confident zero.
        $rawIntegers = $this->rawPropertyValues($object->data, ['PERCENT-COMPLETE', 'PRIORITY']);

        $tasks = [];

        foreach ($calendar->select('VTODO') as $component) {
            if (!$component instanceof VTodo) {
                continue;
            }

            if (isset($unreliableUids[$this->uidOf($component) ?? ''])) {
                continue;
            }

            try {
                $tasks[] = $this->mapOneTask($component, $object, $declaredTimezones, $rawIntegers);
            } catch (Throwable $e) {
                $problems[] = new CalendarProblem(
                    reason: 'task could not be normalized: '.$e->getMessage(),
                    uid: $this->uidOf($component),
                    calendarHref: $object->calendar->href,
                    href: $object->href,
                );
            }
        }

        return [$tasks, $problems];
    }

    /**
     * @param VTodo&iterable<mixed, mixed> $component
     * @param list<string>                 $declaredTimezones
     * @param array<string, string>        $rawIntegers
     */
    private function mapOneTask(VTodo $component, CalendarObject $object, array $declaredTimezones, array $rawIntegers = []): CalendarTask
    {
        $uid = $this->uidOf($component) ?? '';
        $due = $this->dateTimeProperty($component, 'DUE');

        $dueValue = null;
        $dueIsDate = false;
        $orderKey = '';
        $hasDue = false;

        if (null !== $due) {
            if ($due->hasTime()) {
                $instant = $this->instantOf($due, $component, $declaredTimezones);
                $dueValue = $this->timeZone->render($instant);
                // The order key is UTC, so ordering and the page cursor
                // cannot disagree with each other or with a TZ change.
                $orderKey = $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            } else {
                // A date-only due date is never converted through a zone.
                $dueValue = $this->formatDate((string) $due->getValue());
                $dueIsDate = true;
                $orderKey = $dueValue;
            }

            $hasDue = true;
        }

        // A task without a deadline still needs a position in the order, and
        // "after everything dated" is the only honest one. The key is a high
        // sentinel rather than an empty string, so undated tasks group
        // together at the end instead of sorting among the dated ones.
        if (!$hasDue) {
            $orderKey = '~';
        }

        return new CalendarTask(
            id: $uid,
            uid: $uid,
            summary: $this->text($component, 'SUMMARY') ?? '',
            description: $this->text($component, 'DESCRIPTION'),
            due: $dueValue,
            dueIsDate: $dueIsDate,
            status: $this->text($component, 'STATUS'),
            percentComplete: $this->intProperty($component, 'PERCENT-COMPLETE', $rawIntegers),
            priority: $this->intProperty($component, 'PRIORITY', $rawIntegers),
            completedAt: $this->completedAt($component, $declaredTimezones),
            categories: $this->categories($component),
            readonly: $object->calendar->readonly,
            calendar: $object->calendar,
            orderKey: $orderKey,
            hasDue: $hasDue,
        );
    }

    /**
     * `COMPLETED` is an instant (or a date), normalized like any other.
     *
     * @param VTodo&iterable<mixed, mixed> $component
     * @param list<string>                 $declaredTimezones
     */
    private function completedAt(VTodo $component, array $declaredTimezones): ?string
    {
        $property = $this->dateTimeProperty($component, 'COMPLETED');

        if (null === $property) {
            return null;
        }

        if (!$property->hasTime()) {
            return $this->formatDate((string) $property->getValue());
        }

        return $this->timeZone->render($this->instantOf($property, $component, $declaredTimezones));
    }

    /**
     * The payload's own text for the named properties, keyed by name.
     *
     * Needed because `sabre/vobject` **coerces** integer-typed properties: a
     * non-numeric `PERCENT-COMPLETE` becomes `0` while parsing, and
     * `getRawMimeDirValue()` merely re-serializes that coerced value — so the
     * original spelling is unrecoverable from the object graph. It has to be
     * scanned out of the raw payload. The distinction matters: reporting 0%
     * complete for a payload that never said so is a fabricated answer, and
     * indistinguishable from a genuine zero once parsed.
     *
     * A cheap line scan rather than a second parse, and only for the
     * properties asked for.
     *
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    private function rawPropertyValues(string $ics, array $names): array
    {
        $wanted = array_flip(array_map('strtoupper', $names));
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $ics) ?: [] as $line) {
            // The value starts after the first colon that is not inside the
            // parameter list; these properties carry no quoted parameters.
            $separator = strpos($line, ':');

            if (false === $separator) {
                continue;
            }

            // `PERCENT-COMPLETE;X=Y:20` -> `PERCENT-COMPLETE`.
            $name = strtoupper(explode(';', substr($line, 0, $separator), 2)[0]);

            if (isset($wanted[$name]) && !isset($values[$name])) {
                $values[$name] = substr($line, $separator + 1);
            }
        }

        return $values;
    }

    /**
     * Every component of the named types.
     *
     * `Component::select()` takes **one** name — it is not variadic, so a
     * second argument is silently ignored rather than rejected. Calling it
     * once per name keeps that trap out of the call sites: the first version
     * of this code passed two names and quietly checked events only, which is
     * how a task with an unresolvable `TZID` slipped past a guard written to
     * catch exactly that.
     *
     * @param VCalendar&iterable<mixed, mixed> $calendar
     *
     * @return list<Component>
     */
    private function selectAll(VCalendar $calendar, string ...$names): array
    {
        $components = [];

        foreach ($names as $name) {
            foreach ($calendar->select($name) as $component) {
                if ($component instanceof Component) {
                    $components[] = $component;
                }
            }
        }

        return $components;
    }

    /**
     * An integer property, or null when absent or unparseable.
     *
     * A non-numeric value is treated as absent rather than as zero: a task
     * silently reported at 0% complete, or at priority 0 ("undefined" in RFC
     * 5545), would be a fabricated answer.
     *
     * @param VTodo&iterable<mixed, mixed> $component
     * @param array<string, string>        $rawValues the payload's own text, taken before parsing
     */
    private function intProperty(VTodo $component, string $name, array $rawValues = []): ?int
    {
        $properties = $component->select($name);
        $property = $properties[0] ?? null;

        if (!$property instanceof Property) {
            return null;
        }

        // Prefer the payload's own text. `getValue()` cannot be trusted here:
        // sabre types this as an `IntegerValue` and coerces anything
        // unparseable to `0`, and `getRawMimeDirValue()` merely re-serializes
        // that coerced value — so "lots" and a genuine "0" are
        // indistinguishable once parsed. Reporting 0% complete for a payload
        // that never said so is exactly the fabricated answer this method
        // exists to avoid, so the pre-parse text wins when we have it.
        $raw = trim($rawValues[strtoupper($name)] ?? $property->getRawMimeDirValue());

        return 1 === preg_match('/^-?\d+$/', $raw) ? (int) $raw : null;
    }

    /**
     * The earliest start any component in this payload declares, unexpanded.
     *
     * Used to anchor an expansion window on the **series itself** rather than
     * on the current date: a caller looking a series up by UID is saying
     * nothing about when it happens, and anchoring on "now" would report an
     * empty series for anything historical — including a one-off event that
     * already happened.
     *
     * Returns null when the payload cannot be read, leaving the caller to
     * fall back to its own bounds.
     */
    public function seriesStart(CalendarObject $object): ?DateTimeImmutable
    {
        try {
            $calendar = Reader::read($this->rewriter->rewrite($object->data), Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            return null;
        }

        if (!$calendar instanceof VCalendar) {
            return null;
        }

        $starts = [];

        foreach ($this->selectAll($calendar, 'VEVENT', 'VTODO') as $component) {
            if (!$component instanceof VEvent && !$component instanceof VTodo) {
                continue;
            }

            $start = $this->dateTimeProperty($component, 'DTSTART');

            if (null === $start) {
                continue;
            }

            // An all-day series anchors on its date read in the deployment's
            // timezone, so the window lines up with how it will be expanded.
            $instants = $start->getDateTimes($this->timeZone->zone());
            $first = $instants[0] ?? null;

            if (null !== $first) {
                $starts[] = DateTimeImmutable::createFromInterface($first);
            }
        }

        if ([] === $starts) {
            return null;
        }

        usort($starts, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $starts[0];
    }

    /**
     * Map one calendar object to the occurrences that fall inside the window.
     *
     * `$from` is inclusive and `$to` exclusive, matching the expansion
     * semantics of the underlying library.
     *
     * @return array{0: list<CalendarEvent>, 1: list<CalendarProblem>}
     */
    public function map(CalendarObject $object, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$from, $to] = $this->clampWindow($from, $to);

        try {
            $calendar = Reader::read($this->rewriter->rewrite($object->data), Reader::OPTION_FORGIVING);
        } catch (Throwable $e) {
            return [[], [new CalendarProblem(
                reason: 'unparseable payload: '.$e->getMessage(),
                calendarHref: $object->calendar->href,
                href: $object->href,
            )]];
        }

        if (!$calendar instanceof VCalendar) {
            return [[], [new CalendarProblem(
                reason: 'payload is not a calendar',
                calendarHref: $object->calendar->href,
                href: $object->href,
            )]];
        }

        // The `VTIMEZONE` names this payload defines are collected once, so
        // resolving a TZID never re-parses the document per property.
        $declaredTimezones = $this->declaredTimezones($calendar);

        // TZID trust has to be decided **before** expanding. `expand()`
        // rewrites every date-time into a plain UTC value and drops the TZID
        // parameter, so an unresolvable zone is resolved — silently, and to
        // the wrong instant (finding 6) — before any later check could see
        // it. By the time an occurrence comes back out, the evidence that it
        // was constructed from a dubious zone is gone.
        $problems = [];
        $unreliableUids = $this->unreliableUids($calendar, $declaredTimezones, $object, $problems);

        try {
            // The third argument matters: `expand()` strips each date-time
            // down to a UTC value, and for a **floating** time (no zone at
            // all) it resolves the wall clock in the zone given here — which
            // would otherwise be UTC rather than the deployment's. Passing
            // `TZ` keeps a floating 09:00 meaning 09:00 where the calendar is
            // being read, while TZID-bearing properties still honour their own
            // zone, because sabre prefers the parameter when one is present.
            $expanded = $calendar->expand($from, $to, $this->timeZone->zone());
        } catch (Throwable $e) {
            return [[], [new CalendarProblem(
                reason: 'recurrence expansion failed: '.$e->getMessage(),
                uid: $this->firstUid($calendar),
                calendarHref: $object->calendar->href,
                href: $object->href,
            )]];
        }

        $events = [];

        // select() rather than ->VEVENT: the magic property is null, not an
        // empty list, when nothing falls inside the window.
        foreach ($expanded->select('VEVENT') as $component) {
            if (!$component instanceof VEvent) {
                continue;
            }

            // An event built from a zone we cannot resolve is not returned:
            // its instant would be a fabrication presented as fact. It is
            // already counted in `$problems`.
            if (isset($unreliableUids[$this->uidOf($component) ?? ''])) {
                continue;
            }

            try {
                $events[] = $this->mapOccurrence($component, $object, $declaredTimezones);
            } catch (Throwable $e) {
                $problems[] = new CalendarProblem(
                    reason: 'occurrence could not be normalized: '.$e->getMessage(),
                    uid: $this->uidOf($component),
                    calendarHref: $object->calendar->href,
                    href: $object->href,
                );
            }
        }

        return [$events, $problems];
    }

    /**
     * UIDs whose timestamps cannot be trusted, keyed for fast lookup.
     *
     * A `TZID` is acceptable when the payload declares it in a `VTIMEZONE`
     * (finding 4 — the stored definition is authoritative) or when PHP knows
     * the name. Anything else is a zone that resolves nowhere, and sabre
     * would quietly treat it as UTC (finding 6).
     *
     * @param VCalendar&iterable<mixed, mixed> $calendar
     * @param list<string>                     $declaredTimezones
     * @param list<CalendarProblem>            $problems
     *
     * @return array<string, true>
     */
    private function unreliableUids(VCalendar $calendar, array $declaredTimezones, CalendarObject $object, array &$problems): array
    {
        $unreliable = [];

        foreach ($this->selectAll($calendar, 'VEVENT', 'VTODO') as $component) {
            if (!$component instanceof VEvent && !$component instanceof VTodo) {
                continue;
            }

            foreach (self::TIME_PROPERTIES as $name) {
                $property = $this->dateTimeProperty($component, $name);

                if (null === $property) {
                    continue;
                }

                $tzid = $this->tzidOf($property);

                if (null === $tzid || $this->isResolvable($tzid, $declaredTimezones)) {
                    continue;
                }

                $uid = $this->uidOf($component) ?? '';
                $unreliable[$uid] = true;
                $problems[] = new CalendarProblem(
                    reason: \sprintf('%s uses TZID "%s", which is not resolvable and carries no recoverable offset', $name, $tzid),
                    uid: '' === $uid ? null : $uid,
                    calendarHref: $object->calendar->href,
                    href: $object->href,
                );

                // One report per component is enough; the whole event is out.
                break;
            }
        }

        return $unreliable;
    }

    /**
     * Map a single, already-expanded occurrence.
     *
     * @param VEvent&iterable<mixed, mixed> $component
     * @param list<string>                  $declaredTimezones
     */
    private function mapOccurrence(VEvent $component, CalendarObject $object, array $declaredTimezones): CalendarEvent
    {
        $uid = $this->uidOf($component) ?? '';
        $start = $this->dateTimeProperty($component, 'DTSTART');
        $end = $this->dateTimeProperty($component, 'DTEND');

        if (null === $start) {
            throw new RuntimeException('VEVENT has no DTSTART');
        }

        // An all-day event is a bare DATE: emitted date-only and never
        // converted through a timezone, because that is exactly what shifts
        // the day (findings 9, 14).
        if (!$start->hasTime()) {
            $startDate = $this->formatDate((string) $start->getValue());

            return new CalendarEvent(
                id: CompositeId::forOccurrence($uid, date: $startDate)->toString(),
                uid: $uid,
                recurrenceId: $this->recurrenceId($component, allDay: true),
                summary: $this->text($component, 'SUMMARY') ?? '',
                description: $this->text($component, 'DESCRIPTION'),
                start: $startDate,
                end: null !== $end ? $this->formatDate((string) $end->getValue()) : null,
                allDay: true,
                // DTEND on an all-day event is exclusive per RFC 5545, so say
                // so rather than leaving the caller to get it wrong by a day.
                endExclusive: null !== $end,
                location: $this->text($component, 'LOCATION'),
                categories: $this->categories($component),
                status: $this->text($component, 'STATUS'),
                readonly: $object->calendar->readonly,
                calendar: $object->calendar,
                orderKey: $startDate,
            );
        }

        $startInstant = $this->instantOf($start, $component, $declaredTimezones);
        $endInstant = null !== $end ? $this->instantOf($end, $component, $declaredTimezones) : null;

        // The id names a point in time in UTC, so it does not move when the
        // deployment changes TZ; `start` is that same instant rendered in TZ.
        // The two are deliberately different strings for one occurrence.
        $composite = CompositeId::forOccurrence($uid, instant: $startInstant);

        return new CalendarEvent(
            id: $composite->toString(),
            uid: $uid,
            recurrenceId: $this->recurrenceId($component, allDay: false),
            summary: $this->text($component, 'SUMMARY') ?? '',
            description: $this->text($component, 'DESCRIPTION'),
            start: $this->timeZone->render($startInstant),
            end: null !== $endInstant ? $this->timeZone->render($endInstant) : null,
            allDay: false,
            endExclusive: false,
            location: $this->text($component, 'LOCATION'),
            categories: $this->categories($component),
            status: $this->text($component, 'STATUS'),
            readonly: $object->calendar->readonly,
            calendar: $object->calendar,
            // The same UTC instant the id names, so ordering and the page
            // cursor can never disagree with it.
            orderKey: $composite->sortKey(),
        );
    }

    /**
     * Resolve one date-time property to an instant.
     *
     * This is the "craziness" the design absorbs: the raw value, its `TZID`
     * parameter and the payload's `VTIMEZONE` together decide the moment,
     * and the stored definition is authoritative.
     *
     * A `TZID` that resolves nowhere never reaches this point: it is caught
     * before expansion, because `expand()` would already have replaced it
     * with a fabricated UTC instant (finding 6).
     *
     * @param DateTimeProperty&iterable<mixed, mixed>                        $property
     * @param (VEvent&iterable<mixed, mixed>)|(VTodo&iterable<mixed, mixed>) $component
     * @param list<string>                                                   $declaredTimezones
     */
    private function instantOf(DateTimeProperty $property, VEvent|VTodo $component, array $declaredTimezones): DateTimeImmutable
    {
        $tzid = $this->tzidOf($property);

        // A floating time has no zone at all: it is interpreted in the only
        // timezone this system has a vocabulary for.
        $zone = null === $tzid
            ? $this->timeZone->zone()
            : $this->zoneFor($tzid);

        $instants = $property->getDateTimes($zone);
        $first = $instants[0] ?? null;

        if (null === $first) {
            throw new RuntimeException('date-time property yielded no instants');
        }

        return DateTimeImmutable::createFromInterface($first);
    }

    /**
     * The `TZID` parameter of a property, if present and non-empty.
     *
     * @param DateTimeProperty&iterable<mixed, mixed> $property
     */
    private function tzidOf(DateTimeProperty $property): ?string
    {
        if (!isset($property['TZID'])) {
            return null;
        }

        $parameter = $property['TZID'];
        $tzid = trim($parameter instanceof Parameter ? (string) $parameter->getValue() : '', '"');

        return '' === $tzid ? null : $tzid;
    }

    /**
     * Whether a `TZID` can be trusted.
     *
     * A payload that ships its own `VTIMEZONE` definition is authoritative
     * for that TZID (finding 4), so it is accepted even when PHP has never
     * heard of the name. Otherwise the name must resolve in the local
     * database.
     *
     * @param list<string> $declaredTimezones
     */
    private function isResolvable(string $tzid, array $declaredTimezones): bool
    {
        if (\in_array($tzid, $declaredTimezones, true)) {
            return true;
        }

        try {
            new DateTimeZone($tzid);
        } catch (Exception) {
            return false;
        }

        return true;
    }

    /**
     * A zone object for a `TZID` that has already passed `isResolvable()`.
     *
     * A `TZID` backed only by the payload's own `VTIMEZONE` may not be a name
     * PHP knows. Its instants are already expressed by that definition, so
     * UTC is the honest fallback — the value is not invented, only unnamed.
     */
    private function zoneFor(string $tzid): DateTimeZone
    {
        try {
            return new DateTimeZone($tzid);
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * The `TZID` names this payload declares in a `VTIMEZONE`.
     *
     * @param VCalendar&iterable<mixed, mixed> $calendar
     *
     * @return list<string>
     */
    private function declaredTimezones(VCalendar $calendar): array
    {
        $names = [];

        foreach ($calendar->select('VTIMEZONE') as $timezone) {
            if (!$timezone instanceof VTimeZone) {
                continue;
            }

            $properties = $timezone->select('TZID');
            $property = $properties[0] ?? null;

            if ($property instanceof Property) {
                $value = (string) $property->getValue();

                if ('' !== $value) {
                    $names[] = $value;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The `RECURRENCE-ID` of an occurrence, normalized the same way its start
     * would be: a UTC instant, or a bare date.
     *
     * A moved override reports its *actual* start in `id`/`start` while this
     * keeps the original slot, so "moved to 15:00" stays distinguishable from
     * "always 15:00".
     *
     * @param VEvent&iterable<mixed, mixed> $component
     */
    private function recurrenceId(VEvent $component, bool $allDay): ?string
    {
        $property = $this->dateTimeProperty($component, 'RECURRENCE-ID');

        if (null === $property) {
            return null;
        }

        if ($allDay || !$property->hasTime()) {
            return $this->formatDate((string) $property->getValue());
        }

        $instants = $property->getDateTimes(new DateTimeZone('UTC'));
        $first = $instants[0] ?? null;

        if (null === $first) {
            return null;
        }

        return $first->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * `20261005` -> `2026-10-05`. A bare date is never zone-converted.
     */
    private function formatDate(string $value): string
    {
        foreach (['!Ymd', '!Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));

            if (false !== $date) {
                return $date->format('Y-m-d');
            }
        }

        throw new RuntimeException(\sprintf('unrecognised date value "%s"', $value));
    }

    /**
     * @param (VEvent&iterable<mixed, mixed>)|(VTodo&iterable<mixed, mixed>) $component
     */
    private function text(VEvent|VTodo $component, string $name): ?string
    {
        $properties = $component->select($name);
        $property = $properties[0] ?? null;

        if (!$property instanceof Property) {
            return null;
        }

        $value = trim((string) $property->getValue());

        return '' === $value ? null : $value;
    }

    /**
     * @param (VEvent&iterable<mixed, mixed>)|(VTodo&iterable<mixed, mixed>) $component
     *
     * @return list<string>
     */
    private function categories(VEvent|VTodo $component): array
    {
        $properties = $component->select('CATEGORIES');
        $property = $properties[0] ?? null;

        if (!$property instanceof Property) {
            return [];
        }

        $values = [];

        foreach ($property->getParts() as $part) {
            $part = trim((string) $part);

            if ('' !== $part) {
                $values[] = $part;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * The first date-time property with this name, if any.
     *
     * @param (VEvent&iterable<mixed, mixed>)|(VTodo&iterable<mixed, mixed>) $component
     *
     * @return (DateTimeProperty&iterable<mixed, mixed>)|null
     */
    private function dateTimeProperty(VEvent|VTodo $component, string $name): ?DateTimeProperty
    {
        $properties = $component->select($name);
        $property = $properties[0] ?? null;

        return $property instanceof DateTimeProperty ? $property : null;
    }

    /**
     * @param (VEvent&iterable<mixed, mixed>)|(VTodo&iterable<mixed, mixed>) $component
     */
    private function uidOf(VEvent|VTodo $component): ?string
    {
        $properties = $component->select('UID');
        $property = $properties[0] ?? null;

        if (!$property instanceof Property) {
            return null;
        }

        $value = (string) $property->getValue();

        return '' === $value ? null : $value;
    }

    /**
     * @param VCalendar&iterable<mixed, mixed> $calendar
     */
    private function firstUid(VCalendar $calendar): ?string
    {
        foreach ($calendar->select('VEVENT') as $component) {
            if ($component instanceof VEvent) {
                return $this->uidOf($component);
            }
        }

        return null;
    }

    /**
     * Clamp the expansion window to a sane span.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function clampWindow(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $from = $from->setTimezone(new DateTimeZone('UTC'));
        $to = $to->setTimezone(new DateTimeZone('UTC'));

        if ($to <= $from) {
            throw new RuntimeException('calendar window end must be after its start');
        }

        $max = $from->modify(\sprintf('+%d days', self::MAX_EXPANSION_DAYS));

        return [$from, $to > $max ? $max : $to];
    }
}
