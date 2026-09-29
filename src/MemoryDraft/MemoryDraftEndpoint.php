<?php

declare(strict_types=1);

namespace App\MemoryDraft;

use RuntimeException;

/**
 * The memory-draft instance this deployment talks to, if it was named one.
 *
 * The URL is both the address **and** the opt-in. memory-draft has no
 * authentication and no per-operation permission model — anyone who can reach
 * the instance can read and write it — so there is nothing finer to gate on,
 * and pretending otherwise would be a boundary that does not exist. Naming a
 * store is therefore the whole decision: with `MEMORY_DRAFT_URL` empty the
 * memory tools are not registered at all, and with it set every one of them
 * is served.
 *
 * This object is a service because two places need that same answer — the
 * gate deciding whether the tools exist, and the tool deciding where to send
 * a request. Two copies of the URL would be two chances for visibility and
 * behaviour to disagree: a deployment could show a `memory_recall` tool that
 * posts to a different store than the gate believes it checked.
 *
 * Like `CALDAV_URL`, the URL is **not** probed at boot: a memory store being
 * down, or a value with a typo in it, must never stop context-shuttle from
 * starting. A missing store hides the tools; a malformed one surfaces on
 * first use with a message naming the variable, because "you pointed me at a
 * broken address" is configuration to fix, not absence to be silent about.
 */
final readonly class MemoryDraftEndpoint
{
    public function __construct(
        /** The configured value, verbatim. Empty = the memory tools do not exist. */
        public string $configured,
    ) {
    }

    /**
     * Whether a store has been named at all.
     *
     * False means "this deployment has no memory tools" — not "a memory call
     * would fail". A whitespace-only value is a plausible typo in an env
     * file, and treating it as configured would serve a family of tools that
     * cannot work.
     */
    public function isConfigured(): bool
    {
        return '' !== trim($this->configured);
    }

    /**
     * The base URL every memory request is built on, with any trailing slash
     * removed so paths join cleanly.
     *
     * @throws RuntimeException when no store is configured, or the value is
     *                          not an http(s) URL — both with a message
     *                          naming MEMORY_DRAFT_URL to set or fix
     */
    public function baseUrl(): string
    {
        $url = trim($this->configured);

        if ('' === $url) {
            throw new RuntimeException('MEMORY_DRAFT_URL is not configured. Set it in .env.local to the base URL of your memory-draft instance (e.g. https://memory.example.com).');
        }

        if (!preg_match('#^https?://#', $url)) {
            throw new RuntimeException(\sprintf('MEMORY_DRAFT_URL must start with http:// or https:// (got "%s").', $url));
        }

        return rtrim($url, '/');
    }
}
