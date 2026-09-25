<?php

declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\CalendarObject;
use App\Calendar\Domain\ComponentType;
use DateTimeImmutable;

/**
 * One source of calendar data.
 *
 * Both sources satisfy this contract, so the tool layer never learns which it
 * is talking to and both inherit the same timezone and id semantics by
 * construction. If ICS did its own expansion, the two would drift exactly
 * where it is most dangerous — recurrence and DST.
 *
 * Expansion, normalization and DTO shaping sit **above** this interface, in
 * the reader and mapper, so the timezone rule is implemented once.
 */
interface CalendarProvider
{
    /**
     * A non-display identifier, for logging only: `caldav`, `ics`.
     *
     * It exists so an operator reading `mcp_invocation` can tell which side a
     * problem came from, and it must **never** reach a tool result. That is
     * the mechanism behind "an ICS calendar appears no different from a
     * read-only CalDAV one".
     */
    public function name(): string;

    /**
     * Whether this source has enough configuration to answer anything.
     *
     * Decided at **runtime**, not compile time: a container parameter
     * backed by an env var is still the literal placeholder string while
     * the container is being built, so a compiler pass cannot tell a
     * configured source from an unconfigured one — it sees
     * `%env(...)%` and concludes, wrongly, that everything is set.
     */
    public function isConfigured(): bool;

    /**
     * @return list<CalendarInfo>
     */
    public function listCalendars(): array;

    /**
     * Unexpanded components of one type, as the source has them.
     *
     * `$from`/`$to` are a **hint, not a contract**: a provider may use them to
     * narrow the request, and ICS ignores them entirely because a feed is
     * fetched whole. Neither is guaranteed to filter, which is why the reader
     * filters client-side — a server may narrow by a different property than
     * we would, or not at all.
     *
     * @return list<CalendarObject>
     */
    public function fetch(CalendarInfo $calendar, DateTimeImmutable $from, DateTimeImmutable $to, ComponentType $type): array;

    /**
     * Components carrying this UID, wherever they fall in time.
     *
     * Needed because a plain UID says nothing about *when*, so there is no
     * window to narrow by and the match has to be on the property.
     *
     * @return list<CalendarObject>
     */
    public function fetchByUid(CalendarInfo $calendar, string $uid, ComponentType $type): array;
}
