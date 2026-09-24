<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\VitalPulse;

use App\Tool\VitalPulse\HealthLogsTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for the vital-pulse health logs tool, using a mocked HTTP
 * client so no network is needed. Exercises the tool the way a caller
 * would: valid ranges (including the end-of-day expansion that makes
 * the "to" date genuinely inclusive), pagination params, unit
 * annotation (including upstream deference), invalid dates, bad
 * limits, missing config, auth failures, and upstream errors.
 *
 * @internal
 *
 * @covers \App\Tool\VitalPulse\HealthLogsTool
 */
final class HealthLogsToolTest extends TestCase
{
    public function test_fetches_logs_for_valid_range(): void
    {
        $client = new MockHttpClient();
        $calls = [];
        $client->setResponseFactory(static function ($method, $url, $options) use (&$calls) {
            $calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(json_encode([
                'data' => [
                    ['id' => 1, 'timestamp' => '2025-01-05T08:00:00+00:00', 'systolic' => 120, 'diastolic' => 78, 'heart_rate' => 62, 'weight' => 82.5, 'emoji' => '🙂'],
                ],
                'meta' => ['page' => 1, 'limit' => 200, 'total' => 1, 'pages' => 1, 'aggregated' => false],
            ], \JSON_THROW_ON_ERROR));
        });

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'ro-key');

        $result = $tool->getHealthLogs('2025-01-01', '2025-01-31');

