<?php

declare(strict_types=1);

namespace App\Tool\MemoryDraft;

use App\MemoryDraft\MemoryDraftEndpoint;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Memory tools backed by a memory-draft instance.
 *
 * memory-draft is a keyword-addressed memory store: sentences per key, with
 * revisions for concurrency and an explicit miss (near-miss suggestions
 * included) when a key is not there. This class exposes all of it — recall,
 * remember, keys, stats, forget — because that is the whole API surface the
 * service has, and all five answer questions a model actually asks: "what do
 * I know about X", "remember this", "what is there", "how much is there",
 * "forget this".
 *
 * Every operation is a thin transport over one HTTP endpoint. The rules that
 * matter — a miss is 200 with suggestions, trimming demotes rather than
 * deletes, a stale write is kept and flagged — live in memory-draft and are
 * passed through untouched. Re-deciding any of them here would be a second
 * implementation that can disagree with the first, and the caller would have
 * no way to tell which one answered.
 *
 * Two things this tool does decide, because they are about the *transport*:
 *
 * - **Argument shapes mirror the wire, not the PHP API.** Each tool takes the
 *   fields the endpoint takes (`key` plus per-operation options, `sentences`,
 *   `limit`, `pattern`) rather than a nested DTO, so what a caller sends is
 *   what reaches memory-draft.
 * - **Clearing a value that means "empty" is refused where an empty value
 *   would be a silent no-op.** A `remember` with nothing to store, or a
 *   `forget` with no key, is a caller mistake: the write path in memory-draft
 *   answers an empty write with `empty: true` rather than an error, and a
 *   delete of nothing is a 404. Both would look like success from a model's
 *   side, so they are refused before the request is made, with a message that
 *   says what to pass instead.
 */
final class MemoryDraftTool
{
    private const TIMEOUT = 10;

    /**
     * Ceiling on a non-JSON error body's summary. A tool result is what a
     * context window is made of, so an upstream error page is quoted only
     * far enough to identify it.
     */
    private const MAX_ERROR_DETAIL_CHARS = 200;

    public function __construct(
        private HttpClientInterface $httpClient,
        private MemoryDraftEndpoint $endpoint,
    ) {
    }

    /**
     * Look up one or more keywords — or ask what was written recently.
     *
     * The batch is sent as one request: memory-draft resolves each keyword
     * independently, so one miss does not spoil the batch, and the response
     * arrives with `hits` and `misses` separated. A miss is a 200 carrying
     * `suggestions`, never an error — the suggestions are the point of the
     * service, and a tool that turned "I do not know X" into a failure would
     * throw them away.
     *
     * `depth` is omitted when the caller does not give one, so the store's own
     * default applies. Pinned sentences are returned in full regardless of that
     * default: a model that asks for a keyword by name gets the durable facts
     * about it without having to know to raise a number first.
     *
     * **With `$latest` set and no keys, this asks the other question** — *what
     * was written most recently?* — which is the only recall a caller can make
     * before it knows any key names, and therefore the useful way to open a
     * session. `latest` is forwarded as the **boolean `true`**, never a number:
     * `true` means "the count is yours to choose", so the store owns that
     * default and this tool never hardcodes one. The answer says so in its first
     * line, because a default mistaken for a deliberate recall is the failure
     * mode here.
     *
     * Keys and `latest` compose: naming a key *and* asking for recency is one
     * request, and the named hits lead. A key that is both named and recent is
     * returned once, as the named hit.
     *
     * @param list<string> $keys   keywords to look up. May be empty **only**
     *                             when `$latest` is set — an empty recall is a
     *                             caller bug, and the store refuses one
     * @param int|null     $depth  unpinned sentences per key, at least 1;
     *                             omitted to use the store's default
     * @param bool|null    $latest also show the most recently written keys; on
     *                             its own it needs no keys at all
     *
     * @return array<string, mixed> the service's own `{hits, misses}` payload,
     *                              carrying a `latest` block when recency was asked for
     */
    public function recall(array $keys, ?int $depth = null, ?bool $include_cold = null, ?bool $latest = null): array
    {
        $queries = [];

        foreach ($this->normalizeKeys($keys, 'keys', allowEmpty: true) as $key) {
            $query = ['key' => $key];

            if (null !== $depth) {
                $query['depth'] = $this->validateDepth($depth);
            }

            if (null !== $include_cold) {
                $query['includeCold'] = $include_cold;
            }

            $queries[] = $query;
        }

        // `true` rather than a count, deliberately. The tool has one dial for
        // this and it is a boolean, so the model never chooses a number it has
        // no basis to judge — and the store's default can change without a
        // release here.
        if (true === $latest) {
            $queries[] = ['latest' => true];
        }

        if ([] === $queries) {
            // The two ways to ask nothing. Refused here rather than sent, for
            // the same reason a blank key is: the store answers an empty batch
            // with a 422, and "no keys and no recency request" is a caller
            // mistake whose fix is a one-line change at the call site.
            throw new InvalidArgumentException('recall needs at least one key, or latest: true to ask what was written most recently. Use memory_keys to see what is stored.');
        }

        $payload = $this->request('POST', '/api/recall', ['queries' => $queries]);

        if (!isset($payload['hits']) || !\is_array($payload['hits'])) {
            throw new RuntimeException('Unexpected response shape from memory-draft: missing "hits" array.');
        }

        return $payload;
    }

