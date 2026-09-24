<?php

declare(strict_types=1);

namespace App\Calendar\Paging;

use InvalidArgumentException;

/**
 * An opaque position in a listing.
 *
 * A page cursor rather than an offset or a date, because that is what the
 * other tools in this repo already speak and what MCP itself uses for list
 * methods. Two properties matter:
 *
 * - It anchors to the **last row's sort position**, not to a row count. An
 *   event inserted earlier in the range between two pages therefore cannot
 *   cause a later page to skip or repeat rows the way an offset would.
 * - It is **opaque**: base64url of a small JSON payload. Callers echo it
 *   back rather than constructing one, so the encoding can change later
 *   without breaking anything.
 *
 * It carries no credentials and no server state — just a position — so
 * nothing needs to be stored or expired.
 */
final readonly class Cursor
{
    public function __construct(
        public string $sortKey,
        public string $id,
    ) {
    }

    /**
     * Encode for the wire, base64url without padding so it survives being
     * pasted around as a query value.
     */
    public function encode(): string
    {
        $json = json_encode(
            ['k' => $this->sortKey, 'i' => $this->id],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        );

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decode a cursor supplied by a caller.
     *
     * An unparseable cursor is a normal tool error naming the parameter, not
     * a silent restart from the beginning: quietly re-running from row zero
     * would look like success while handing back duplicates.
     */
    public static function decode(string $value): self
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = (4 - \strlen($normalized) % 4) % 4;
        $decoded = base64_decode($normalized.str_repeat('=', $padding), true);

        if (false === $decoded) {
            throw new InvalidArgumentException('cursor is not a valid paging token; pass back the value of next_cursor verbatim, or omit it entirely to start from the beginning.');
        }

        try {
            $payload = json_decode($decoded, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('cursor is not a valid paging token; pass back the value of next_cursor verbatim, or omit it entirely to start from the beginning.');
        }

        if (!\is_array($payload) || !isset($payload['k'], $payload['i']) || !\is_string($payload['k']) || !\is_string($payload['i'])) {
            throw new InvalidArgumentException('cursor is not a valid paging token; pass back the value of next_cursor verbatim, or omit it entirely to start from the beginning.');
        }

        return new self($payload['k'], $payload['i']);
    }
}