        self::assertSame('GET', $calls[0]['method']);
        self::assertSame(120, $result['data'][0]['systolic']);
        self::assertSame(1, $result['meta']['total']);
        self::assertFalse($result['meta']['aggregated']);
    }

    public function test_expands_end_date_to_end_of_day_so_range_is_inclusive(): void
    {
        // vital-pulse parses "to" as a UTC timestamp and matches
        // timestamp <= to; a bare date would be midnight and drop the
        // whole end day. The tool must send 23:59:59.
        $client = new MockHttpClient();
        $calls = [];
        $client->setResponseFactory(static function ($method, $url, $options) use (&$calls) {
            $calls[] = $url;

            return new MockResponse('{"data": [], "meta": {"page": 1, "limit": 200, "total": 0, "pages": 0, "aggregated": false}}');
        });

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'ro-key');

        $tool->getHealthLogs('2025-01-01', '2025-01-31');

        self::assertSame('https://pulse.example.com/api/v1/logs?from=2025-01-01&to=2025-01-31T23:59:59', $calls[0]);
    }

    public function test_sends_api_key_header(): void
    {
        $client = new MockHttpClient();
        $calls = [];
        $client->setResponseFactory(static function ($method, $url, $options) use (&$calls) {
            $calls[] = $options;

            return new MockResponse('{"data": [], "meta": {"page": 1, "limit": 200, "total": 0, "pages": 0, "aggregated": false}}');
        });

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'ro-key');

        $tool->getHealthLogs('2025-01-01', '2025-01-31');

        $headerLine = $calls[0]['normalized_headers']['x-api-key'][0]
            ?? $calls[0]['normalized_headers']['X-API-Key'][0]
            ?? '';
        self::assertStringContainsString('ro-key', $headerLine);
        self::assertStringContainsString('api-key', strtolower($headerLine));
    }

    public function test_passes_limit_and_page_through(): void
    {
        $client = new MockHttpClient();
        $calls = [];
        $client->setResponseFactory(static function ($method, $url, $options) use (&$calls) {
            $calls[] = $url;

            return new MockResponse('{"data": [], "meta": {"page": 2, "limit": 50, "total": 80, "pages": 2, "aggregated": false}}');
        });

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'ro-key');

        $tool->getHealthLogs('2025-01-01', '2025-01-31', 50, 2);

        self::assertSame('https://pulse.example.com/api/v1/logs?from=2025-01-01&to=2025-01-31T23:59:59&limit=50&page=2', $calls[0]);
    }

    public function test_passes_aggregated_response_through_unchanged(): void
    {
        // When > 200 readings match, vital-pulse auto-aggregates; the
        // tool must not choke on that shape.
        $client = new MockHttpClient(new MockResponse(json_encode([
            'data' => [
                ['id' => null, 'timestamp' => '2025-01-01T00:00:00+00:00', 'systolic' => 121, 'diastolic' => 79, 'heart_rate' => 63, 'weight' => 82.3, 'emoji' => '📊', 'aggregated' => true, 'count' => 14, 'interval' => 'day'],
            ],
            'meta' => ['page' => 1, 'limit' => 31, 'total' => 432, 'pages' => 1, 'aggregated' => true, 'interval' => 'day'],
        ], \JSON_THROW_ON_ERROR)));

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'ro-key');

        $result = $tool->getHealthLogs('2025-01-01', '2025-01-31');

        self::assertTrue($result['meta']['aggregated']);
        self::assertSame(432, $result['meta']['total']);
        self::assertSame(14, $result['data'][0]['count']);
    }

    public function test_annotates_units_on_each_response(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'data' => [
                ['id' => 1, 'timestamp' => '2025-01-05T08:00:00+00:00', 'systolic' => 120, 'diastolic' => 78, 'heart_rate' => 62, 'weight' => 82.5, 'emoji' => '🙂'],
            ],
            'meta' => ['page' => 1, 'limit' => 200, 'total' => 1, 'pages' => 1, 'aggregated' => false],
        ], \JSON_THROW_ON_ERROR)));

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'ro-key');

        $result = $tool->getHealthLogs('2025-01-01', '2025-01-31');

        // vital-pulse's API returns bare numbers; the tool must state
        // the units its dashboard shows: mmHg, bpm, and lbs.
        self::assertSame([
            'systolic' => 'mmHg',
            'diastolic' => 'mmHg',
            'heart_rate' => 'bpm',
            'weight' => 'lbs',
        ], $result['meta']['units']);

        // The rest of the payload is untouched.
        self::assertSame(1, $result['meta']['total']);
        self::assertSame(82.5, $result['data'][0]['weight']);
    }

    public function test_defers_to_upstream_units_when_present(): void
    {
        // If a future vital-pulse sends its own meta.units (e.g. a
        // per-instance kg/lb setting), those values win per field; the
        // defaults fill in anything it leaves out, so the map stays
        // complete.
        $client = new MockHttpClient(new MockResponse('{"data": [], "meta": {"page": 1, "limit": 200, "total": 0, "pages": 0, "aggregated": false, "units": {"weight": "kg"}}}'));

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'ro-key');

        $result = $tool->getHealthLogs('2025-01-01', '2025-01-31');

        self::assertSame([
            'systolic' => 'mmHg',
            'diastolic' => 'mmHg',
            'heart_rate' => 'bpm',
            'weight' => 'kg',
        ], $result['meta']['units']);
    }

    public function test_annotates_units_even_without_meta_block(): void
    {
        $client = new MockHttpClient(new MockResponse('{"data": [{"id": 1, "timestamp": "2025-01-05T08:00:00+00:00", "systolic": 120, "diastolic": 78, "heart_rate": 62, "weight": 82.5, "emoji": "🙂"}]}'));

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'ro-key');

        $result = $tool->getHealthLogs('2025-01-01', '2025-01-31');

        self::assertSame([
            'systolic' => 'mmHg',
            'diastolic' => 'mmHg',
            'heart_rate' => 'bpm',
            'weight' => 'lbs',
        ], $result['meta']['units']);
    }

    public function test_strips_trailing_slash_from_base_url(): void
    {
        $client = new MockHttpClient();
        $calls = [];
        $client->setResponseFactory(static function ($method, $url, $options) use (&$calls) {
            $calls[] = $url;

            return new MockResponse('{"data": [], "meta": {"page": 1, "limit": 200, "total": 0, "pages": 0, "aggregated": false}}');
        });

        $tool = new HealthLogsTool($client, 'https://pulse.example.com/', 'ro-key');

        $tool->getHealthLogs('2025-01-01', '2025-01-31');

        self::assertSame('https://pulse.example.com/api/v1/logs?from=2025-01-01&to=2025-01-31T23:59:59', $calls[0]);
    }

    public function test_rejects_malformed_dates(): void
    {
        $tool = new HealthLogsTool(new MockHttpClient(), 'https://pulse.example.com', 'ro-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM-DD');

        $tool->getHealthLogs('01/01/2025', '2025-01-31');
    }

    public function test_rejects_non_calendar_dates(): void
    {
        $tool = new HealthLogsTool(new MockHttpClient(), 'https://pulse.example.com', 'ro-key');

        $this->expectException(InvalidArgumentException::class);

        $tool->getHealthLogs('2025-02-30', '2025-03-01');
    }

    public function test_rejects_inverted_range(): void
    {
        $tool = new HealthLogsTool(new MockHttpClient(), 'https://pulse.example.com', 'ro-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'to' date must not be before 'from' date");

        $tool->getHealthLogs('2025-02-01', '2025-01-01');
    }

    public function test_rejects_out_of_range_limit(): void
    {
        $tool = new HealthLogsTool(new MockHttpClient(), 'https://pulse.example.com', 'ro-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 200');

        $tool->getHealthLogs('2025-01-01', '2025-01-31', 201);
    }

    public function test_rejects_non_positive_page(): void
    {
        $tool = new HealthLogsTool(new MockHttpClient(), 'https://pulse.example.com', 'ro-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1');

        $tool->getHealthLogs('2025-01-01', '2025-01-31', null, 0);
    }

    public function test_errors_clearly_when_url_unconfigured(): void
    {
        $tool = new HealthLogsTool(new MockHttpClient(), '', 'ro-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VITALPULSE_URL');

        $tool->getHealthLogs('2025-01-01', '2025-01-31');
    }

    public function test_errors_clearly_when_api_key_unconfigured(): void
    {
        $tool = new HealthLogsTool(new MockHttpClient(), 'https://pulse.example.com', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VITALPULSE_API_KEY');

        $tool->getHealthLogs('2025-01-01', '2025-01-31');
    }

    public function test_rejects_non_http_base_url(): void
    {
        $tool = new HealthLogsTool(new MockHttpClient(), 'ftp://pulse.example.com', 'ro-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http:// or https://');

        $tool->getHealthLogs('2025-01-01', '2025-01-31');
    }

    public function test_reports_auth_failure_without_leaking_key(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error": "Invalid API key."}', ['http_code' => 401]));

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'secret-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rejected the API key');

        $tool->getHealthLogs('2025-01-01', '2025-01-31');
    }

    public function test_reports_upstream_error_with_body(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error": "Invalid date range: \\"from\\" must be before or equal to \\"to\\"."}', ['http_code' => 400]));

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'ro-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 400');

        $tool->getHealthLogs('2025-01-01', '2025-01-31');
    }

    public function test_reports_unexpected_response_shape(): void
    {
        $client = new MockHttpClient(new MockResponse('{"unexpected": true}'));

        $tool = new HealthLogsTool($client, 'https://pulse.example.com', 'ro-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"data"');

        $tool->getHealthLogs('2025-01-01', '2025-01-31');
    }
}
