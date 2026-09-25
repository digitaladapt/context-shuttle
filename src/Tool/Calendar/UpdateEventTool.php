<?php

declare(strict_types=1);

namespace App\Tool\Calendar;

use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Write\EventDraft;
use App\Calendar\Write\EventWriter;
use App\Calendar\Write\When;
use App\Calendar\Write\WriteScope;
use InvalidArgumentException;

/**
 * `calendar_update_event` — change an event, or one occurrence of it.
 *
 * **The id decides the scope.** A composite id (`UID::occurrence`, which is
 * what a listing returns) changes that occurrence alone by writing an override
 * into the existing object; a plain UID changes the whole series. There is no
 * `scope` parameter: a second way to say which occurrences to touch would be a
 * second thing to get wrong, and the id already says it unambiguously.
 *
 * **Omitted fields are left alone.** Passing only `summary` renames the event
 * and moves nothing, which is the behaviour that makes a small edit safe. To
 * clear a field, pass an empty string — that is the only way to say "remove the
 * location", since omitting it means "keep it".
 *
 * A no-op is refused rather than sent: a write that changes nothing still costs
 * a round trip, still moves the etag, and still makes a shared calendar look
 * edited to anyone watching it.
 */
final readonly class UpdateEventTool
{
    public function __construct(
        private EventWriter $writer,
        private TimeZoneRule $timeZone,
    ) {
    }

    /**
     * Change one event or occurrence.
     *
     * @param string      $id          an id from a listing (one occurrence), or a uid (the whole series)
     * @param string|null $summary     new title, if it should change
     * @param string|null $start       new start, `YYYY-MM-DD HH:MM` or `YYYY-MM-DD`
     * @param string|null $end         new end, same format as start
     * @param string|null $description new description; empty string clears it
     * @param string|null $location    new location; empty string clears it
     *
     * @return array<string, mixed>
     */
    public function updateEvent(
        string $id,
        ?string $summary = null,
        ?string $start = null,
        ?string $end = null,
        ?string $description = null,
        ?string $location = null,
    ): array {
        $id = trim($id);

        if ('' === $id) {
            throw new InvalidArgumentException('id must not be empty. Pass back an id from calendar_list_events, or a series uid.');
        }

        if (null !== $summary && '' === trim($summary)) {
            throw new InvalidArgumentException('summary must not be empty. Omit it to leave the title unchanged; an event always needs a title.');
        }

        $draft = new EventDraft(
            summary: null === $summary ? null : trim($summary),
            description: $description,
            location: $location,
        );

        if ($draft->isEmpty() && null === $start && null === $end) {
            throw new InvalidArgumentException('Nothing to change: provide at least one of summary, start, end, description or location.');
        }

        $startsAt = null === $start ? null : When::parse($start, $this->timeZone, 'start');
        $endsAt = null === $end ? null : When::parse($end, $this->timeZone, 'end');

        if (null !== $startsAt && null !== $endsAt && $startsAt->isDate !== $endsAt->isDate) {
            throw new InvalidArgumentException('start and end must both be dates, or both include a time.');
        }

        $scope = $this->writer->scopeOf($id);

        $uid = $this->writer->update($id, $draft, $startsAt, $endsAt, $scope);

        return [
            'updated' => true,
            'uid' => $uid,
            'scope' => WriteScope::Occurrence === $scope ? 'occurrence' : 'series',
            'timezone' => $this->timeZone->name(),
            'message' => WriteScope::Occurrence === $scope
                ? 'Changed that one occurrence. The rest of the series is unchanged.'
                : 'Changed the whole series.',
        ];
    }
}
