<?php

declare(strict_types=1);

namespace App\Tool\PennyTrack;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function checkdate;
use function is_array;
use function preg_match;
use function rtrim;
use function sprintf;
use function trim;

/**
 * Penny-track transactions tool backed by the receipts API.
 *
 * penny-track is a self-hosted spending tracker. This tool exposes its
 * GET /api/receipts endpoint: a paginated list of logged transactions
 * filtered by an inclusive date range. Authentication uses the
 * X-API-Key header (a read-only key is sufficient; this tool only
 * reads, never mutates).
 */
final class TransactionsTool
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $apiKey,
    ) {}

    /**
     * List transactions (receipts) logged in penny-track within an
     * inclusive date range.
     *
     * Note: penny-track filters on the date a receipt was logged
     * (created_at), which is the only date a receipt carries.
     *
     * @param string   $from  start date, YYYY-MM-DD (inclusive)
     * @param string   $to    end date, YYYY-MM-DD (inclusive, >= from)
     * @param null|int $limit page size, 1-100 (defaults to penny-track's 10)
     *
     * @return array<string, mixed>
     */
    public function getTransactions(string $from, string $to, ?int $limit = null): array
    {
        [$from, $to] = $this->validateDates($from, $to);
        $limit = $this->validateLimit($limit);

        $config = $this->clientConfig();
        $url = $config['url'].'/api/receipts';

        $query = [
            'from' => $from,
            'to' => $to,
        ];

        if (null !== $limit) {
            $query['limit'] = $limit;
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
            throw new RuntimeException(sprintf('Could not reach penny-track at %s: %s', $config['url'], $e->getMessage()), 0, $e);
        }

        if (401 === $status || 403 === $status) {
            throw new RuntimeException('Penny-track rejected the API key. Check PENNYTRACK_API_KEY (a read-only key is sufficient).');
        }

        if ($status >= 400) {
            $body = '';

            try {
                $body = $response->getContent(false);
            } catch (Throwable) {
                // status already captured; body is best-effort for context
            }

            throw new RuntimeException(sprintf('Penny-track returned HTTP %d for %s: %s', $status, $url, trim($body)));
        }

        $data = $response->toArray();

        if (!isset($data['data']) || !is_array($data['data'])) {
            throw new RuntimeException('Unexpected response shape from penny-track: missing "data" array.');
        }

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
     * @return null|int normalized limit or null to use penny-track's default
     */
    private function validateLimit(?int $limit): ?int
    {
        if (null === $limit) {
            return null;
        }

        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }

        return $limit;
    }

    /**
     * Resolve configuration, failing with a clear message when the
     * deployment has not been pointed at a penny-track instance.
     *
     * @return array{url: string, key: string}
     */
    private function clientConfig(): array
    {
        $url = trim($this->baseUrl);
        $key = trim($this->apiKey);

        if ('' === $url) {
            throw new RuntimeException('PENNYTRACK_URL is not configured. Set it in .env.local to the base URL of your penny-track instance (e.g. https://penny.example.com).');
        }

        if ('' === $key) {
            throw new RuntimeException('PENNYTRACK_API_KEY is not configured. Create a read-only API key in penny-track (bin/console app:create-api-key) and set PENNYTRACK_API_KEY in .env.local.');
        }

        if (!preg_match('#^https?://#', $url)) {
            throw new RuntimeException(sprintf('PENNYTRACK_URL must start with http:// or https:// (got "%s").', $url));
        }

        return ['url' => rtrim($url, '/'), 'key' => $key];
    }
}
