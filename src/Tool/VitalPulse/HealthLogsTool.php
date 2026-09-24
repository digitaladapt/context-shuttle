<?php

declare(strict_types=1);

namespace App\Tool\VitalPulse;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Vital-pulse health logs tool backed by the health log API.
 *
 * vital-pulse is a self-hosted health tracker (blood pressure, heart
 * rate, weight). This tool exposes its GET /api/v1/logs endpoint: a
 * paginated list of logged readings filtered by an inclusive date
 * range. Authentication uses the X-API-Key header (a read-only key is
 * sufficient; this tool only reads, never mutates).
 *
 * vital-pulse specifics this tool accounts for:
 *
 *  - Dates are parsed as UTC timestamps, and the end of the range is
 *    matched with "timestamp <= to". A bare YYYY-MM-DD parses as
 *    midnight, which would silently drop every reading taken during
 *    the end day. The tool therefore expands "to" to the last second
 *    of that day (23:59:59 UTC) so the range is genuinely inclusive.
 *  - The page size is capped at 200 by the API (its default), and
 *    pagination uses a "page" number rather than penny-track's page
 *    parameter convention.
 *  - When more than 200 readings match a range, vital-pulse does not
 *    paginate raw records: it auto-aggregates them into day, week, or
 *    month buckets (meta.aggregated = true). The payload is passed
 *    through unchanged either way; the meta block tells the caller
 *    which shape "data" has.
 *  - Readings are unit-less in the API while the dashboard labels them
 *    (mmHg for blood pressure, bpm for heart rate, lbs for weight), so
 *    every response is annotated with a meta.units map. A units map
 *    that a future vital-pulse version sends itself takes precedence
 *    per field; the defaults fill any field it does not mention.
 */
final class HealthLogsTool
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * Measurement units for each reading field, as labelled in
     * vital-pulse's own dashboard. The API delivers bare numbers; this
     * mapping is attached to every response under meta.units so callers
     * never have to infer the unit from a value's magnitude.
     */
    private const UNITS = [
        'systolic' => 'mmHg',
        'diastolic' => 'mmHg',
        'heart_rate' => 'bpm',
        'weight' => 'lbs',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $apiKey,
    ) {
    }

    /**
     * List health readings logged in vital-pulse within an inclusive
     * date range (UTC), newest first.
     *
     * @param string   $from  start date, YYYY-MM-DD (inclusive, midnight UTC)
     * @param string   $to    end date, YYYY-MM-DD (inclusive, through 23:59:59 UTC, >= from)
     * @param int|null $limit page size, 1-200 (defaults to vital-pulse's 200)
     * @param int|null $page  1-based page number (defaults to 1)
     *
     * @return array<string, mixed> payload annotated with meta.units
     */
    public function getHealthLogs(string $from, string $to, ?int $limit = null, ?int $page = null): array
    {
        [$from, $to] = $this->validateDates($from, $to);
        $limit = $this->validateLimit($limit);
        $page = $this->validatePage($page);

        $config = $this->clientConfig();
        $url = $config['url'].'/api/v1/logs';

        $query = [
            'from' => $from,
            'to' => $to.'T23:59:59',
        ];

        if (null !== $limit) {
            $query['limit'] = $limit;
        }

        if (null !== $page) {
            $query['page'] = $page;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'X-API-Key' => $config['key'],
                    'Accept' => 'application/json',
                ],
                'query' => $query,
                'timeout' => 10,
            ]);

            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(\sprintf('Could not reach vital-pulse at %s: %s', $config['url'], $e->getMessage()), 0, $e);
        }

        if (401 === $status || 403 === $status) {
            throw new RuntimeException('Vital-pulse rejected the API key. Check VITALPULSE_API_KEY (a read-only key is sufficient).');
        }

        if ($status >= 400) {
            $body = '';

            try {
                $body = $response->getContent(false);
            } catch (Throwable) {
                // status already captured; body is best-effort for context
            }

            throw new RuntimeException(\sprintf('Vital-pulse returned HTTP %d for %s: %s', $status, $url, trim($body)));
        }

        $data = $response->toArray();

        if (!isset($data['data']) || !\is_array($data['data'])) {
            throw new RuntimeException('Unexpected response shape from vital-pulse: missing "data" array.');
        }

        // The API returns bare numbers; annotate the units its dashboard
        // shows (mmHg, bpm, lbs). A units map that a future vital-pulse
        // version sends itself takes precedence per field; the defaults
        // fill any field it does not mention, so the map is complete.
        $meta = $data['meta'] ?? null;
        if (!\is_array($meta)) {
            $meta = [];
        }

        $units = $meta['units'] ?? null;
        if (!\is_array($units)) {
            $units = [];
        }

        $meta['units'] = array_merge(self::UNITS, $units);
        $data['meta'] = $meta;

        return $data;
    }

    /**
     * Strict calendar check: format already matches YYYY-MM-DD, so parse
     * the components and verify the day exists in that month (rejects
     * 2025-02-30, which strtotime() would silently roll over).
     */
    private function isRealCalendarDate(string $date): bool
    {
        [$y, $m, $d] = explode('-', $date);

        return checkdate((int) $m, (int) $d, (int) $y);
    }

    /**
     * Validate and normalize the date pair.
     *
     * @return array{0: string, 1: string}
     */
    private function validateDates(string $from, string $to): array
    {
        $from = trim($from);
        $to = trim($to);

        if (!preg_match(self::DATE_PATTERN, $from) || !preg_match(self::DATE_PATTERN, $to)) {
            throw new InvalidArgumentException('Dates must be in YYYY-MM-DD format (e.g. 2025-01-01).');
        }

        if (!$this->isRealCalendarDate($from) || !$this->isRealCalendarDate($to)) {
            throw new InvalidArgumentException('Dates must be valid calendar dates (e.g. 2025-01-01; 2025-02-30 is not).');
        }

        if ($to < $from) {
            throw new InvalidArgumentException("'to' date must not be before 'from' date.");
        }

        return [$from, $to];
    }

    /**
     * @return int|null normalized limit or null to use vital-pulse's default
     */
    private function validateLimit(?int $limit): ?int
    {
        if (null === $limit) {
            return null;
        }

        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('limit must be between 1 and 200.');
        }

        return $limit;
    }

    /**
     * @return int|null normalized page or null for the first page
     */
    private function validatePage(?int $page): ?int
    {
        if (null === $page) {
            return null;
        }

        if ($page < 1) {
            throw new InvalidArgumentException('page must be at least 1.');
        }

        return $page;
    }

    /**
     * Resolve configuration, failing with a clear message when the
     * deployment has not been pointed at a vital-pulse instance.
     *
     * @return array{url: string, key: string}
     */
    private function clientConfig(): array
    {
        $url = trim($this->baseUrl);
        $key = trim($this->apiKey);

        if ('' === $url) {
            throw new RuntimeException('VITALPULSE_URL is not configured. Set it in .env.local to the base URL of your vital-pulse instance (e.g. https://pulse.example.com).');
        }

        if ('' === $key) {
            throw new RuntimeException('VITALPULSE_API_KEY is not configured. Set it to an API key from your vital-pulse instance in .env.local (a read-only key is sufficient).');
        }

        if (!preg_match('#^https?://#', $url)) {
            throw new RuntimeException(\sprintf('VITALPULSE_URL must start with http:// or https:// (got "%s").', $url));
        }

        return ['url' => rtrim($url, '/'), 'key' => $key];
    }
}
