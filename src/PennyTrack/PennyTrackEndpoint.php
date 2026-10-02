<?php

declare(strict_types=1);

namespace App\PennyTrack;

use RuntimeException;

/**
 * The penny-track instance this deployment talks to, if it was named one.
 *
 * This exists because more than one tool now needs the same answer. The read
 * tool, the write tool and anything added later all build requests against one
 * base URL, and a second copy of the resolution rules would be a second chance
 * for two tools to disagree about where the ledger is — the same argument that
 * makes `MemoryDraftEndpoint` and `EditableCalendar` services.
 *
 * Like `CALDAV_URL` and `MEMORY_DRAFT_URL`, the URL is **not** probed at boot:
 * a ledger being down, or a value with a typo in it, must never stop
 * context-shuttle from starting. A malformed value therefore surfaces on first
 * use, with a message naming the variable, rather than at boot.
 */
final readonly class PennyTrackEndpoint
{
    public function __construct(
        /** The configured value, verbatim. Empty = nothing here is configured. */
        public string $configured,
    ) {
    }

    /**
     * Whether an instance has been named at all.
     *
     * False means "this deployment has no ledger configured" — not "a request
     * would fail". A whitespace-only value is a plausible typo in an env file,
     * and treating it as configured would send requests to nowhere.
     */
    public function isConfigured(): bool
    {
        return '' !== trim($this->configured);
    }

    /**
     * The base URL every penny-track request is built on, with any trailing
     * slash removed so paths join cleanly.
     *
     * @throws RuntimeException when no instance is configured, or the value is
     *                          not an http(s) URL — both with a message naming
     *                          PENNYTRACK_URL to set or fix
     */
    public function baseUrl(): string
    {
        $url = trim($this->configured);

        if ('' === $url) {
            throw new RuntimeException('PENNYTRACK_URL is not configured. Set it in .env.local to the base URL of your penny-track instance (e.g. https://penny.example.com).');
        }

        if (!preg_match('#^https?://#', $url)) {
            throw new RuntimeException(\sprintf('PENNYTRACK_URL must start with http:// or https:// (got "%s").', $url));
        }

        return rtrim($url, '/');
    }
}