    /**
     * Store sentences under one or more keys.
     *
     * A batch is one transaction upstream: either every item lands or none
     * does, and the response reports what happened per item — including
     * sentences demoted to cold, retired by a `replace`, or purged past the
     * cold cap. That reporting is why the write result is passed through
     * whole rather than reduced to a boolean: "nothing was lost silently" is
     * the service's central promise, and it is only observable in the detail.
     *
     * @param string      $key       the keyword to write under
     * @param string      $sentences prose to store; memory-draft splits it into
     *                               sentences, keeping abbreviations, initials and
     *                               decimals intact ("Dr. Smith", "3.50")
     * @param string|null $mode      `append` (default) or `replace`;
     *                               `replace` retires the key's current
     *                               sentences — they are kept in cold
     *                               storage, not destroyed
     * @param bool|null   $pin       `true` pins these sentences so trimming
     *                               cannot demote them; `false` un-pins them;
     *                               omit to leave any existing pin exactly as
     *                               it is. Omitting is not "un-pin": this
     *                               value is forwarded only when you set it,
     *                               and re-stating a fact therefore never
     *                               un-pins it.
     * @param int|null    $revision  the revision a recall returned, when
     *                               you read before writing. Passing it
     *                               lets memory-draft detect a concurrent
     *                               write; the write still lands, flagged
     *                               `backfilled`. Omit if you did not read first.
     *
     * @return array<string, mixed> the service's own `{results, purged}` payload
     */
    public function remember(
        string $key,
        string $sentences,
        ?string $mode = null,
        ?bool $pin = null,
        ?int $revision = null,
    ): array {
        $key = $this->validateKey($key, 'key');
        $mode = $this->validateMode($mode);

        // memory-draft accepts a write with nothing in it and reports
        // `empty: true` rather than an error — visible in the detail, but a
        // caller reading the success-shaped result can miss it. Refusing here
        // means "stored" always means stored.
        if ('' === trim($sentences)) {
            throw new InvalidArgumentException('sentences must not be empty. memory-draft accepts a write with nothing in it (it reports `empty: true` rather than an error), so this is refused here instead of being reported as success.');
        }

        $item = [
            'key' => $key,
            'sentences' => trim($sentences),
        ];

        if (null !== $mode) {
            $item['mode'] = $mode;
        }

        if (null !== $pin) {
            $item['pin'] = $pin;
        }

        if (null !== $revision) {
            $item['revision'] = $this->validateRevision($revision);
        }

        $payload = $this->request('POST', '/api/remember', ['items' => [$item]]);

        if (!isset($payload['results']) || !\is_array($payload['results'])) {
            throw new RuntimeException('Unexpected response shape from memory-draft: missing "results" array.');
        }

        return $payload;
    }

    /**
     * Enumerate the keyspace.
     *
     * The highest-value read in the store and the one that replaces guessing
     * key names — a keyword store punishes a guessed key with a miss, which
     * looks exactly like "there is nothing to know". Every row carries its
     * revision, aliases, and counts per tier.
     *
     * @param string|null $pattern substring to filter keys by; omit for everything
     * @param int|null    $limit   maximum keys to return, 1-1000
     *                             (memory-draft's default: 200)
     *
     * @return array<string, mixed> `{keys, count}` — the service returns a
     *                              bare list, so this wraps it with a count
     *                              for symmetry with the other list-shaped
     *                              results here
     */
    public function keys(?string $pattern = null, ?int $limit = null): array
    {
        $query = [];

        $pattern = $pattern ?? '';

        if ('' !== trim($pattern)) {
            $query['pattern'] = trim($pattern);
        }

        if (null !== $limit) {
            $query['limit'] = $this->validateLimit($limit, 1, 1000);
        }

        $payload = $this->request('GET', '/api/keys', $query);

        if (!array_is_list($payload)) {
            throw new RuntimeException('Unexpected response shape from memory-draft: expected a list of keys.');
        }

        return [
            'keys' => $payload,
            'count' => \count($payload),
        ];
    }

