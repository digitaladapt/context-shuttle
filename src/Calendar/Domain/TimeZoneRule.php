<?php

declare(strict_types=1);

namespace App\Calendar\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * The deployment's timezone: **everything in — TZ; everything out — TZ.**.
 *
 * This is the only timezone in the system's vocabulary. A caller should
 * never have to know what an offset is, or when DST starts, so every
 * timestamp that leaves the server is already rendered here, and `from`/`to`
 * are read here too — the input and output sides agree by construction.
 *
 * Note that a *wrong* `TZ` can only mis-render; it cannot change which
 * instant an event is, because the instant is derived from the stored data
 * and this class is applied afterwards.
 */
final readonly class TimeZoneRule
{
    private DateTimeZone $zone;

    /**
     * @param string $timeZone an IANA/PHP timezone identifier, e.g. `Europe/London`
     *
     * @throws InvalidArgumentException when the identifier does not resolve
     */
    public function __construct(string $timeZone)
    {
        $name = '' === trim($timeZone) ? 'UTC' : trim($timeZone);

        try {
            $this->zone = new DateTimeZone($name);
        } catch (Exception $e) {
            throw new InvalidArgumentException(\sprintf('TZ is not a valid timezone: "%s". Use an IANA/PHP identifier such as "Europe/London", "America/New_York" or "UTC"; see DateTimeZone::listIdentifiers() (%d available).', $name, \count(DateTimeZone::listIdentifiers())), 0, $e);
        }

        // Guard the silent-UTC class of mistake (finding 7): a fixed-offset
        // string is a legal DateTimeZone but is almost never a deployment
        // timezone, and it defeats DST entirely.
        if (1 === preg_match('/^[+-]\d{2}:\d{2}$/', $name)) {
            throw new InvalidArgumentException(\sprintf('TZ must be a named timezone, not a fixed offset ("%s"). A fixed offset cannot follow DST; use an identifier such as "Europe/London" or "America/New_York".', $name));
        }
    }

    public function zone(): DateTimeZone
    {
        return $this->zone;
    }

    public function name(): string
    {
        return $this->zone->getName();
    }

    /**
     * Render an instant the way every timestamp leaves this server:
     * ISO 8601 with the offset `TZ` was observing at that moment.
     */
    public function render(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->zone)->format('Y-m-d\TH:i:sP');
    }

    /**
     * Midnight at the start of `YYYY-MM-DD` in `TZ`, as an instant.
     */
    public function startOfDay(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date.' 00:00:00', $this->zone);
    }

    /**
     * Midnight at the start of the day *after* `YYYY-MM-DD` in `TZ`, as an
     * instant — an exclusive upper bound, so an inclusive `to` date is
     * expressed without losing the final day.
     */
    public function endOfDayExclusive(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date.' 00:00:00', $this->zone)->modify('+1 day');
    }
}
