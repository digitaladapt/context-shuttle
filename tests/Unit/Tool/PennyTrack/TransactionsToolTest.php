<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\PennyTrack;

use App\PennyTrack\PennyTrackClient;
use App\PennyTrack\PennyTrackCredentials;
use App\PennyTrack\PennyTrackEndpoint;
use App\Tests\Support\RecordingHttpClient;
use App\Tool\PennyTrack\TransactionsTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the penny-track transactions tool, using a scripted HTTP
 * client so no network is needed. Exercises the tool the way a caller would:
 * valid ranges, invalid dates, bad limits, missing config, auth failures, and
 * upstream errors.
 *
 * The transport itself — the URL, the key header, the error translation — now
 * lives in `PennyTrackClient` and is tested through it, so what is asserted
 * here is the read tool's own behaviour: the argument checks, and the query it
 * asks the ledger for.
 *
 * @internal
 *
 * @covers \App\Tool\PennyTrack\TransactionsTool
 */
final class TransactionsToolTest extends TestCase
{
    private function tool(RecordingHttpClient $http, string $baseUrl = 'https://penny.example.com'): TransactionsTool
    {
        return new TransactionsTool(new PennyTrackClient(
            $http,
            new PennyTrackEndpoint($baseUrl),
            new PennyTrackCredentials('ro-key', ''),
        ));
    }

    private function emptyPage(): string
    {
        return '{"data": [], "meta": {"page": 1, "limit": 10, "total": 0, "pages": 0}}';
    }

    public function test_fetches_transactions_for_valid_range(): void
    {
        $http = new RecordingHttpClient();
        $http->queue(['{"data": [{"id": 1, "amount": 12.5, "business": "Coffee Shop", "category": "Dining"}], "meta": {"page": 1, "limit": 10, "total": 1, "pages": 1}}']);

        $result = $this->tool($http)->getTransactions('2025-01-01', '2025-01-31');

        self::assertSame('GET', $http->calls[0]['method']);
        self::assertSame('https://penny.example.com/api/receipts?from=2025-01-01&to=2025-01-31', $http->calls[0]['url']);
        self::assertSame(['from' => '2025-01-01', 'to' => '2025-01-31'], $http->calls[0]['options']['query']);
        self::assertSame(12.5, $result['data'][0]['amount']);
        self::assertSame(1, $result['meta']['total']);
    }

    public function test_sends_the_read_api_key_header(): void
    {
        $http = new RecordingHttpClient();
        $http->queue([$this->emptyPage()]);

        $this->tool($http)->getTransactions('2025-01-01', '2025-01-31');

        self::assertStringContainsString('ro-key', $http->requestHeaders(0)['x-api-key'][0]);
    }

    public function test_passes_limit_through(): void
    {
        $http = new RecordingHttpClient();
        $http->queue([$this->emptyPage()]);

        $this->tool($http)->getTransactions('2025-01-01', '2025-01-31', 50);

        self::assertSame(50, $http->calls[0]['options']['query']['limit']);
    }

    public function test_omits_limit_when_not_given(): void
    {
        $http = new RecordingHttpClient();
        $http->queue([$this->emptyPage()]);

        $this->tool($http)->getTransactions('2025-01-01', '2025-01-31');

        self::assertArrayNotHasKey('limit', $http->calls[0]['options']['query']);
    }

    public function test_strips_trailing_slash_from_base_url(): void
    {
        $http = new RecordingHttpClient();
        $http->queue([$this->emptyPage()]);

        $this->tool($http, 'https://penny.example.com/')->getTransactions('2025-01-01', '2025-01-31');

        self::assertSame('https://penny.example.com/api/receipts?from=2025-01-01&to=2025-01-31', $http->calls[0]['url']);
    }

    public function test_rejects_malformed_dates(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM-DD');

        $this->tool($http)->getTransactions('01/01/2025', '2025-01-31');
    }

    public function test_rejects_non_calendar_dates(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(InvalidArgumentException::class);

        $this->tool($http)->getTransactions('2025-02-30', '2025-03-01');
    }

    public function test_rejects_inverted_range(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'to' date must not be before 'from' date");

        $this->tool($http)->getTransactions('2025-02-01', '2025-01-01');
    }

    public function test_rejects_out_of_range_limit(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 100');

        $this->tool($http)->getTransactions('2025-01-01', '2025-01-31', 101);
    }

    public function test_errors_clearly_when_url_unconfigured(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PENNYTRACK_URL');

        $this->tool($http, '')->getTransactions('2025-01-01', '2025-01-31');
    }

    public function test_errors_clearly_when_api_key_unconfigured(): void
    {
        $http = new RecordingHttpClient();

        $tool = new TransactionsTool(new PennyTrackClient(
            $http,
            new PennyTrackEndpoint('https://penny.example.com'),
            new PennyTrackCredentials('', ''),
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PENNYTRACK_API_KEY');

        $tool->getTransactions('2025-01-01', '2025-01-31');
    }

    public function test_rejects_non_http_base_url(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http:// or https://');

        $this->tool($http, 'ftp://penny.example.com')->getTransactions('2025-01-01', '2025-01-31');
    }

    public function test_reports_auth_failure_without_leaking_key(): void
    {
        $http = new RecordingHttpClient();
        $http->queueWithStatus('{"error": "Invalid API key"}', 401);

        try {
            $this->tool($http)->getTransactions('2025-01-01', '2025-01-31');
            self::fail('expected an auth failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('rejected the API key', $e->getMessage());
            self::assertStringNotContainsString('ro-key', $e->getMessage(), 'the key must not appear in a message');
        }
    }

    public function test_reports_upstream_error_with_body(): void
    {
        $http = new RecordingHttpClient();
        $http->queueWithStatus('{"error": "Invalid date range. \'to\' must not be before \'from\'."}', 400);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 400');

        $this->tool($http)->getTransactions('2025-01-01', '2025-01-31');
    }

    public function test_reports_unexpected_response_shape(): void
    {
        $http = new RecordingHttpClient();
        $http->queue(['{"unexpected": true}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"data"');

        $this->tool($http)->getTransactions('2025-01-01', '2025-01-31');
    }

    public function test_reports_an_unreachable_instance_as_a_message_not_a_crash(): void
    {
        // A transport failure, as distinct from a response: the point is that
        // it arrives as the tool's own sentence naming the instance, rather
        // than as an unhandled exception from the HTTP layer.
        $http = new RecordingHttpClient();
        $http->failWith('dns failure');

        $tool = new TransactionsTool(new PennyTrackClient(
            $http,
            new PennyTrackEndpoint('https://penny.invalid'),
            new PennyTrackCredentials('ro-key', ''),
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not reach penny-track at https://penny.invalid');

        $tool->getTransactions('2025-01-01', '2025-01-31');
    }
}