    /**
     * Store-wide counts and the active trimming budgets.
     *
     * `caps` is what makes the counts interpretable: it says how many
     * unpinned sentences a key keeps hot before demotion, and how many cold
     * ones it keeps before the only deletion this store performs.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $payload = $this->request('GET', '/api/stats', []);

        if (!isset($payload['keys']) || !isset($payload['caps'])) {
            throw new RuntimeException('Unexpected response shape from memory-draft: missing "keys" or "caps".');
        }

        return $payload;
    }

    /**
     * Permanently delete a key and every sentence under it.
     *
     * There is no undo, and this is the only operation here that destroys
     * anything — every other path either appends, demotes, or retires.
     * memory-draft answers 404 when nothing matched; that is turned into an
     * "is not there" message, because from a caller's side "nothing named
     * that" and "I deleted nothing" are the same answer and only one of them
     * is useful.
     *
     * @param string $key the keyword to delete
     *
     * @return array<string, mixed> `{key, deleted}` — the canonical key and
     *                              the number of sentences removed
     */
    public function forget(string $key): array
    {
        $key = $this->validateKey($key, 'key');

        $payload = $this->request('DELETE', '/api/keys/'.rawurlencode($key), [], allowNotFound: true);

        // memory-draft answers 404 with `{"error": ..., "deleted": 0}` when
        // nothing matched. From a caller's side "nothing is stored under that
        // key" is the useful rendering of that; `deleted: 0` on a 200 would
        // be the outcome it looks like.
        if (isset($payload['error']) || 0 === ($payload['deleted'] ?? null)) {
            throw new RuntimeException(\sprintf('memory-draft has no key matching "%s", so nothing was deleted.', $key));
        }

        return $payload;
    }

    /**
     * One request, with the failure modes this project's tools share.
     *
     * The translation is deliberately thin, because the interesting answers
     * are the service's own: a miss is a 200 with `suggestions`, and a stale
     * write is a 200 with `backfilled`. Only real failures are errors here.
     *
     * @param array<string, mixed> $body          JSON body for POST, or query parameters for GET
     * @param bool                 $allowNotFound whether a 404 is an answer the
     *                                            caller handles (`forget`) rather than
     *                                            a sign the URL is wrong
     *
     * @return array<array-key, mixed> the decoded payload; a list for the
     *                                 endpoints that answer with one, a map
     *                                 otherwise — deliberately not narrowed,
     *                                 because which one it is is the service's
     *                                 answer, and the callers check
     */
    private function request(string $method, string $path, array $body, bool $allowNotFound = false): array
    {
        $baseUrl = $this->endpoint->baseUrl();

        $options = [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => self::TIMEOUT,
        ];

        if ('POST' === $method) {
            $options['json'] = $body;
        } elseif ([] !== $body) {
            $options['query'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $baseUrl.$path, $options);

            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(\sprintf('Could not reach memory-draft at %s: %s', $baseUrl, $e->getMessage()), 0, $e);
        }

        if (404 === $status) {
            // A 404 on a path the API is known to serve means the address is
            // not a memory-draft instance (or has no HTTP API), which is a
            // configuration problem worth naming rather than passing through
            // as an empty result. The one exception is a caller that treats
            // "nothing matched" as an answer (`forget`), which reads the body
            // below.
            if (!$allowNotFound) {
                throw new RuntimeException(\sprintf('memory-draft answered HTTP 404 for %s. Check that MEMORY_DRAFT_URL points at a memory-draft instance that serves its HTTP API.', $path));
            }
        } elseif ($status >= 400) {
            $detail = '';

            try {
                // The error envelope is JSON (`{"error": ...}` or a Symfony
                // validation problem document), so it is worth decoding; the
                // raw body is the fallback for anything else.
                $decoded = $response->toArray(false);

                $detail = $this->describeError($decoded);
            } catch (Throwable) {
                try {
                    $detail = $this->summarize($response->getContent(false));
                } catch (Throwable) {
                    // status already captured; detail is best-effort context
                }
            }

            throw new RuntimeException(\sprintf('memory-draft returned HTTP %d for %s%s', $status, $path, '' === $detail ? '.' : ': '.$detail));
        }

        try {
            // `false` because a 404 on the `allowNotFound` path is a valid
            // answer whose body still has to be read.
            $payload = $response->toArray(false);
        } catch (Throwable $e) {
            throw new RuntimeException(\sprintf('memory-draft returned a body that is not JSON for %s: %s', $path, $e->getMessage()), 0, $e);
        }

        return $payload;
    }

