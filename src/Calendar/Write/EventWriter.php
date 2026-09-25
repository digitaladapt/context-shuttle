<?php

declare(strict_types=1);

namespace App\Calendar\Write;

use App\Calendar\CalDav\CalDavClient;
use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\CalendarObject;
use App\Calendar\Domain\ComponentType;
use App\Calendar\Domain\CompositeId;
use App\Calendar\Domain\EditableCalendar;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Provider\CalDavProvider;
use App\Calendar\Provider\CalendarProvider;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Throwable;

/**
 * The write side of the calendar, for one configured calendar.
 *
 * Every operation funnels through here so the boundary rules are enforced in
 * one place:
 *
 * - **one calendar**, named by `CALDAV_EDITABLE_CALENDAR`. Nothing else is
 *   reachable, whatever a caller asks for;
 * - **re-read before write**, so `If-Match` has a value and unmodelled
 *   properties survive an edit;
 * - **the id decides the scope** — a composite id names one occurrence, a
 *   plain UID names the series. There is no parameter to say which, because a
 *   second way to say it would be a second thing to get wrong.
 *
 * The class is deliberately not a `CalendarProvider`: reads are shaped by
 * "list what is there", writes by "change exactly this", and one interface
 * for both would have to be vague about which contract it was honouring.
 */
