<?php

declare(strict_types=1);

namespace App\Tool\PennyTrack;

use App\PennyTrack\PennyTrackClient;
use InvalidArgumentException;

/**
 * Penny-track transactions tool backed by the receipts API.
 *
 * penny-track is a self-hosted spending tracker. This tool exposes its
 * GET /api/receipts endpoint: a paginated list of logged transactions
 * filtered by an inclusive date range. Authentication uses an API key in the
 * X-API-Key header (a read-only key is sufficient; this tool only reads,
 * never mutates).
 *
 * Everything about *reaching* penny-track — the base URL, the key, the
 * translation of a refused key into a sentence naming the variable to fix —
 * lives in {@see PennyTrackClient}, because the create tool needs all of it
 * too. What stays here is the argument surface, which is this tool's alone:
 * the date range, and the range check that makes "to before from" a refusal
 * with a sentence in it rather than an empty page.
 */
final class TransactionsTool
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private PennyTrackClient $client,
    ) {
    }

    /**
     * List transactions (receipts) logged in penny-track within an
     * inclusive date range.
     *
     * Note: penny-track filters on the date a receipt was logged
     * (created_at), which is the only date a receipt carries.
     *
     * @param string   $from  start date, YYYY-MM-DD (inclusive)
     * @param string   $to    end date, YYYY-MM-DD (inclusive, >= from)
     * @param int|null $limit page size, 1-100 (defaults to penny-track's 10)
     *
     * @return array<string, mixed>
     */
    public function getTransactions(string $from, string $to, ?int $limit = null): array
    {
        [$from, $to] = $this->validateDates($from, $to);
        $limit = $this->validateLimit($limit);

        $query = [
            'from' => $from,
            'to' => $to,
        ];

        if (null !== $limit) {
            $query['limit'] = $limit;
        }

        return $this->client->listReceipts($query);
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
     * @return int|null normalized limit or null to use penny-track's default
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
}
