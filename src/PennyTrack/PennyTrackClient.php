<?php

declare(strict_types=1);

namespace App\PennyTrack;

use App\PennyTrack\Domain\TransactionRefused;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * The one place that talks to penny-track.
 *
 * Both tools build on this so that "where is the ledger", "which key do we
 * present" and "what does a failure look like" are each answered once. A
 * second copy of the error translation would be a second chance for the same
 * upstream condition to read differently depending on which tool hit it.
 *
 * The transport is deliberately thin and the payloads are passed through
 * whole, because the interesting answers are penny-track's own: a receipt as
 * it was stored, and a paginated `{data, meta}` listing. Re-shaping either
 * here would be a second implementation that can disagree with the first.
 *
 * What *is* translated is the set of failures a caller can act on:
 *
 *  - a key that was refused, naming the variable the operator has to fix. The
 *    read and write paths name *different* variables, because they present
 *    different keys and only one of them will fix the problem.
 *  - a `409` from the create path, which is penny-track's own duplicate guard
 *    and is surfaced as a refusal rather than as a bare HTTP status.
 *  - a `422` from the create path, whose body is a per-field error map, so a
 *    caller is told *which* field the ledger rejected and why.
 */
final class PennyTrackClient
{
    private const TIMEOUT = 10;

    /**
     * Ceiling on a non-JSON error body's summary.
     *
     * A tool result is what a context window is made of, so a proxy's HTML
     * error page is quoted only far enough to identify it.
     */
    private const MAX_ERROR_DETAIL_CHARS = 200;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PennyTrackEndpoint $endpoint,
        private readonly PennyTrackCredentials $credentials,
    ) {
    }

    /**
     * One page of the ledger.
     *
     * The query is built by the caller rather than by a typed signature,
     * because the two callers ask genuinely different questions and both are
     * already expressible: the read tool filters on inclusive `YYYY-MM-DD`
     * dates, while the create tool's duplicate scan needs a *day* expressed as
     * two instants (see `CreateTransactionTool`, which explains why a single
     * date is not enough for that).
     *
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed> penny-track's own `{data, meta}` payload
     */
    public function listReceipts(array $query): array
    {
        $url = $this->endpoint->baseUrl().'/api/receipts';

        $response = $this->send('GET', $url, [
            'headers' => $this->headers($this->readKey()),
            'query' => $query,
            'timeout' => self::TIMEOUT,
        ], $url);

        $payload = $this->decode($response, $url);

        if (!isset($payload['data']) || !\is_array($payload['data'])) {
            throw new RuntimeException('Unexpected response shape from penny-track: missing "data" array.');
        }

        return $payload;
    }

    /**
     * The distinct businesses already in the ledger, as penny-track spells them.
     *
     * @return list<string>
     */
    public function businesses(): array
    {
        return $this->stringList('/api/autocomplete/businesses', $this->readKey());
    }

    /**
     * The distinct categories already in the ledger, as penny-track spells them.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        return $this->stringList('/api/autocomplete/categories', $this->readKey());
    }

    /**
     * Log one receipt.
     *
     * `created_at` is sent as a wall-clock local value with no offset, which is
     * the shape penny-track's own entry form produces (`datetime-local`) and the
     * shape its storage and its date filtering both assume. Sending an offset
     * would be lossy here and would make the value disagree with the ledger's
     * own clock.
     *
     * @param array<string, scalar|null> $receipt
     *
     * @return array<string, mixed> the stored receipt, as penny-track serialises it
     */
    public function createReceipt(array $receipt): array
    {
        $url = $this->endpoint->baseUrl().'/api/receipts';

        $response = $this->send('POST', $url, [
            'headers' => $this->headers($this->writeKey()) + ['Content-Type' => 'application/json'],
            'json' => $receipt,
            'timeout' => self::TIMEOUT,
        ], $url, allowConflict: true, allowUnprocessable: true);

        $status = $response->getStatusCode();

        if (409 === $status) {
            // penny-track's own duplicate guard: same amount, business and
            // category logged within five minutes of the receipt's own
            // created_at. It is stricter and stranger than this tool's
            // same-day rule, and it is worth passing through rather than
            // "fixing" here — it is the ledger protecting itself, and the
            // caller should know which of the two checks fired.
            throw new TransactionRefused(\sprintf('Penny-track refused this receipt as a duplicate: %s', $this->detail($response)));
        }

        if (422 === $status) {
            $fields = $this->fieldErrors($response);

            throw new TransactionRefused('' === $fields ? \sprintf('Penny-track rejected this receipt as invalid: %s', $this->detail($response)) : \sprintf('Penny-track rejected this receipt: %s', $fields));
        }

        return $this->decode($response, $url);
    }

    /**
     * The key presented for reads.
     *
     * penny-track returns `401`/`403` for a bad key on either path, so the
     * message has to be chosen by the caller that knows which variable it read
     * the key from — otherwise an operator holding a working write key would be
     * sent to check a read variable that is not the problem.
     */
    private function readKey(): string
    {
        return $this->credentials->readKeyOrFail();
    }

    private function writeKey(): string
    {
        return $this->credentials->writeKeyOrFail();
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $apiKey): array
    {
        return [
            'X-API-Key' => $apiKey,
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options, string $contextUrl, bool $allowConflict = false, bool $allowUnprocessable = false): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        try {
            $response = $this->httpClient->request($method, $url, $options);

            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(\sprintf('Could not reach penny-track at %s: %s', $this->endpoint->baseUrl(), $e->getMessage()), 0, $e);
        }

        if (401 === $status || 403 === $status) {
            throw new RuntimeException(\sprintf('Penny-track rejected the API key. %s', $this->authHint($contextUrl, $method)));
        }

        if ($allowConflict && 409 === $status) {
            return $response;
        }

        if ($allowUnprocessable && 422 === $status) {
            return $response;
        }

        if ($status >= 400) {
            throw new RuntimeException(\sprintf('Penny-track returned HTTP %d for %s: %s', $status, $contextUrl, $this->detail($response)));
        }

        return $response;
    }

    /**
     * Which variable the operator needs to look at, given what was attempted.
     *
     * A `POST` to the receipts collection can only have presented the write key,
     * so naming the read variable there would be actively misleading — and it is
     * the likeliest moment for a read-only key to be discovered, since
     * penny-track accepts it happily for every `GET`.
     */
    private function authHint(string $url, string $method): string
    {
        if ('POST' === $method) {
            return 'Check PENNYTRACK_WRITE_API_KEY: creating a receipt requires a full-access key, and penny-track refuses a read-only key for anything that mutates the ledger.';
        }

        return 'Check PENNYTRACK_API_KEY (a read-only key is sufficient).';
    }

    /**
     * The useful part of an error body, bounded.
     */
    private function detail(\Symfony\Contracts\HttpClient\ResponseInterface $response): string
    {
        try {
            $decoded = $response->toArray(false);

            foreach (['error', 'detail', 'title'] as $key) {
                if (isset($decoded[$key]) && \is_string($decoded[$key]) && '' !== trim($decoded[$key])) {
                    return trim($decoded[$key]);
                }
            }
        } catch (Throwable) {
            // Not JSON — fall through to a bounded summary of the raw body.
        }

        try {
            return $this->summarize($response->getContent(false));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * penny-track's validation failure is `{"errors": {"field": "message"}}`.
     *
     * Rendered as a sentence rather than echoed as JSON, because the caller is
     * usually a model that has to act on it.
     */
    private function fieldErrors(\Symfony\Contracts\HttpClient\ResponseInterface $response): string
    {
        try {
            $decoded = $response->toArray(false);
        } catch (Throwable) {
            return '';
        }

        $errors = $decoded['errors'] ?? null;

        if (!\is_array($errors) || [] === $errors) {
            return '';
        }

        $parts = [];
        foreach ($errors as $field => $message) {
            $parts[] = \sprintf('%s: %s', (string) $field, \is_string($message) ? $message : json_encode($message));
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Symfony\Contracts\HttpClient\ResponseInterface $response, string $url): array
    {
        try {
            $payload = $response->toArray(false);
        } catch (Throwable $e) {
            throw new RuntimeException(\sprintf('Penny-track returned a body that is not JSON for %s: %s', $url, $e->getMessage()), 0, $e);
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function stringList(string $path, string $apiKey): array
    {
        $url = $this->endpoint->baseUrl().$path;

        $response = $this->send('GET', $url, [
            'headers' => $this->headers($apiKey),
            'timeout' => self::TIMEOUT,
        ], $url);

        $payload = $this->decode($response, $url);

        $values = [];
        foreach ($payload as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * A bounded, single-line rendering of a body that is not JSON.
     *
     * Tags are dropped rather than echoed so the useful part of a proxy's error
     * page survives while the markup does not.
     */
    private function summarize(string $body): string
    {
        $summary = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));

        if (mb_strlen($summary) <= self::MAX_ERROR_DETAIL_CHARS) {
            return $summary;
        }

        return mb_substr($summary, 0, self::MAX_ERROR_DETAIL_CHARS).'…';
    }
}
