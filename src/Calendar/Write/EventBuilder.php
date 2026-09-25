<?php

declare(strict_types=1);

namespace App\Calendar\Write;

use App\Calendar\Domain\TimeZoneRule;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Builds and edits the iCalendar payloads a write sends.
 *
 * Everything goes through sabre's data model rather than string
 * concatenation. That is not tidiness: a caller-supplied `summary` is
 * attacker-influenced text, and sabre escapes and folds it correctly on
 * serialize, where hand-rolled `sprintf` would put a `\r\n` straight into the
 * wire format and let the caller append properties or whole components. The
 * escaping is the security boundary, so the builder must never bypass it.
 *
 * The other reason is preservation. An event may carry properties this
 * application does not model — `ATTENDEE`, `VALARM`, `ORGANIZER`, custom
 * `X-` properties — and an update must not silently delete them. So an
 * update **modifies the parsed object in place** rather than constructing a
 * replacement from the fields we happen to understand.
 */
final readonly class EventBuilder
{
    public function __construct(
        private TimeZoneRule $timeZone,
    ) {
    }

    /**
     * A brand-new event object.
     *
     * `DTSTAMP` and a `PRODID` are added because sabre's serializer sets
     * them, and `VERSION`/`CALSCALE` because a payload without them is
     * rejected by strict servers. Nothing here depends on the deployment's
     * timezone for *storage*: a timed event is written as UTC (`...Z`), which
     * every server and client agrees on, and rendered back into `TZ` on read
     * by the mapper. Storing it in the caller's local wall clock with a
     * `TZID` would be a claim about where the event lives that a write tool
     * has no business making.
     */
    /**
     * @return VCalendar&iterable<mixed, mixed>
     */
    public function create(string $uid, EventDraft $draft, When $start, ?When $end): VCalendar
    {
        $calendar = new VCalendar();
        $event = $calendar->add('VEVENT');
        \assert($event instanceof VEvent);

        $this->setText($event, 'UID', $uid);

        $this->applyTimes($event, $start, $end);
        $this->applyText($event, $draft);

        return $calendar;
    }

    /**
     * The same event with one occurrence replaced by an override.
     *
     * A moved occurrence is expressed as a *second* `VEVENT` in the same
     * object carrying `RECURRENCE-ID` — measured behaviour, and the only way
     * to move one occurrence without touching the rest (see
     * CALENDAR-WRITES-FINDINGS §4).
     *
     * `$occurrence` is the id the caller was given; `$originalSlot` is the
     * `RECURRENCE-ID` that occurrence currently has, which is what the new
     * override must match on. Those are the same moment for an untouched
     * occurrence and differ for one that was already moved, which is why the
     * caller passes both rather than deriving one from the other.
     *
     * An override for a slot that already has one **replaces** it, so editing
     * a moved occurrence twice does not accumulate components.
     */
    /**
     * @param VCalendar&iterable<mixed, mixed> $calendar
     *
     * @return VCalendar&iterable<mixed, mixed>
     */
    public function withOccurrenceOverride(
        VCalendar $calendar,
        string $uid,
        When $originalSlot,
        EventDraft $draft,
        ?When $start,
        ?When $end,
    ): VCalendar {
        $master = $this->masterOf($calendar, $uid);

        if (null === $master) {
            throw new WriteRefused('This event has no series to modify — it is not a recurring event.');
        }

        $override = $this->findOverride($calendar, $uid, $originalSlot) ?? $this->inheritFrom($master, $calendar, $uid, $originalSlot);

        $this->applyText($override, $draft);

        // Times are only rewritten when the caller named one. An override that
        // changes just the summary keeps the occurrence where it was, which is
        // what "the override inherits the master" means.
        if (null !== $start) {
            $this->applyTimes($override, $start, $end ?? $this->endFor($override));
        } elseif (null !== $end) {
            $this->applyEndOnly($override, $end);
        }

        return $calendar;
    }

    /**
     * The same event with every occurrence **after** `$fromExclusive` removed.
     *
     * This is the honest version of "edit this and future occurrences": rather
     * than trying to split a series — rewriting `RRULE` bounds, copying
     * overrides, and getting `COUNT`/`UNTIL` arithmetic right for every
     * frequency — it *truncates* the series and leaves the caller to create
     * the new one. That is a supported, well-defined change, and it is
     * deliberately narrower than the "this and future" edit the design left
     * open.
     */
    /**
     * @param VCalendar&iterable<mixed, mixed> $calendar
     *
     * @return VCalendar&iterable<mixed, mixed>
     */
    public function truncateFrom(VCalendar $calendar, string $uid, When $fromExclusive): VCalendar
    {
        $master = $this->masterOf($calendar, $uid);

        if (null === $master) {
            throw new WriteRefused('This event has no series to modify — it is not a recurring event.');
        }

        $rrule = $this->propertyNamed($master, 'RRULE');

        if (null === $rrule) {
            throw new WriteRefused('This event does not repeat, so there is nothing to truncate.');
        }

        $rruleText = (string) $rrule->getValue();

        // `UNTIL` replaces `COUNT`: the two are mutually exclusive in RFC
        // 5545, and leaving both is a payload a strict server rejects.
        $until = $this->untilValue($fromExclusive);
        $trimmed = preg_replace('/;?COUNT=\d+/i', '', $rruleText) ?? $rruleText;
        $trimmed = preg_replace('/;?UNTIL=[^;]+/i', '', $trimmed) ?? $trimmed;

        $this->setText($master, 'RRULE', rtrim($trimmed, ';').';UNTIL='.$until);

        // An override at or after the cut has no master occurrence to hang
        // from any more, so leaving it would orphan a component.
        foreach ($this->overridesFor($calendar, $uid) as $override) {
            if (!$override instanceof VEvent) {
                continue;
            }

            $slot = $this->recurrenceIdOf($override);

            if (null !== $slot && null !== $slot->instant && $slot->instant >= $fromExclusive->instant) {
                $calendar->remove($override);
            }
        }

        return $calendar;
    }

    /**
     * The same event with an `EXDATE` added for one occurrence.
     *
     * Deleting one occurrence is *not* a `DELETE`: that would remove the whole
     * series. `EXDATE` is how a single slot is removed from a recurring event,
     * and it is also what a server would produce if the delete were done in a
     * calendar UI (measured: 4 occurrences → 3 — CALENDAR-WRITES-FINDINGS §5).
     */
    /**
     * @param VCalendar&iterable<mixed, mixed> $calendar
     *
     * @return VCalendar&iterable<mixed, mixed>
     */
    public function withoutOccurrence(VCalendar $calendar, string $uid, When $slot): VCalendar
    {
        $master = $this->masterOf($calendar, $uid);

        if (null === $master) {
            throw new WriteRefused('This event has no series to modify — it is not a recurring event.');
        }

        if (null === $this->propertyNamed($master, 'RRULE') && null === $this->propertyNamed($master, 'RDATE')) {
            throw new WriteRefused('This event does not repeat, so its one occurrence cannot be deleted on its own. Delete the event instead.');
        }

        // An occurrence that had been *moved* carries both an override and a
        // slot the master still generates, so deleting it takes both actions:
        //
        // - dropping the override alone would put the original occurrence
        //   back (the master regenerates the slot) — measured: the moved
        //   15:00 copy vanished and 10:00 reappeared, which is "un-move", not
        //   "delete";
        // - excluding the slot alone would leave the moved copy visible,
        //   because an override is its own component and does not inherit the
        //   master's exclusions.
        //
        // So the override is removed *and* the slot excluded.
        $override = $this->findOverride($calendar, $uid, $slot);

        if (null !== $override) {
            $calendar->remove($override);
        }

        $this->addExdate($master, $slot);

        return $calendar;
    }

    /**
     * Parse a payload fetched from a server.
     *
     * Unparseable input is refused rather than replaced: if the stored object
     * cannot be read, writing back a rebuilt one would delete whatever we
     * could not understand.
     */
    /**
     * @return VCalendar&iterable<mixed, mixed>
     */
    public function parse(string $ics): VCalendar
    {
        try {
            $component = Reader::read($ics);
        } catch (Throwable $e) {
            throw new WriteRefused('This event could not be read, so it will not be modified: '.$e->getMessage());
        }

        if (!$component instanceof VCalendar) {
            throw new WriteRefused('This event is not a calendar object, so it will not be modified.');
        }

        return $component;
    }

    /**
     * @param VCalendar&iterable<mixed, mixed> $calendar
     */
    public function serialize(VCalendar $calendar): string
    {
        return $calendar->serialize();
    }

    /**
     * The series component for a UID, if this object has one.
     *
     * Exposed so the writer can decide whether an edit has a master to apply
     * to, rather than reaching into the component tree itself.
     */
    /**
     * @param VCalendar&iterable<mixed, mixed> $calendar
     *
     * @return (VEvent&iterable<mixed, mixed>)|null
     */
    public function master(VCalendar $calendar, string $uid): ?VEvent
    {
        return $this->masterOf($calendar, $uid);
    }

    /**
     * Change the series component's own details.
     *
     * Text only changes what was mentioned; times are rewritten only when the
     * caller named a start, so an edit to the summary cannot shift the series
     * as a side effect. `DTSTART` decides what kind of event the series is, so
     * the two are always written together by `applyTimes`.
     */
    /**
     * @param VEvent&iterable<mixed, mixed> $master
     */
    public function applyToMaster(VEvent $master, EventDraft $draft, ?When $start, ?When $end): void
    {
        $this->applyText($master, $draft);

        if (null !== $start) {
            $this->applyTimes($master, $start, $end);
        } elseif (null !== $end) {
            $this->applyEndOnly($master, $end);
        }
    }

    /**
     * The component a series is anchored on: the `VEVENT` for this UID with no
     * `RECURRENCE-ID`.
     */
    /**
     * @param VCalendar&iterable<mixed, mixed> $calendar
     *
     * @return (VEvent&iterable<mixed, mixed>)|null
     */
    private function masterOf(VCalendar $calendar, string $uid): ?VEvent
    {
        foreach ($calendar->select('VEVENT') as $component) {
            if (!$component instanceof VEvent) {
                continue;
            }

            if ($this->uidOf($component) !== $uid) {
                continue;
            }

            if (null === $this->propertyNamed($component, 'RECURRENCE-ID')) {
                return $component;
            }
        }

        return null;
    }

    /**
     * Every override component for a UID.
     *
     * Returns the base `Component` type and lets callers narrow, the same way
     * `EventMapper::selectAll()` does — an intersection inside a generic is not
     * expressible in a way the analyser accepts, and the narrowing is a line of
     * `instanceof` at the call site either way.
     *
     * @param VCalendar&iterable<mixed, mixed> $calendar
     *
     * @return list<Component>
     */
    private function overridesFor(VCalendar $calendar, string $uid): array
    {
        $overrides = [];

        foreach ($calendar->select('VEVENT') as $component) {
            if ($component instanceof VEvent
                && $this->uidOf($component) === $uid
                && null !== $this->propertyNamed($component, 'RECURRENCE-ID')
            ) {
                $overrides[] = $component;
            }
        }

        return $overrides;
    }

    /**
     * The override for one slot, matched on the moment `RECURRENCE-ID` names.
     *
     * @param VCalendar&iterable<mixed, mixed> $calendar
     *
     * @return (VEvent&iterable<mixed, mixed>)|null
     */
    private function findOverride(VCalendar $calendar, string $uid, When $slot): ?VEvent
    {
        foreach ($this->overridesFor($calendar, $uid) as $override) {
            if (!$override instanceof VEvent) {
                continue;
            }

            $existing = $this->recurrenceIdOf($override);

            if (null !== $existing && $existing->sameAs($slot)) {
                return $override;
            }
        }

        return null;
    }

    /**
     * A new override that starts as a copy of the master.
     *
     * Copying rather than starting blank is what makes a *partial* change
     * sensible: "move this occurrence to 15:00" should keep the summary,
     * description and location, not blank them.
     */
    /**
     * @param VEvent&iterable<mixed, mixed>    $master
     * @param VCalendar&iterable<mixed, mixed> $calendar
     *
     * @return VEvent&iterable<mixed, mixed>
     */
    private function inheritFrom(VEvent $master, VCalendar $calendar, string $uid, When $slot): VEvent
    {
        $override = $calendar->add('VEVENT');
        \assert($override instanceof VEvent);

        foreach ($master->children() as $child) {
            // `RECURRENCE-ID` and the recurrence rules describe the series,
            // not this occurrence, and must not be copied onto an override.
            if ($child instanceof Property && \in_array($child->name, ['UID', 'RECURRENCE-ID', 'RRULE', 'RDATE', 'EXDATE', 'DTSTAMP'], true)) {
                continue;
            }

            if ($child instanceof Property) {
                $override->add(clone $child);
            }
        }

        $this->setText($override, 'UID', $uid);
        $this->applySlot($override, $slot);

        return $override;
    }

    /**
     * Write the `RECURRENCE-ID` for a slot.
     *
     * All-day slots stay date-valued; timed slots are written as UTC. UTC is
     * the right choice for a *matching* value even when the series' own
     * `DTSTART` carries a `TZID`: `RECURRENCE-ID` identifies a slot to be
     * replaced, comparison happens on the resolved instant, and an instant is
     * what "the same slot" means — as measured, a series written with
     * `TZID=America/New_York` and an override keyed on the UTC instant of the
     * slot combine correctly (CALENDAR-WRITES-FINDINGS §4).
     */
    /**
     * @param VEvent&iterable<mixed, mixed> $override
     */
    private function applySlot(VEvent $override, When $slot): void
    {
        if ($slot->isDate) {
            $override->add('RECURRENCE-ID', $slot->toDateValue(), ['VALUE' => 'DATE']);

            return;
        }

        $override->add('RECURRENCE-ID', $slot->toUtcDateTimeValue());
    }

    /**
     * Add or replace the `EXDATE` for one slot.
     *
     * Appended rather than assigned, because an event may legitimately already
     * have several excluded dates and replacing the property would silently
     * put the others back.
     */
    /**
     * @param VEvent&iterable<mixed, mixed> $master
     */
    private function addExdate(VEvent $master, When $slot): void
    {
        foreach ($this->exdatesOf($master) as $existing) {
            if ($existing->sameAs($slot)) {
                return;
            }
        }

        if ($slot->isDate) {
            $master->add('EXDATE', $slot->toDateValue(), ['VALUE' => 'DATE']);

            return;
        }

        $master->add('EXDATE', $slot->toUtcDateTimeValue());
    }

    /**
     * @return list<When>
     */
    /**
     * @param VEvent&iterable<mixed, mixed> $master
     *
     * @return list<When>
     */
    private function exdatesOf(VEvent $master): array
    {
        $found = [];

        foreach ($master->select('EXDATE') as $property) {
            foreach ($this->momentsOf($property) as $moment) {
                $found[] = $moment;
            }
        }

        return $found;
    }

    /**
     * @param VEvent&iterable<mixed, mixed> $component
     */
    private function recurrenceIdOf(VEvent $component): ?When
    {
        $property = $this->propertyNamed($component, 'RECURRENCE-ID');

        return null === $property ? null : ($this->momentsOf($property)[0] ?? null);
    }

    /**
     * Every moment a date-valued property names.
     *
     * A property can carry a comma-separated list, and `EXDATE` usually does,
     * so reading only the first value would quietly ignore exclusions.
     *
     * @return list<When>
     */
    /**
     * @param Property&iterable<mixed, mixed> $property
     *
     * @return list<When>
     */
    private function momentsOf(Property $property): array
    {
        $moments = [];

        // `hasTime()` belongs to the date-time property, not to every
        // property, so the narrowing is what makes this call checkable — and
        // also what makes it correct: a bare `EXDATE` with no time is read as
        // a date, which is the reading the read path already uses.
        $hasTime = $property instanceof DateTimeProperty && $property->hasTime();

        foreach ($property->getParts() as $part) {
            try {
                $text = \is_scalar($part) ? (string) $part : '';

                if ('' === $text) {
                    continue;
                }

                if ($hasTime) {
                    $zone = $this->zoneOf($property);
                    $moments[] = When::fromInstant(DateTimeParser::parseDateTime($text, $zone));

                    continue;
                }

                $raw = $text;
                $moments[] = When::fromDate(\sprintf('%s-%s-%s', substr($raw, 0, 4), substr($raw, 4, 2), substr($raw, 6, 2)));
            } catch (Throwable) {
                // An unreadable date is skipped: one bad `EXDATE` must not
                // make the whole event unwritable.
                continue;
            }
        }

        return $moments;
    }

    /**
     * The zone a property's value is expressed in.
     *
     * `TZID` when present and resolvable, otherwise UTC — which is the
     * correct reading for a value that ends in `Z`, and the only defensible
     * default for one that does not.
     */
    /**
     * @param Property&iterable<mixed, mixed> $property
     */
    private function zoneOf(Property $property): DateTimeZone
    {
        $parameters = $property->parameters();

        if (isset($parameters['TZID'])) {
            // `getValue()` is annotated `mixed` on the parameter API, so the
            // value is narrowed before use rather than cast — a cast would
            // assert something about sabre we cannot back up.
            $raw = $parameters['TZID']->getValue();
            $name = \is_scalar($raw) ? (string) $raw : '';

            if ('' !== $name) {
                try {
                    return new DateTimeZone($name);
                } catch (Throwable) {
                    // Fall through to UTC.
                }
            }
        }

        return new DateTimeZone('UTC');
    }

    /**
     * @param VEvent&iterable<mixed, mixed> $component
     */
    private function uidOf(VEvent $component): ?string
    {
        $property = $this->propertyNamed($component, 'UID');

        return null === $property ? null : (string) $property->getValue();
    }

    /**
     * @param VEvent&iterable<mixed, mixed> $event
     */
    private function applyText(VEvent $event, EventDraft $draft): void
    {
        // Set rather than appended: an event with two summaries is a malformed
        // component that reads back differently per client, and "edit" turning
        // into "duplicate" is the classic way that happens.
        $this->setText($event, 'SUMMARY', $draft->summary);
        $this->setText($event, 'DESCRIPTION', $draft->description);

        // An empty string is a deliberate clear (see EventDraft), so it is
        // written rather than treated as "not mentioned" — and an empty
        // property value is not the same as an absent property to a client.
        $this->setText($event, 'LOCATION', $draft->location);
    }

    /**
     * Replace a text property, or leave it alone when the caller said nothing.
     *
     * Set through the property API rather than `add()`, because `add()` on a
     * component treats its first argument as a *component* name for certain
     * names and synthesises a value: `$event->add('UID', 'x@test')` yields a
     * generated UUID and discards `x@test`, which is how an override ended up
     * with a UID that matched no other component in the object. Assigning the
     * property is the API that actually sets it.
     *
     * @param VEvent&iterable<mixed, mixed> $event
     */
    private function setText(VEvent $event, string $name, ?string $value): void
    {
        if (null === $value) {
            return;
        }

        $this->removeNamed($event, $name);

        $event->{$name} = $value;
    }

    /**
     * Drop every property with this name.
     *
     * Looped rather than called once because a property may legitimately
     * appear more than once (`EXDATE`, `ATTENDEE`), and a caller replacing one
     * of those means all of them.
     *
     * @param VEvent&iterable<mixed, mixed> $event
     */
    private function removeNamed(VEvent $event, string $name): void
    {
        foreach ($event->select($name) as $existing) {
            $event->remove($existing);
        }
    }

    /**
     * Set `DTSTART`, and `DTEND` if one was given.
     *
     * An all-day event with no end gets a one-day end, because an all-day
     * event's `DTEND` is exclusive and omitting it leaves many clients
     * guessing. A timed event with no end simply has none, which is valid and
     * means "no stated duration".
     */
    /**
     * @param VEvent&iterable<mixed, mixed> $event
     */
    private function applyTimes(VEvent $event, When $start, ?When $end): void
    {
        $this->removeTimeProperties($event);

        if ($start->isDate) {
            $event->add('DTSTART', $start->toDateValue(), ['VALUE' => 'DATE']);

            $endValue = true === $end?->isDate
                ? $end->toDateValue()
                : (new DateTimeImmutable((string) $start->date, $this->timeZone->zone()))->modify('+1 day')->format('Ymd');

            $event->add('DTEND', $endValue, ['VALUE' => 'DATE']);

            return;
        }

        $event->add('DTSTART', $start->toUtcDateTimeValue());

        // A date-valued end beside a timed start would be a malformed
        // component, so a mismatched end is dropped rather than written. The
        // start decides what kind of event this is; `applyEndOnly` is the
        // narrow tool for changing an end on its own.
        if (null !== $end && !$end->isDate) {
            $event->add('DTEND', $end->toUtcDateTimeValue());
        }
    }

    /**
     * @param VEvent&iterable<mixed, mixed> $event
     */
    private function applyEndOnly(VEvent $event, When $end): void
    {
        $this->removeTimePropertiesNamed($event, 'DTEND');

        // The start is authoritative about the event's shape, so the end is
        // written in whatever shape the start already has. Writing the end in
        // the shape the *caller* used would let one edit produce a component
        // whose two halves disagree, which reads back as a different event.
        $start = $this->propertyNamed($event, 'DTSTART');
        $timed = !$start instanceof DateTimeProperty || $start->hasTime();

        if ($timed) {
            $event->add('DTEND', $end->isDate
                ? $this->asTimedEnd($end)
                : $end->toUtcDateTimeValue());

            return;
        }

        $event->add('DTEND', $end->isDate
            ? $end->toDateValue()
            : $this->asDateEnd($end), ['VALUE' => 'DATE']);
    }

    /**
     * A date-only end read as the instant it ends, for a timed event.
     *
     * Midnight local at the start of that day, which is what "ends on the 5th"
     * means for an event that has a time.
     */
    private function asTimedEnd(When $end): string
    {
        $date = (string) $end->date;

        return (new DateTimeImmutable($date.' 00:00:00', $this->timeZone->zone()))->format('Ymd\THis\Z');
    }

    /**
     * A timed end read as the date it falls on in the deployment's timezone.
     *
     * The same day a caller would name if they read the listing, so the
     * rendered date and the stored one do not disagree.
     */
    private function asDateEnd(When $end): string
    {
        \assert(null !== $end->instant);

        return $end->instant->setTimezone($this->timeZone->zone())->format('Ymd');
    }

    /**
     * Drop the time properties a rewrite replaces.
     *
     * `DTSTART`/`DTEND` are set rather than appended so an event cannot end up
     * with two of either — the classic way an "edit" turns into a malformed
     * object that reads back as a duplicate.
     */
    /**
     * @param VEvent&iterable<mixed, mixed> $event
     */
    private function removeTimeProperties(VEvent $event): void
    {
        $this->removeTimePropertiesNamed($event, 'DTSTART');
        $this->removeTimePropertiesNamed($event, 'DTEND');
    }

    /**
     * @param VEvent&iterable<mixed, mixed> $event
     */
    private function removeTimePropertiesNamed(VEvent $event, string $name): void
    {
        foreach ($event->select($name) as $property) {
            $event->remove($property);
        }
    }

    /**
     * @param VEvent&iterable<mixed, mixed> $event
     */
    private function endFor(VEvent $event): ?When
    {
        $property = $this->propertyNamed($event, 'DTEND');

        return null === $property ? null : ($this->momentsOf($property)[0] ?? null);
    }

    /**
     * One named property, or null.
     *
     * `select()` rather than the magic `__get`, which is the idiom the read
     * path already uses and for the same reason: sabre's own `@property`
     * annotations name classes that do not resolve, so the magic route cannot
     * be statically checked at all — and the write path is the last place to
     * give up type checking.
     *
     * @param (VEvent&iterable<mixed, mixed>)|(VCalendar&iterable<mixed, mixed>) $component
     *
     * @return (Property&iterable<mixed, mixed>)|null
     */
    private function propertyNamed(VEvent|VCalendar $component, string $name): ?Property
    {
        $properties = $component->select($name);
        $property = $properties[0] ?? null;

        return $property instanceof Property ? $property : null;
    }

    /**
     * The `UNTIL` value for a truncation, matching the rule's own granularity.
     *
     * RFC 5545 requires `UNTIL` to agree with `DTSTART`: a rule over date
     * values takes a date, one over date-times takes a UTC instant. Getting
     * this wrong is rejected by strict servers and silently misread by others.
     */
    private function untilValue(When $fromExclusive): string
    {
        if ($fromExclusive->isDate) {
            return $fromExclusive->toDateValue();
        }

        return $fromExclusive->toUtcDateTimeValue();
    }
}
