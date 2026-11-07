<?php

declare(strict_types=1);

namespace App\Messages;

use InvalidArgumentException;

/**
 * The one place a message body is made storable.
 *
 * A message body is what a context window is made of, twice over — the sender
 * generated it from a context, and the reader will read it into one — so the
 * guarantees here are the ones that keep both sides honest:
 *
 * - **Well-formed UTF-8, always.** Malformed sequences scrub to U+FFFD rather
 *   than reaching the store, because the store is read back through JSON and
 *   a broken byte would surface as an opaque serialization error at read
 *   time, far from the write that caused it.
 * - **No control characters except newline and tab.** A carriage return or an
 *   ANSI escape in a body is not meaning anyone sent; it is damage riding
 *   along, and it would render as damage on every read.
 * - **Bounded.** At most 4000 bytes, cut on a character boundary so a cap can
 *   never itself produce the malformed sequence the scrub just removed.
 * - **Non-empty after all of that.** A body that is blank is not a message;
 *   storing one would make "stored" mean less than stored, the same refusal
 *   the memory family makes of an empty write.
 *
 * Everything here is deliberately a *normalize-then-check* pipeline rather
 * than check-only: a caller that sends 4002 bytes or a stray control
 * character gets a stored, cleaned message and a `truncated` flag on the
 * result, not an error it cannot act on. The only refusal is the one with no
 * honest alternative — nothing left to store.
 */
final class MessageBody
{
    /**
     * The store's hard ceiling on a body, in bytes. The YAML tool definitions
     * advertise the same number as `maxLength` (which counts characters, so
     * a multi-byte body can exceed the advertised length and be cut here —
     * the cut is the boundary that is enforced, the advertisement is the
     * guidance a model reads).
     */
    public const int MAX_BYTES = 4000;

    /**
     * @return array{body: string, truncated: bool} the storable body and
     *                                              whether anything was cut
     *
     * @throws InvalidArgumentException when the body is empty after scrubbing
     */
    public static function normalize(string $raw): array
    {
        $scrubbed = self::scrub($raw);

        // Strip control characters except \n and \t. \r is damage in a world
        // where every reader normalizes to \n; the rest (C0, C1, DEL) is not
        // meaning a sender meant to send.
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $scrubbed) ?? '';
        // C1 controls (U+0080–U+009F) survive the \x pattern above on the
        // UTF-8 codepoint axis; take those too.
        $cleaned = preg_replace('/[\x{0080}-\x{009F}]/u', '', $cleaned) ?? '';

        $trimmed = trim($cleaned);

        if ('' === $trimmed) {
            throw new InvalidArgumentException('A message body is required — there is nothing to store.');
        }

        $truncated = \strlen($trimmed) > self::MAX_BYTES;

        return [
            'body' => $truncated ? self::cut($trimmed) : $trimmed,
            'truncated' => $truncated,
        ];
    }

    /**
     * Replace malformed UTF-8 sequences with U+FFFD.
     *
     * The `u` modifier makes PCRE reject a malformed subject, so an empty
     * pattern is a cheap whole-string validation; mb_scrub is preferred when
     * present (ext-mbstring is in this project's requirements) with iconv as
     * the documented fallback.
     */
    private static function scrub(string $text): string
    {
        if (1 === preg_match('//u', $text)) {
            return $text;
        }

        if (\function_exists('mb_scrub')) {
            return mb_scrub($text, 'UTF-8');
        }

        return (string) iconv('UTF-8', 'UTF-8//IGNORE', $text);
    }

    /**
     * At most MAX_BYTES bytes, cut on a character boundary.
     *
     * `substr()` counts bytes and lands wherever the budget says; if a
     * multi-byte character straddles the offset the result ends mid-sequence
     * and is no longer valid UTF-8 — which the scrub just promised it would
     * never be. So the cut walks back over continuation bytes (10xxxxxx, at
     * most three) until the string validates again.
     */
    private static function cut(string $text): string
    {
        $cut = substr($text, 0, self::MAX_BYTES);

        for ($dropped = 0; $dropped < 3 && '' !== $cut && 1 !== preg_match('//u', $cut); ++$dropped) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }
}
