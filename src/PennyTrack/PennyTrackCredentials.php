<?php

declare(strict_types=1);

namespace App\PennyTrack;

use RuntimeException;

/**
 * The penny-track API keys this deployment may present.
 *
 * penny-track distinguishes two kinds of key, and the distinction is the whole
 * permission model on its side: a **read-only** key authenticates `GET`/`HEAD`
 * only, and anything that mutates the ledger (POST/PUT/DELETE) requires a
 * **full-access** one. Both travel in the same `X-API-Key` header, so the only
 * way this deployment can be sure a key may write is to be told to use one that
 * may.
 *
 * That is why there are two configured values rather than one. Holding a key is
 * not the same as knowing what it is allowed to do — hashes are all penny-track
 * stores, and it offers no probe short of attempting a write — so a single
 * `PENNYTRACK_API_KEY` could not answer "may this deployment create a
 * transaction?" without guessing. Two names answer it by construction: the read
 * key is what the read tool uses, and naming a write key **is** the opt-in for
 * the write tool's existence (see `PennyTrackWriteGate`).
 *
 * The keys are kept verbatim and only trimmed, for the same reason
 * `MemoryDraftEndpoint` keeps its URL that way: normalising a secret is a fine
 * way to turn a working credential into a broken one.
 */
final readonly class PennyTrackCredentials
{
    public function __construct(
        /** The read key, verbatim. Empty = the read tool explains itself when called. */
        public string $readKey,
        /** The write key, verbatim. Empty = there is no write tool at all. */
        public string $writeKey,
    ) {
    }

    /**
     * Whether a read key has been named.
     *
     * False does not hide `get_transactions`: the read tools elsewhere stay
     * listed on an unconfigured deployment and explain themselves, because
     * "list my transactions" has an answer beyond "configure me".
     */
    public function hasReadKey(): bool
    {
        return '' !== trim($this->readKey);
    }

    /**
     * Whether a write key has been named — the opt-in for the write tool.
     *
     * False means the create tool is not registered: a deployment that has not
     * opted in has no mutation verb for a model to find, which is the stronger
     * statement an operator can verify by reading `tools/list` rather than by
     * reading a refusal.
     */
    public function hasWriteKey(): bool
    {
        return '' !== trim($this->writeKey);
    }

    /**
     * @throws RuntimeException naming PENNYTRACK_API_KEY when no read key is set
     */
    public function readKeyOrFail(): string
    {
        $key = trim($this->readKey);

        if ('' === $key) {
            throw new RuntimeException('PENNYTRACK_API_KEY is not configured. Create a read-only API key in penny-track (bin/console app:api-key:create --read-only) and set PENNYTRACK_API_KEY in .env.local.');
        }

        return $key;
    }

    /**
     * @throws RuntimeException naming PENNYTRACK_WRITE_API_KEY when no write key
     *                          is set — which is a deployment whose write tool
     *                          does not exist, so reaching this is a wiring
     *                          mistake rather than something a caller did
     */
    public function writeKeyOrFail(): string
    {
        $key = trim($this->writeKey);

        if ('' === $key) {
            throw new RuntimeException('PENNYTRACK_WRITE_API_KEY is not configured, so this deployment cannot create transactions. Set it to a full-access API key in penny-track (bin/console app:api-key:create) and set PENNYTRACK_WRITE_API_KEY in .env.local. A read-only key will not work: penny-track refuses to create receipts with one.');
        }

        return $key;
    }
}
