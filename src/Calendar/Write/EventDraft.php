<?php

declare(strict_types=1);

namespace App\Calendar\Write;

/**
 * What a caller wants an event to be, expressed the way the tools take it.
 *
 * Every field is nullable and `null` means "the caller did not mention this".
 * What that implies depends on the operation, and the difference is the point:
 *
 * - on **create**, an unmentioned field is simply absent;
 * - on **update**, an unmentioned field is left alone.
 *
 * For the text fields, an empty string is a third state and means "make this
 * empty" — the only way to remove a location, since omitting it would mean
 * "leave it as it is". `summary` has no empty string: an event with no title
 * is not a thing a caller can ask for.
 *
 * Times are strings because the caller's format is not an instant. Parsing
 * (and refusing the formats that cannot be meant) is `When`'s job, so the
 * refusal happens once rather than per field.
 */
final readonly class EventDraft
{
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $location = null,
        public ?string $start = null,
        public ?string $end = null,
    ) {
    }

    /**
     * Whether the caller asked for nothing at all.
     *
     * Used to refuse a no-op update: a write that changes nothing still costs
     * a round trip, still moves the etag, and still makes a shared calendar
     * look edited to anyone watching it.
     */
    public function isEmpty(): bool
    {
        return null === $this->summary
            && null === $this->description
            && null === $this->location
            && null === $this->start
            && null === $this->end;
    }
}
