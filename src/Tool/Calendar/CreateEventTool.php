<?php

declare(strict_types=1);

namespace App\Tool\Calendar;

use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Write\EventDraft;
use App\Calendar\Write\EventWriter;
use App\Calendar\Write\When;
use App\Calendar\Write\WriteRefused;
use InvalidArgumentException;

/**
 * `calendar_create_event` — put one event on the calendar that is open to
 * writing.
 *
 * There is no `calendar` parameter and there never will be: the target is the
 * one named by `CALDAV_EDITABLE_CALENDAR`, because a caller that could name a
 * target could name the wrong one and a wrong write is not recoverable the way
 * a wrong read is. The tool is not even registered unless that variable is set,
 * so a deployment that has not opted in has no mutation verb to find.
 *
 * Times are given as wall-clock local values in the deployment's timezone, not
 * as the offset-bearing strings listings return. That asymmetry is deliberate:
 * the whole point of the timezone rule is that a caller never has to know an
 * offset, and accepting one on the way in would quietly reintroduce exactly
 * that requirement — so the refusal names the accepted shapes instead.
 */
final readonly class CreateEventTool
{
    public function __construct(
        private EventWriter $writer,
        private TimeZoneRule $timeZone,
    ) {
    }

    /**
     * Create one event.
     *
     * @param string      $summary     the event's title
     * @param string      $start       `YYYY-MM-DD HH:MM` for a timed event, or `YYYY-MM-DD` for an all-day one
     * @param string|null $end         same format as start; for an all-day event this is the exclusive last day
     * @param string|null $description free text
     * @param string|null $location    free text
     *
     * @return array<string, mixed>
     */
    public function createEvent(
        string $summary,
        string $start,
        ?string $end = null,
        ?string $description = null,
        ?string $location = null,
    ): array {
        $summary = trim($summary);

        if ('' === $summary) {
            throw new InvalidArgumentException('summary must not be empty: an event needs a title.');
        }

        $startsAt = When::parse($start, $this->timeZone, 'start');
        $endsAt = null === $end ? null : When::parse($end, $this->timeZone, 'end');

        $this->assertOrder($startsAt, $endsAt);

        $draft = new EventDraft(
            summary: $summary,
            description: $description,
            location: $location,
        );

        $uid = $this->writer->create($draft, $startsAt, $endsAt);

        return [
            'created' => true,
            'uid' => $uid,
            'summary' => $summary,
            'start' => $startsAt->describe($this->timeZone),
            'all_day' => $startsAt->isDate,
            'timezone' => $this->timeZone->name(),
            'message' => \sprintf('Created "%s". Fetch it with calendar_get_event using the uid, or find it with calendar_list_events.', $summary),
        ];
    }

    /**
     * Refuse an end that precedes its start.
     *
     * Checked here rather than left to the server because a server that
     * accepts it produces an object that every client reads differently — and
     * "it saved, but it looks wrong" is a much worse outcome than a refusal
     * with a sentence in it.
     */
    private function assertOrder(When $start, ?When $end): void
    {
        if (null === $end) {
            return;
        }

        if ($start->isDate !== $end->isDate) {
            throw new WriteRefused('start and end must both be dates, or both include a time. An all-day event uses dates for both; a timed event uses a time for both.');
        }

        if ($start->isDate) {
            if ((string) $end->date <= (string) $start->date) {
                throw new WriteRefused(\sprintf('end (%s) must be after start (%s). Remember that an all-day event\'s end is the exclusive last day, so a single-day event on the 5th ends on the 6th.', $end->date, $start->date));
            }

            return;
        }

        if (null !== $end->instant && null !== $start->instant && $end->instant < $start->instant) {
            throw new WriteRefused(\sprintf('end (%s) must not be before start (%s).', $end->describe($this->timeZone), $start->describe($this->timeZone)));
        }
    }
}
