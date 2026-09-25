<?php

declare(strict_types=1);

namespace App\Calendar\Write;

use App\Calendar\Domain\CompositeId;
use App\Calendar\Domain\TimeZoneRule;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * A time a caller gave us, read the way the timezone rule says to read it.
 *
 * Two shapes and no third: a bare date is an **all-day** event, and a date
 * with a time is a **timed** event at that wall clock in the deployment's
 * timezone. Nothing else is accepted, deliberately:
 *
 * - **No offsets.** An instant in `-05:00` is a different kind of value from
 *   what every other calendar input and output uses here, and accepting both
 *   would mean the deployment's timezone silently stopped being the one
 *   answer to "when". It is also the format `calendar_list_events` *returns*,
 *   so it is exactly the string a caller is most likely to paste back — which
 *   is why the refusal names the accepted shapes instead of leaving the
 *   caller to guess.
 * - **No seconds.** The read format has them, a write does not need them, and
 *   accepting `:00` while refusing `:30` is a rule nobody would remember.
 *
 * A wall clock that does not exist — the hour a DST jump skips — is refused
 * rather than resolved. PHP would move it forward and hour, so a meeting
 * asked for at 02:30 would quietly become 03:30. The caller can name a time
 * that exists; being told so beats an event an hour from where it was put.
 */
final readonly class When
{
    private const DATE_ONLY = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /** `T` and a space are both accepted; callers produce both. */
    private const DATE_AND_TIME = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/';

    private function __construct(
        public bool $isDate,
        public ?string $date,
        public ?DateTimeImmutable $instant,
    ) {
    }

    /**
     * A bare date, from a value already known to be one.
     *
     * The parser below is the way in for caller input; this is the way in for
     * a date that came off a stored object, where the shape is already known
     * and re-validating it would only be able to fail on something the server
     * wrote.
     */
    public static function fromDate(string $date): self
    {
        return new self(true, $date, null);
    }

    /**
     * An instant, normalized to UTC.
     *
     * Normalized rather than trusted, because `sameAs()` compares instants:
     * a value that arrived as `TZID=Europe/London` and one that arrived as
     * `...Z` have to compare equal for "the id you were given names this
     * occurrence" to hold.
     */
    public static function fromInstant(DateTimeImmutable $instant): self
    {
        return new self(false, null, $instant->setTimezone(new DateTimeZone('UTC')));
    }

    /**
     * Read a caller-supplied time, or refuse it in terms of what it should be.
     *
     * `$field` is the parameter name, so the sentence names the thing to fix.
     */
    public static function parse(string $value, TimeZoneRule $timeZone, string $field): self
    {
        $value = trim($value);

        if (1 === preg_match(self::DATE_ONLY, $value, $matches)) {
            self::assertRealDate($field, $value, (int) $matches[2], (int) $matches[3]);

            return new self(true, $value, null);
        }

        if (1 === preg_match(self::DATE_AND_TIME, $value, $matches)) {
            [, $year, $month, $day, $hour, $minute] = $matches;

            self::assertRealDate($field, $value, (int) $month, (int) $day);

            if ((int) $hour > 23 || (int) $minute > 59) {
                throw new WriteRefused(\sprintf('"%s" is not a valid time for %s: hours run 00-23 and minutes 00-59.', $value, $field));
            }

            $wallClock = \sprintf('%s-%s-%s %s:%s', $year, $month, $day, $hour, $minute);
            $instant = new DateTimeImmutable($wallClock.':00', $timeZone->zone());

            // A skipped wall clock is silently pushed forward by the date
            // library, so round-trip it to find out whether it existed.
            if ($instant->format('Y-m-d H:i') !== $wallClock) {
                throw new WriteRefused(\sprintf('%s "%s" does not exist in %s — that clock time is skipped by a daylight-saving change. Pick a time on either side of it.', $field, $value, $timeZone->name()));
            }

            return new self(false, null, $instant->setTimezone(new DateTimeZone('UTC')));
        }

        throw new WriteRefused(\sprintf('%s "%s" is not a time I can read. Use YYYY-MM-DD HH:MM for a timed event (in %s, with no offset), or YYYY-MM-DD alone for an all-day event. Do not pass back the timestamp format that listings return: it carries an offset, which this does not take.', $field, $value, $timeZone->name()));
    }

    /**
     * The time an occurrence id names, if it names one.
     *
     * A composite id's tail is already the occurrence's start — a UTC instant
     * for a timed event, a date for an all-day one — so it needs no parsing,
     * only the same wrapper.
     */
    public static function fromComposite(CompositeId $composite): ?self
    {
        if (!$composite->hasOccurrence) {
            return null;
        }

        if (null !== $composite->date) {
            return new self(true, $composite->date, null);
        }

        if (null !== $composite->instant) {
            return new self(false, null, $composite->instant->setTimezone(new DateTimeZone('UTC')));
        }

        return null;
    }

    /**
     * Whether this is the same moment (or the same day) as another.
     *
     * Compared as instants so a value that arrived as `TZID=Europe/London`
     * and one that arrived as `...Z` are recognised as the same occurrence —
     * which is the whole basis for "the id you were given names this row".
     */
    public function sameAs(self $other): bool
    {
        if ($this->isDate !== $other->isDate) {
            return false;
        }

        if ($this->isDate) {
            return $this->date === $other->date;
        }

        return $this->instant?->getTimestamp() === $other->instant?->getTimestamp();
    }

    /** A bare date, for an all-day `DTSTART`/`DTEND`/`EXDATE`. */
    public function toDateValue(): string
    {
        \assert(null !== $this->date);

        return str_replace('-', '', $this->date);
    }

    /** The instant, as `DTSTART` is written when nothing says otherwise. */
    public function toUtcDateTimeValue(): string
    {
        \assert(null !== $this->instant);

        return $this->instant->format('Ymd\THis\Z');
    }

    /**
     * The same moment rendered in another zone, as a local date-time value.
     *
     * Used when patching a property that carries a `TZID`: the new time is
     * written the way the old one was, so moving a meeting in a
     * `TZID`-anchored series keeps it at the same *local* hour across a
     * daylight-saving change instead of silently re-anchoring it to UTC.
     *
     * Returns null when the zone will not resolve, which is not an error —
     * the caller falls back to writing the instant.
     */
    public function toZoneDateTimeValue(string $timeZoneName): ?string
    {
        if (null === $this->instant) {
            return null;
        }

        try {
            $zone = new DateTimeZone($timeZoneName);
        } catch (Exception) {
            return null;
        }

        return $this->instant->setTimezone($zone)->format('Ymd\THis');
    }

    /**
     * How this time is shown back to a caller.
     *
     * Rendered in the deployment's timezone, like **every** other timestamp
     * this server emits — including the ones a listing returns, so the value
     * a caller reads here is the same string they would get back from
     * `calendar_get_event`. Returning the underlying UTC instant instead was
     * a real bug: it is the one form the whole timezone rule exists to keep
     * out of the output, and it would have had a caller comparing "09:30"
     * against "14:30+00:00" for the same event.
     */
    public function describe(TimeZoneRule $timeZone): string
    {
        if ($this->isDate) {
            return (string) $this->date;
        }

        \assert(null !== $this->instant);

        return $timeZone->render($this->instant);
    }

    private static function assertRealDate(string $field, string $value, int $month, int $day): void
    {
        $year = (int) substr($value, 0, 4);

        if (!checkdate($month, $day, $year)) {
            throw new WriteRefused(\sprintf('%s "%s" is not a real calendar date.', $field, $value));
        }
    }
}
