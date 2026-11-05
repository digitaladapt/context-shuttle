<?php

declare(strict_types=1);

namespace App\Messages;

/**
 * The one place a sender of record is decided.
 *
 * A sender is an address, not prose: trimmed, UTF-8-scrubbed, at most 64
 * bytes cut on a character boundary. It is a *claim*, not a credential —
 * this deployment authenticates one actor for the whole gateway, so the
 * store cannot check who is speaking. v1 accepts and documents that hole
 * (the design note's identity section); the fix is per-participant keys,
 * at which point the claim stops being a parameter and this class derives
 * the sender server-side from the key that made the request.
 *
 * A blank claim means "the deployment's configured actor" — the name
 * MESSAGES_ACTOR gives the resident this gateway serves.
 */
final readonly class Sender
{
    private const int MAX_BYTES = 64;

    private function __construct(
        public string $value,
    ) {
    }

    /**
     * The caller's claim, or `$default` when it named nothing worth
     * standing behind (absent, blank, whitespace-only).
     */
    public static function from(?string $claimed, string $default): self
    {
        $who = trim((string) $claimed);

        return new self('' !== $who ? self::bounded($who) : self::bounded($default));
    }

    /**
     * Scrub, then cap on a character boundary — the same two guarantees the
     * body gets, at a quarter of the budget, because an address is shorter
     * than what it points at.
     */
    private static function bounded(string $who): string
    {
        $scrubbed = 1 === preg_match('//u', $who) ? $who
            : (\function_exists('mb_scrub') ? mb_scrub($who, 'UTF-8') : (string) iconv('UTF-8', 'UTF-8//IGNORE', $who));

        if (\strlen($scrubbed) <= self::MAX_BYTES) {
            return $scrubbed;
        }

        $cut = substr($scrubbed, 0, self::MAX_BYTES);
        for ($dropped = 0; $dropped < 3 && '' !== $cut && 1 !== preg_match('//u', $cut); ++$dropped) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }
}
