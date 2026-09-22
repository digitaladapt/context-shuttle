<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\PennyTrack;

use App\Tool\PennyTrack\TransactionsTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function json_encode;
use function strtolower;

use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for the penny-track transactions tool, using a mocked HTTP
 * client so no network is needed. Exercises the tool the way a caller
 * would: valid ranges, invalid dates, bad limits, missing config,
 * auth failures, and upstream errors.
 *
 * @internal
 *
 * @covers \App\Tool\PennyTrack\TransactionsTool
 */
final class TransactionsToolTest extends TestCase
{
    public function test_fetches_transactions_for_valid_range(): void
    {
        $client = new MockHttpClient();
        $client->setResponseFactory(function ($method, $url, $options) use (&$calls) {
            $calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(json_encode([
                'data' => [
                    ['id' => 1, 'amount' => 12.5, 'business' => 'Coffee Shop', 'category' => 'Dining'],
                ],
                'meta' => ['page' => 1, 'limit' => 10, 'total' => 1, 'pages' => 1],
            ], JSON_THROW_ON_ERROR));
        });

        $tool = new TransactionsTool($client, 'https://penny.example.com', 'ro-key');

        $result = $tool->getTransactions('2025-01-01', '2025-01-31');

        self::assertSame('GET', $calls[0]['method']);
        self::assertSame('https://penny.example.com/api/receipts?from=2025-01-01&to=2025-01-31', $calls[0]['url']);
        self::assertSame(12.5, $result['data'][0]['amount']);
        self::assertSame(1, $result['meta']['total']);
    }

    public function test_sends_api_key_header(): void
    {
        $client = new MockHttpClient();
        $client->setResponseFactory(function ($method, $url, $options) use (&$calls) {
            $calls[] = $options;

            return new MockResponse('{"data": [], "meta": {"page": 1, "limit": 10, "total": 0, "pages": 0}}');
        });

        $tool = new TransactionsTool($client, 'https://penny.example.com', 'ro-key');

        $tool->getTransactions('2025-01-01', '2025-01-31');

        $headerLine = $calls[0]['normalized_headers']['x-api-key'][0]
            ?? $calls[0]['normalized_headers']['X-API-Key'][0]
            ?? '';
        self::assertStringContainsString('ro-key', $headerLine);
        self::assertStringContainsString('api-key', strtolower($headerLine));
    }

    public function test_passes_limit_through(): void
    {
        $client = new MockHttpClient();
        $client->setResponseFactory(function ($method, $url, $options) use (&$calls) {
            $calls[] = $url;

            return new MockResponse('{"data": [], "meta": {"page": 1, "limit": 50, "total": 0, "pages": 0}}');
        });

        $tool = new TransactionsTool($client, 'https://penny.example.com', 'ro-key');

        $tool->getTransactions('2025-01-01', '2025-01-31', 50);

        self::assertSame('https://penny.example.com/api/receipts?from=2025-01-01&to=2025-01-31&limit=50', $calls[0]);
    }

    public function test_strips_trailing_slash_from_base_url(): void
    {
        $client = new MockHttpClient();
        $client->setResponseFactory(function ($method, $url, $options) use (&$calls) {
            $calls[] = $url;

            return new MockResponse('{"data": [], "meta": {"page": 1, "limit": 10, "total": 0, "pages": 0}}');
        });

        $tool = new TransactionsTool($client, 'https://penny.example.com/', 'ro-key');

        $tool->getTransactions('2025-01-01', '2025-01-31');

        self::assertSame('https://penny.example.com/api/receipts?from=2025-01-01&to=2025-01-31', $calls[0]);
    }

    public function test_rejects_malformed_dates(): void
    {
        $tool = new TransactionsTool(new MockHttpClient(), 'https://penny.example.com', 'ro-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM-DD');

        $tool->getTransactions('01/01/2025', '2025-01-31');
    }

    public function test_rejects_non_calendar_dates(): void
    {
        $tool = new TransactionsTool(new MockHttpClient(), 'https://penny.example.com', 'ro-key');

        $this->expectException(InvalidArgumentException::class);

        $tool->getTransactions('2025-02-30', '2025-03-01');
    }

    public function test_rejects_inverted_range(): void
    {
        $tool = new TransactionsTool(new MockHttpClient(), 'https://penny.example.com', 'ro-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'to' date must not be before 'from' date");

        $tool->getTransactions('2025-02-01', '2025-01-01');
    }

    public function test_rejects_out_of_range_limit(): void
    {
        $tool = new TransactionsTool(new MockHttpClient(), 'https://penny.example.com', 'ro-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 100');

        $tool->getTransactions('2025-01-01', '2025-01-31', 101);
    }

    public function test_errors_clearly_when_url_unconfigured(): void
    {
        $tool = new TransactionsTool(new MockHttpClient(), '', 'ro-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PENNYTRACK_URL');

        $tool->getTransactions('2025-01-01', '2025-01-31');
    }

    public function test_errors_clearly_when_api_key_unconfigured(): void
    {
        $tool = new TransactionsTool(new MockHttpClient(), 'https://penny.example.com', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PENNYTRACK_API_KEY');

        $tool->getTransactions('2025-01-01', '2025-01-31');
    }

    public function test_rejects_non_http_base_url(): void
    {
        $tool = new TransactionsTool(new MockHttpClient(), 'ftp://penny.example.com', 'ro-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http:// or https://');

        $tool->getTransactions('2025-01-01', '2025-01-31');
    }

    public function test_reports_auth_failure_without_leaking_key(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error": "Invalid API key"}', ['http_code' => 401]));

        $tool = new TransactionsTool($client, 'https://penny.example.com', 'secret-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rejected the API key');

        $tool->getTransactions('2025-01-01', '2025-01-31');
    }

    public function test_reports_upstream_error_with_body(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error": "Invalid date range. \'to\' must not be before \'from\'."}', ['http_code' => 400]));

        $tool = new TransactionsTool($client, 'https://penny.example.com', 'ro-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 400');

        $tool->getTransactions('2025-01-01', '2025-01-31');
    }

    public function test_reports_unexpected_response_shape(): void
    {
        $client = new MockHttpClient(new MockResponse('{"unexpected": true}'));

        $tool = new TransactionsTool($client, 'https://penny.example.com', 'ro-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"data"');

        $tool->getTransactions('2025-01-01', '2025-01-31');
    }
}
