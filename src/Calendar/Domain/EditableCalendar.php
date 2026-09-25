<?php

declare(strict_types=1);

namespace App\Calendar\Domain;

/**
 * The one calendar this deployment will write to, if it was told to write.
 *
 * This is the **write** counterpart to `CALDAV_CALENDARS`, and it inverts the
 * default on purpose. The read allowlist is empty-means-everything, because
 * not thinking about reads is harmless; this one is empty-means-*nothing*,
 * because a default that made a calendar writable without anyone saying so is
 * precisely the accident it exists to prevent.
 *
 * Two things are deliberately *not* here:
 *
 * - **Not an argument.** No write tool takes a calendar parameter. A caller
 *   that could name a target could name the wrong one, and unlike a bad read
 *   that is not recoverable. The target is configuration.
 * - **Not a `CalendarInfo`.** A designated href may not have been discovered
 *   (the server may not offer it, or the read allowlist may filter it out).
 *   Holding a `CalendarInfo` would mean inventing one, and an invented
 *   calendar would be indistinguishable from a real one downstream. This
 *   carries only what is known: the configured value and its resolved href
 *   once a discovery pass has confirmed it exists.
 */
final readonly class EditableCalendar
{
    public function __construct(
        /** The configured value, verbatim — href or bare name. Empty = read-only. */
        public string $configured,
    ) {
    }

    /**
     * Whether writes are available at all.
     *
     * False does not mean "a write will fail". It means the write tools are
     * not registered: a deployment that has not opted in has no mutation verb
     * for a model to find.
     */
    public function isConfigured(): bool
    {
        return '' !== trim($this->configured);
    }

    /**
     * Whether this is the calendar a write may target.
     *
     * Matched the same way `CALDAV_CALENDARS` is — full href (`/user/work/`)
     * or bare trailing segment (`work`) — so an operator who wrote one will
     * not be surprised by the other. Comparison happens on the *discovered*
     * href rather than on the configured string, so `/lyra/work/`, `/work/`
     * and `work` all agree.
     */
    public function matches(CalendarInfo $calendar): bool
    {
        return $this->matchesHref($calendar->href);
    }

    /**
     * The same question, asked of an href.
     *
     * Separate from `matches()` because the narrowing of `readonly` happens
     * while a `CalendarInfo` is being *constructed*, so there is no object to
     * pass yet — and building a throwaway one just to ask would be a lie about
     * what is known at that point.
     */
    public function matchesHref(string $href): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        return self::hrefMatches($href, $this->configured);
    }

    /**
     * Whether a discovered href is the one an operator named.
     *
     * Deliberately shared with the read allowlist rather than reimplemented:
     * two matchers that "obviously" behave the same are two matchers that
     * eventually do not, and the pair of them guard opposite ends of the same
     * boundary.
     */
    public static function hrefMatches(string $href, string $wanted): bool
    {
        $trimmed = rtrim($href, '/');
        $wantedTrimmed = rtrim(trim($wanted), '/');

        if ('' === $wantedTrimmed) {
            return false;
        }

        if ($trimmed === $wantedTrimmed) {
            return true;
        }

        // `work` should match `/lyra/work/` without matching `/lyra/homework/`.
        return basename($trimmed) === $wantedTrimmed;
    }
}
