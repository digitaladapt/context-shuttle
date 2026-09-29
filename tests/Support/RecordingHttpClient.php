<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Override;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A `MockHttpClient` that records what it was asked to send.
 *
 * The alternative — a `$calls` array passed by reference into a test helper —
 * works but reads as incidental plumbing, and it hides the fact that a tool
 * test asserts two things: the request that went out and the payload that
 * came back. Modelling the recorder as an object makes the first a property a
 * test reads directly, and one helper then serves every test.
 *
 * It extends `MockHttpClient` rather than implementing `HttpClientInterface`
 * because a `MockResponse` is only usable when a `MockHttpClient` issued it —
 * reimplementing the interface produces responses that explode on first read
 * with "MockResponse instances must be issued by MockHttpClient".
 *
 * Responses are consumed in order, and running out is an error rather than an
 * empty 200 — a test that expects one request and makes two fails loudly
 * instead of quietly passing. Running out is detected in the factory, which
 * is reached before the response is issued.
 */
final class RecordingHttpClient extends MockHttpClient
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array{status: int, body: string}> */
    private array $queue = [];

    public function __construct()
    {
        parent::__construct(function (string $method, string $url, array $options): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            $next = array_shift($this->queue);

            if (null === $next) {
                throw new RuntimeException(\sprintf('The scripted responses ran out: %s %s was request %d, and nothing was queued for it.', $method, $url, \count($this->calls)));
            }

            return new MockResponse($next['body'], ['http_code' => $next['status']]);
        });
    }

    /**
     * Queue bodies, in order, each answered with 200.
     *
     * @param list<string> $bodies
     */
    public function queue(array $bodies): void
    {
        foreach ($bodies as $body) {
            $this->queueWithStatus($body, 200);
        }
    }

    /**
     * Queue one body with an explicit status, for the failure paths.
     */
    public function queueWithStatus(string $body, int $status): void
    {
        $this->queue[] = ['status' => $status, 'body' => $body];
    }

    /**
     * The decoded JSON body of the call at `$index`.
     *
     * @return array<array-key, mixed>
     */
    public function jsonBody(int $index = 0): array
    {
        $body = $this->calls[$index]['options']['body'] ?? '';

        \assert(\is_string($body));

        return json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * The request headers of the call at `$index`, lower-cased by name.
     *
     * `MockHttpClient` has already normalized them to `Name: value` lines by
     * the time the factory runs, so the assertions read against that rather
     * than the options the caller passed — which is also the form that would
     * actually go on the wire.
     *
     * @return array<string, list<string>>
     */
    public function requestHeaders(int $index = 0): array
    {
        return $this->calls[$index]['options']['normalized_headers'] ?? [];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function withOptions(array $options): static
    {
        // The tool never re-scopes its client, so sharing state is correct:
        // requests stay visible to the test either way.
        return $this;
    }
}