final readonly class EventWriter
{
    /**
     * How far a series is expanded when recovering an occurrence's
     * `RECURRENCE-ID`. Wide enough for a series anchored in the past and
     * still running, and bounded so a pathological `RRULE` cannot run away.
     */
    private const OVERRIDE_SEARCH_YEARS = 2;

    public function __construct(
        private CalDavClient $client,
        private CalDavProvider $provider,
        private EditableCalendar $editable,
        private EventBuilder $builder,
        private TimeZoneRule $timeZone,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->editable->isConfigured() && $this->client->isConfigured();
    }

    /**
     * Create one event.
     *
     * The object's filename is **generated**, never derived from the UID.
     * Measured: a UID is caller-supplied text, and a UID containing `..`
     * placed into a request path escapes the collection entirely
     * (CALENDAR-WRITES-FINDINGS §3). The UID belongs in the payload, which is
     * where every CalDAV client looks for it; the filename is ours to choose.
     */
    public function create(EventDraft $draft, When $start, ?When $end): string
    {
        $calendar = $this->target();

        $uid = $this->generateUid();
        $payload = $this->builder->create($uid, $draft, $start, $end);

        $href = rtrim($calendar->href, '/').'/'.bin2hex(random_bytes(16)).'.ics';

        $this->client->put($calendar, $href, $this->builder->serialize($payload), mustNotExist: true);

        $this->logger?->info('Calendar event created.', [
            'uid' => $uid,
            'calendar' => $calendar->href,
            'href' => $href,
        ]);

        return $uid;
    }

    /**
     * Change an event: one occurrence if the id names one, otherwise the whole
     * series.
     *
     * `$scope` is not a caller-facing choice — it is how the tool layer passes
     * down what the id already said, so this method cannot be called with a
     * scope the id contradicts.
     */
    public function update(string $id, EventDraft $draft, ?When $start, ?When $end, WriteScope $scope): string
    {
        $calendar = $this->target();
        $object = $this->locate($calendar, $id);

        $payload = $this->builder->parse($object->data);

        if (WriteScope::Series === $scope) {
            $master = $this->builder->master($payload, $object->uid);

            if (null !== $master) {
                $this->applySeriesEdit($master, $draft, $start, $end);
            }
        } else {
            $this->builder->withOccurrenceOverride(
                $payload,
                $object->uid,
                $this->originalSlotOf($object, $id),
                $draft,
                $start,
                $end,
            );
        }

        return $this->write($calendar, $object, $payload, 'updated');
    }

    /**
     * Remove an event: one occurrence if the id names one, otherwise the whole
     * series.
     *
     * Deleting a series is a real `DELETE`; deleting one occurrence rewrites
     * the object with an `EXDATE`, because removing the whole object would
     * take the other occurrences with it.
     */
    public function delete(string $id, WriteScope $scope): string
    {
        $calendar = $this->target();
        $object = $this->locate($calendar, $id);

        if (WriteScope::Series === $scope) {
            $this->client->delete($calendar, $object->href, $object->etag);

            $this->logger?->info('Calendar event deleted.', [
                'uid' => $object->uid,
                'calendar' => $calendar->href,
                'href' => $object->href,
            ]);

            return $object->uid;
        }

        $payload = $this->builder->parse($object->data);
        $payload = $this->builder->withoutOccurrence($payload, $object->uid, $this->originalSlotOf($object, $id));

        return $this->write($calendar, $object, $payload, 'occurrence removed from');
    }

    /**
     * Whether an id names one occurrence rather than a series.
     *
     * Exposed so the tools can decide scope from the id itself instead of
     * accepting a parameter that could disagree with it.
     */
    public function scopeOf(string $id): WriteScope
    {
        return CompositeId::parse($id)->hasOccurrence
            ? WriteScope::Occurrence
            : WriteScope::Series;
    }

    /**
     * The event an id names, resolved to the object that stores it.
     *
     * Reads through the *read* path, so a write can only ever touch something
     * a caller could have seen. An id that resolves to nothing is a refusal
     * naming the id, not an empty success.
     */
    private function locate(CalendarInfo $calendar, string $id): LocatedObject
    {
        $composite = CompositeId::parse(trim($id));

        // Both shapes resolve the same way, by UID: see `objectsAround()`
        // for why an occurrence id must not be looked up by time window.
        $uid = $composite->uid;
        $objects = $this->objectsAround($calendar, $uid);

        foreach ($objects as $object) {
            $payload = $this->safelyParse($object);

            if (null === $payload) {
                continue;
            }

            if (!$this->payloadHasUid($payload, $uid)) {
                continue;
            }

            // A composite id must name an occurrence that is really in this
            // object, or a typo would silently edit the series instead.
            if ($composite->hasOccurrence && !$this->payloadHasOccurrence($payload, $composite)) {
                continue;
            }

            return new LocatedObject($object->href, $object->etag, $uid, $object->data);
        }

        if ($composite->hasOccurrence) {
            throw new WriteRefused(\sprintf('No event found with id "%s", so nothing was changed. It may be stale, or may belong to a calendar this deployment does not allow writing to.', $id));
        }

        throw new WriteRefused(\sprintf('No event found with uid "%s", so nothing was changed. It may be stale, or may belong to a calendar this deployment does not allow writing to.', $id));
    }

    /**
     * The object carrying an id's UID.
     *
     * Fetched by **UID**, not by a time window — measured, and the reverse of
     * what the read path can afford to do. A `calendar-query` with a
     * `<time-range>` returned *zero* objects for a series whose only
     * occurrence in the window had been moved out of it by an override, while
     * both the master and the override plainly exist in the calendar. The
     * server filters on the times it knows about, and an override's new time
     * is not one of them.
     *
     * That makes a windowed fetch unusable here specifically: a write has to
     * find the object by an id the caller was *given*, and if the caller was
     * given that id precisely because the occurrence was moved, the window is
     * exactly the case a time query misses. A UID lookup has no such blind
     * spot, and costs one request either way.
     *
     * @return list<CalendarObject>
     */
    private function objectsAround(CalendarInfo $calendar, string $uid): array
    {
        return $this->provider->fetchByUid($calendar, $uid, ComponentType::Event);
    }

    /**
     * @param VCalendar&iterable<mixed, mixed> $payload
     */
    private function payloadHasUid(VCalendar $payload, string $uid): bool
    {
        foreach ($payload->select('VEVENT') as $component) {
            if (!$component instanceof VEvent) {
                continue;
            }

            // `select()` rather than the magic `->UID`, which sabre annotates
            // with a class that does not resolve and which therefore cannot be
            // statically checked — the same idiom the read path uses.
            $property = $component->select('UID')[0] ?? null;

            if ($property instanceof \Sabre\VObject\Property && (string) $property->getValue() === $uid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this object really contains the named occurrence.
     *
     * Checked by expanding the object, because a composite id names an
     * instant and the object stores a rule: without expanding there is no way
     * to tell whether the named instant is one the series produces. A
     * `calendar-query` narrowed the fetch, but a server that ignores the time
     * range (or a series dense enough to overlap the window) would otherwise
     * let a stale id match an unrelated object.
     *
     * @param VCalendar&iterable<mixed, mixed> $payload
     */
    private function payloadHasOccurrence(VCalendar $payload, CompositeId $composite): bool
    {
        return null !== $this->slotFor($payload, $composite);
    }

    /**
     * The `RECURRENCE-ID` an id's occurrence should be keyed on.
     *
     * For an occurrence the series produces untouched, that is the occurrence's
     * own start. For one already moved by an override, the id names the *new*
     * time while the override is keyed on the *original* slot, so the object
     * has to be expanded to recover it. Getting this wrong would stack a
     * second override beside the existing one, which is why it is resolved
     * here rather than assumed.
     */
    private function originalSlotOf(LocatedObject $object, string $id): When
    {
        $payload = $this->builder->parse($object->data);
        $composite = CompositeId::parse(trim($id));
        $slot = $this->slotFor($payload, $composite);

        if (null === $slot) {
            throw new WriteRefused(\sprintf('The event id "%s" does not name an occurrence of this event, so nothing was changed.', $id));
        }

        return $slot;
    }

    /**
     * Find the `RECURRENCE-ID` for the occurrence a composite id names.
     *
     * Expansion is how a moved occurrence's original slot is recovered: the
     * expanded component keeps `RECURRENCE-ID` (the slot) beside `DTSTART`
     * (where it actually is now), so the two can be told apart.
     *
     * @param VCalendar&iterable<mixed, mixed> $payload
     */
    private function slotFor(VCalendar $payload, CompositeId $composite): ?When
    {
        $target = When::fromComposite($composite);

        if (null === $target || null === $composite->instant && null === $composite->date) {
            return null;
        }

        $anchor = $composite->instant ?? $this->timeZone->startOfDay((string) $composite->date);
        $from = $anchor->modify('-'.self::OVERRIDE_SEARCH_YEARS.' years');
        $to = $anchor->modify('+'.self::OVERRIDE_SEARCH_YEARS.' years');

        try {
            $expanded = (clone $payload)->expand($from, $to, $this->timeZone->zone());
        } catch (Throwable $e) {
            throw new WriteRefused('This event could not be expanded, so it will not be modified: '.$e->getMessage());
        }

        foreach ($expanded->select('VEVENT') as $component) {
            if (!$component instanceof VEvent) {
                continue;
            }

            $start = $this->momentOf($component, 'DTSTART');

            if (null === $start || !$start->sameAs($target)) {
                continue;
            }

            // An unmodified occurrence's `RECURRENCE-ID` equals its start, so
            // either is the right key; a moved one is keyed on the other.
            $recurrenceId = $this->momentOf($component, 'RECURRENCE-ID');

            if (null !== $recurrenceId && !$recurrenceId->sameAs($start)) {
                return $recurrenceId;
            }

            return $start;
        }

        return null;
    }

    /**
     * @param VEvent&iterable<mixed, mixed> $component
     */
    private function momentOf(VEvent $component, string $property): ?When
    {
        $node = $component->select($property)[0] ?? null;

        if (!$node instanceof \Sabre\VObject\Property) {
            return null;
        }

        try {
            // `hasTime()` lives on the date-time property, so the narrowing is
            // what makes the call checkable — and it is also the honest
            // reading: anything that is not a date-time property is a date.
            if (!$node instanceof DateTimeProperty || !$node->hasTime()) {
                $raw = (string) $node->getValue();

                return When::fromDate(\sprintf('%s-%s-%s', substr($raw, 0, 4), substr($raw, 4, 2), substr($raw, 6, 2)));
            }

            // The zone that matters here is UTC: an expanded occurrence's
            // `DTSTART` is a UTC value, and a `RECURRENCE-ID` is compared on
            // the instant it names, so both are read as the instant they are.
            return When::fromInstant(DateTimeParser::parseDateTime((string) $node->getValue(), new DateTimeZone('UTC')));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Replace the series component's own details.
     *
     * Only the master is touched: the overrides are separate components that
     * already carry their own values, and rewriting them all would undo every
     * per-occurrence change that was made deliberately.
     */
    /**
     * @param VEvent&iterable<mixed, mixed> $master
     */
    private function applySeriesEdit(VEvent $master, EventDraft $draft, ?When $start, ?When $end): void
    {
        $this->builder->applyToMaster($master, $draft, $start, $end);
    }

    /**
     * @return (VCalendar&iterable<mixed, mixed>)|null
     */
    private function safelyParse(CalendarObject $object): ?VCalendar
    {
        try {
            return $this->builder->parse($object->data);
        } catch (WriteRefused) {
            // A stored object we cannot read is not a candidate for editing.
            // It is skipped here and reported by the "nothing found" path, so
            // the caller gets one clear sentence rather than a parse error.
            return null;
        }
    }

    /**
     * PUT a modified payload with the etag we read it at.
     *
     * An etag of `null` means the server never gave us one, so there is
     * nothing to match on and no way to detect a concurrent change. The write
     * still goes ahead — refusing would make servers that omit `getetag`
     * completely unusable — but it is logged, because on such a server
     * last-write-wins is back and an operator should know which one they have.
     */
    /**
     * @param VCalendar&iterable<mixed, mixed> $payload
     */
    private function write(CalendarInfo $calendar, LocatedObject $object, VCalendar $payload, string $verb): string
    {
        if (null === $object->etag) {
            $this->logger?->warning('Writing a calendar object with no etag, so a concurrent change cannot be detected.', [
                'calendar' => $calendar->href,
                'href' => $object->href,
                'uid' => $object->uid,
            ]);
        }

        $this->client->put($calendar, $object->href, $this->builder->serialize($payload), ifMatch: $object->etag);

        $this->logger?->info(\sprintf('Calendar event %s.', $verb), [
            'uid' => $object->uid,
            'calendar' => $calendar->href,
            'href' => $object->href,
        ]);

        return $object->uid;
    }

    /**
     * The one calendar writes may touch, resolved from discovery.
     *
     * Resolved on every call rather than cached, because a calendar can vanish
     * or appear and a write is not the place to trust a stale answer. A
     * designate that matches nothing is reported with the calendars the server
     * *did* offer: the usual cause is a typo or a calendar the account cannot
     * see, and both are better said out loud than left looking like "writes
     * are broken".
     */
    private function target(): CalendarInfo
    {
        if (!$this->editable->isConfigured()) {
            // Should be unreachable: the tools are not registered without it.
            // Kept because a container can be hand-built in a test.
            throw new WriteRefused('Writes are not enabled on this deployment: CALDAV_EDITABLE_CALENDAR is not set.');
        }

        if (!$this->client->isConfigured()) {
            throw new WriteRefused('CALDAV_URL is not configured, so there is no calendar to write to.');
        }

        $discovered = $this->provider->listCalendars();

        foreach ($discovered as $info) {
            if ($this->editable->matches($info)) {
                // The server's own privileges still decide whether writing is
                // possible at all. Naming a calendar we may not write to must
                // fail here, clearly, rather than at the server with a 403.
                if ($info->readonly) {
                    throw new WriteRefused(\sprintf('The calendar configured by CALDAV_EDITABLE_CALENDAR ("%s") does not allow writing. Check the account\'s permissions on the server.', $info->href));
                }

                return $info;
            }
        }

        $offered = array_map(static fn (CalendarInfo $info): string => $info->href, $discovered);

        $this->logger?->warning('CALDAV_EDITABLE_CALENDAR matched no discovered calendar.', [
            'entry' => $this->editable->configured,
            'discovered' => $offered,
        ]);

        throw new WriteRefused(\sprintf('CALDAV_EDITABLE_CALENDAR ("%s") did not match any calendar this account can see, so nothing was changed.%s', $this->editable->configured, [] === $offered ? ' The server offered no calendars at all.' : ' It offered: '.implode(', ', $offered).'.'));
    }

    /**
     * A UID that is unlikely to collide with one already on the server.
     *
     * Not a UUID: no dependency for one string, and the domain part is what
     * makes collisions across calendars impossible rather than merely
     * unlikely. It is never used as a filename (see `create`), so it only has
     * to be unique, not safe.
     */
    private function generateUid(): string
    {
        return bin2hex(random_bytes(16)).'@context-shuttle';
    }
}
