<?php

declare(strict_types=1);

namespace App\Calendar\Ics;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Recovers fixed-offset pseudo-TZIDs (`TZID=UTC-04:00`, `TZID=GMT+05:30`)
 * that `sabre/vobject` refuses to parse (finding 5).
 *
 * The trap: `Reader` throws `InvalidDataException` on these at every option
 * level, and the obvious workaround — passing an explicit zone to
 * `getDateTimes()` — does **not** work either, because sabre resolves the
 * TZID parameter through `TimeZoneUtil`, which silently returns UTC for
 * `UTC-04:00` (finding 7). So the recovery happens here, on the raw payload,
 * *before* parsing.
 *
 * A fixed offset has no DST by definition, so the shift to UTC is constant
 * and the rewrite is exact. Once the values are plain `...Z` instants,
 * sabre's recurrence expansion sees a UTC-anchored series, which is the
 * correct model for a zone that never changes.
 *
 * Only the properties that carry an event's time are rewritten, and only
 * when the value is a `DATE-TIME` — a `VALUE=DATE` (all-day) property can
 * never match, which is what keeps finding 14's day-shift out of this path.
 */
final readonly class FixedOffsetTzidRewriter
{
    /** Property names whose values are instants we may need to shift. */
    private const TIME_PROPERTIES = ['DTSTART', 'DTEND', 'RECURRENCE-ID', 'DUE'];

    /** `UTC-04:00`, `GMT+05:30`, `UTC-0400` — optionally quoted. */
    private const FIXED_OFFSET_TZID = '/^(?:UTC|GMT)([+-])(\d{2}):?(\d{2})$/i';

    /**
     * Rewrite every fixed-offset-TZID time property into its UTC instant.
     *
     * Returns the payload unchanged when there is nothing to recover, so the
     * common path costs one scan and no string surgery.
     */
    public function rewrite(string $ics): string
    {
        if (!str_contains($ics, 'TZID=')) {
            return $ics;
        }

        $lines = preg_split('/\r\n|\r|\n/', $ics);

        if (!\is_array($lines)) {
            return $ics;
        }

        $changed = false;

        foreach ($lines as $index => $line) {
            $rewritten = $this->rewriteLine($line);

            if ($rewritten !== $line) {
                $lines[$index] = $rewritten;
                $changed = true;
            }
        }

        return $changed ? implode("\r\n", $lines) : $ics;
    }

    /**
     * Rewrite one content line, or return it untouched.
     */
    private function rewriteLine(string $line): string
    {
        $separator = $this->valueSeparator($line);
        if (null === $separator) {
            return $line;
        }

        $nameAndParams = substr($line, 0, $separator);
        $value = substr($line, $separator + 1);
        // Split `DTSTART;TZID=...;X=Y` into name plus parameters.
        $parts = explode(';', $nameAndParams);
        $property = strtoupper((string) array_shift($parts));

        if (!\in_array($property, self::TIME_PROPERTIES, true)) {
            return $line;
        }

        // The value must be a DATE-TIME; an all-day DATE is left alone
        // (finding 14 — it must never be converted through a zone).
        if (1 !== preg_match('/^\d{8}T\d{6}$/', $value)) {
            return $line;
        }

        $offsetMinutes = null;
        $keptParams = [];

        foreach ($parts as $parameter) {
            if (0 === stripos($parameter, 'TZID=')) {
                $tzid = trim(substr($parameter, 5), '"');

                if (1 === preg_match(self::FIXED_OFFSET_TZID, $tzid, $matches)) {
                    $offsetMinutes = $this->offsetToMinutes($matches[1], (int) $matches[2], (int) $matches[3]);

                    continue;
                }
            }

            $keptParams[] = $parameter;
        }

        if (null === $offsetMinutes) {
            return $line;
        }

        $local = DateTimeImmutable::createFromFormat('!Ymd\THis', $value, new DateTimeZone('UTC'));
        if (false === $local) {
            return $line;
        }

        // local = UTC + offset, so UTC = local - offset.
        $magnitude = new DateInterval('PT'.abs($offsetMinutes).'M');
        $utc = $offsetMinutes >= 0 ? $local->sub($magnitude) : $local->add($magnitude);

        $rebuilt = $property;
        if ([] !== $keptParams) {
            $rebuilt .= ';'.implode(';', $keptParams);
        }

        return $rebuilt.':'.$utc->format('Ymd\THis').'Z';
    }

    /**
     * The offset of the colon that introduces the property *value*.
     *
     * The naive `strpos($line, ':')` is wrong here, and wrong precisely for
     * the inputs this class exists to handle: `TZID=UTC-04:00` puts a colon
     * *inside* the parameter list, so the first colon splits
     * `DTSTART;TZID=UTC-04` from `00:20261015T090000`.
     *
     * Scanning backwards is correct for this class's purposes: every
     * property it touches ends in a `DATE` or `DATE-TIME` value, and neither
     * can contain a colon. Callers still validate the value afterwards, so a
     * line shaped differently from that expectation is returned untouched
     * rather than mangled.
     */
    private function valueSeparator(string $line): ?int
    {
        $position = strrpos($line, ':');

        return false === $position ? null : $position;
    }

    /**
     * Minutes east of UTC for a `(sign, hours, minutes)` triple —
     * `-` for `UTC-04:00`, `+` for `GMT+05:30`, as the TZID spells it.
     */
    private function offsetToMinutes(string $sign, int $hours, int $minutes): int
    {
        $total = $hours * 60 + $minutes;

        return '-' === $sign ? -$total : $total;
    }
}
