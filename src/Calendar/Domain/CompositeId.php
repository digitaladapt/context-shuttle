<?php

declare(strict_types=1);

namespace App\Calendar\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Override;

/**
 * The `{UID}::Occurrence` address of one calendar occurrence (finding 1).
 *
 * A recurring series shares one UID across every occurrence, so a distinct
 * address is needed. The occurrence half is the occurrence's start **in
 * UTC** — an instant for a timed event, a date for an all-day one — so an
 * id names a point in time and does not move when the deployment changes
 * its `TZ`.
 *
 * Parsing is regex-first with the **date case tested before the instant
 * case** (finding 15). That ordering is load-bearing: `DateTimeImmutable`
 * accepts `2026-11-01` as a valid instant, so a "does the tail parse?"
 * rule would read every all-day occurrence as midnight UTC and then shift
 * its day under a non-UTC zone (finding 14). The timed form therefore also
 * requires an explicit `Z` or offset, so a floating local time can never be
 * mistaken for an instant.
 *
 * A string whose tail matches neither pattern is not an occurrence at all —
 * the whole thing is a plain UID. That keeps a UID which merely contains
 * `::` working.
 */
final readonly class CompositeId
{
    /** An all-day occurrence: a date, never an instant. */
    public const DATE_TAIL = '/^\d{4}-\d{2}-\d{2}$/';

    /** A timed occurrence: requires an explicit `Z` or numeric offset. */
    public const INSTANT_TAIL = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/';

    /**
     * @param string                 $uid           the series UID, unchanged
     * @param DateTimeImmutable|null $instant       UTC start for a timed occurrence
     * @param string|null            $date          `YYYY-MM-DD` for an all-day occurrence
     * @param bool                   $hasOccurrence whether a tail was recognised at all
     */
    private function __construct(
        public string $uid,
        public ?DateTimeImmutable $instant,
        public ?string $date,
        public bool $hasOccurrence,
    ) {
    }

    /**
     * Build the id of one occurrence.
     *
     * Pass `$date` for an all-day occurrence (`YYYY-MM-DD`, never zone
     * converted) or `$instant` for a timed one (rendered as UTC).
     */
    public static function forOccurrence(string $uid, ?DateTimeImmutable $instant = null, ?string $date = null): self
    {
        if (null !== $date) {
            return new self($uid, null, $date, true);
        }

        if (null === $instant) {
            return new self($uid, null, null, false);
        }

        return new self($uid, $instant->setTimezone(new DateTimeZone('UTC')), null, true);
    }

    /**
     * Parse an id that is either a composite id or a plain UID.
     *
     * Splits on the *last* `::` so that a UID containing `::` still
     * resolves, then classifies the tail.
     */
    public static function parse(string $value): self
    {
        $separator = strrpos($value, '::');
        if (false === $separator) {
            return new self($value, null, null, false);
        }

        $uid = substr($value, 0, $separator);
        $tail = substr($value, $separator + 2);

        // Order matters — date first (finding 15).
        if (1 === preg_match(self::DATE_TAIL, $tail)) {
            return new self($uid, null, $tail, true);
        }

        if (1 === preg_match(self::INSTANT_TAIL, $tail)) {
            $instant = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $tail, new DateTimeZone('UTC'));

            if (false !== $instant) {
                // Normalize to UTC. Keeping the parsed offset would make
                // `toString()` print the local wall clock followed by a
                // literal `Z` — turning an instant of 19:00Z into `15:00Z`,
                // which is the same shape of silent shift the date case is
                // ordered to avoid.
                return new self($uid, $instant->setTimezone(new DateTimeZone('UTC')), null, true);
            }
        }

        // Not an occurrence shape — the whole string is the UID.
        return new self($value, null, null, false);
    }

    /**
     * The wire form: `{UID}::Occurrence`, or the bare UID when there is no
     * occurrence part (a non-recurring event's id equals its uid).
     */
    public function toString(): string
    {
        if (null !== $this->date) {
            return $this->uid.'::'.$this->date;
        }

        if (null !== $this->instant) {
            return $this->uid.'::'.$this->instant->format('Y-m-d\TH:i:s\Z');
        }

        return $this->uid;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * The key used to order and deduplicate rows: the instant for a timed
     * occurrence, the date for an all-day one, the empty string otherwise.
     */
    public function sortKey(): string
    {
        return $this->date ?? $this->instant?->format('Y-m-d\TH:i:s\Z') ?? '';
    }
}