    /**
     * A human-readable reason from an error body.
     *
     * Symfony's `#[MapRequestPayload]` validation failure is a problem
     * document whose useful part is `detail`; memory-draft's own errors are
     * `{"error": "..."}`. Anything else is summarized rather than dumped, so
     * a proxy's HTML error page cannot flood a context window through a tool
     * result.
     *
     * @param array<string, mixed> $decoded
     */
    private function describeError(array $decoded): string
    {
        foreach (['error', 'detail', 'title'] as $key) {
            if (isset($decoded[$key]) && \is_string($decoded[$key]) && '' !== trim($decoded[$key])) {
                return trim($decoded[$key]);
            }
        }

        return '';
    }

    /**
     * A bounded, single-line summary of a body that is not JSON.
     *
     * A proxy's HTML error page must not arrive as tool output: it is
     * unbounded, and a tool result is exactly what a context window is made
     * of. Tags are dropped rather than echoed so the useful part of the page
     * ("502 Bad Gateway") survives while the markup does not, and a cap
     * keeps a page that is all prose from filling the result.
     */
    private function summarize(string $body): string
    {
        $summary = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));

        if (mb_strlen($summary) <= self::MAX_ERROR_DETAIL_CHARS) {
            return $summary;
        }

        return mb_substr($summary, 0, self::MAX_ERROR_DETAIL_CHARS).'…';
    }

    /**
     * Validate and normalize a key.
     *
     * A blank key is refused rather than sent: memory-draft would resolve it
     * to an empty canonical key and answer `misses` (recall) or create a key
     * named `""` (write), and neither is what the caller meant. The key is
     * kept in the spelling the caller used, because memory-draft records
     * aliases from exactly that spelling — normalizing here would discard the
     * alias the store is designed to learn.
     */
    private function validateKey(string $key, string $field): string
    {
        $key = trim($key);

        if ('' === $key) {
            throw new InvalidArgumentException(\sprintf('%s must not be empty. Use memory_keys to see what is stored.', $field));
        }

        return $key;
    }

    /**
     * Non-string entries are refused rather than cast, because the pipeline's
     * JSON Schema already guarantees strings (`items: {type: string}`) — so a
     * non-string here means the tool was called around the pipeline, and
     * coercing it would hide that.
     *
     * `$allowEmpty` exists for `recall` alone, where an empty list is legitimate
     * provided recency was asked for instead. The emptiness rule is enforced by
     * the caller, on the *request* rather than on this list, because "no keys"
     * is only a mistake when it is the whole request.
     *
     * @param array<mixed> $keys
     *
     * @return list<string>
     */
    private function normalizeKeys(array $keys, string $field, bool $allowEmpty = false): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            if (!\is_string($key)) {
                throw new InvalidArgumentException(\sprintf('%s must be a list of strings.', $field));
            }

            $normalized[] = $this->validateKey($key, $field);
        }

        if ([] === $normalized && !$allowEmpty) {
            throw new InvalidArgumentException(\sprintf('%s must contain at least one key.', $field));
        }

        return $normalized;
    }

    private function validateDepth(int $depth): int
    {
        if ($depth < 1) {
            throw new InvalidArgumentException('depth must be at least 1.');
        }

        return $depth;
    }

    private function validateRevision(int $revision): int
    {
        if ($revision < 1) {
            throw new InvalidArgumentException('revision must be at least 1. Pass the revision a recall returned, or omit it if you did not read first.');
        }

        return $revision;
    }

    private function validateLimit(int $limit, int $min, int $max): int
    {
        if ($limit < $min || $limit > $max) {
            throw new InvalidArgumentException(\sprintf('limit must be between %d and %d.', $min, $max));
        }

        return $limit;
    }

    private function validateMode(?string $mode): ?string
    {
        if (null === $mode) {
            return null;
        }

        $mode = trim($mode);

        if (!\in_array($mode, ['append', 'replace'], true)) {
            throw new InvalidArgumentException('mode must be "append" or "replace".');
        }

        return $mode;
    }
}
