<?php

declare(strict_types=1);

namespace App\Tool\PennyTrack;

use App\PennyTrack\Domain\DuplicateTransaction;
use App\PennyTrack\Domain\TransactionRefused;
use App\PennyTrack\Domain\Vocabulary;
use App\PennyTrack\PennyTrackClient;
use InvalidArgumentException;

/**
 * `create_transaction` — log one spending transaction in penny-track.
 *
 * The use case is a receipt arriving by email: a model reads "thank you for
 * your payment of $2.13" and logs it, without a human retyping it later. That
 * makes this tool the only one in the project that writes to a ledger, and it
 * is where the project's usual rule — pass upstream data through untouched —
 * has to be inverted. `get_transactions` can report whatever penny-track holds,
 * typo included; a *write* has to decide whether what it is about to add is a
 * thing that belongs there, because nothing downstream ever will.
 *
 * Three safeguards, all of them refusals rather than repairs except where a
 * repair is provably safe:
 *
 * 1. **The category must already exist**, matched the ledger's way — see
 *    {@see Vocabulary}, which corrects case, spacing and punctuation and
 *    refuses everything else. A receipt mailed as "software" lands in the
 *    existing "Software"; a receipt whose category fits nothing is refused,
 *    with the nearest existing ones named.
 * 2. **The business must already exist**, by the same rule. This is what stops
 *    a mailbox full of "Backblaze, Inc." / "Backblaze Inc" / "backblaze" from
 *    becoming three merchants in the spending breakdown.
 * 3. **No second transaction for the same amount, business and category on the
 *    same day** — the rule, and why it is wider than penny-track's own
 *    five-minute window, is set out on {@see DuplicateTransaction}.
 *
 * The two vocabulary checks make the third one work. A duplicate can only be
 * recognised if the values being compared are the values already stored, and
 * the reason a caller's spelling differs from the ledger's is case and
 * punctuation — exactly what has been resolved away by the time the comparison
 * happens.
 *
 * Every call answers the same way whether or not the transaction was created,
 * in that it either returns the stored receipt or throws a message explaining
 * what it declined to do. A refusal is never an empty success.
 */
final readonly class CreateTransactionTool
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * Page size for the duplicate scan, and the ceiling on how many pages of
     * one day's ledger are read looking for it.
     *
     * A hundred transactions in a single day is already unusual for a personal
     * ledger; the cap exists so a pathological day cannot turn one tool call
     * into an unbounded number of requests.
     */
    private const SCAN_PAGE_SIZE = 100;
    private const SCAN_MAX_PAGES = 10;

    public function __construct(
        private PennyTrackClient $client,
    ) {
    }

    /**
     * Log one transaction.
     *
     * @param float       $amount   what was spent, in the ledger's currency
     * @param string      $date     when it happened, YYYY-MM-DD — the date on the receipt, not today
     * @param string      $business the merchant, spelled as the ledger already spells it
     * @param string      $category the category, spelled as the ledger already spells it
     * @param string|null $location free text; not checked against the ledger
     * @param string|null $notes    free text; not checked against the ledger
     *
     * @return array<string, mixed>
     */
    public function createTransaction(
        float $amount,
        string $date,
        string $business,
        string $category,
        ?string $location = null,
        ?string $notes = null,
    ): array {
        $date = $this->validateDate($date);
        $amount = $this->validateAmount($amount);

        // Read the vocabulary first. Whether the business and category exist is
        // the question most likely to end the call, and it is cheaper — and far
        // more useful — to answer it before anything has been compared against
        // a day of the ledger.
        $businesses = new Vocabulary($this->client->businesses());
        $categories = new Vocabulary($this->client->categories());

        $business = $this->resolve($business, 'business', $businesses);
        $category = $this->resolve($category, 'category', $categories);

        $this->assertNotADuplicate($date, $amount, $business, $category);

        $receipt = [
            'amount' => $amount,
            'business' => $business,
            'category' => $category,
            // Date only: penny-track reads this as that day, and an invented
            // time of day would be a fact the receipt email never stated.
            'created_at' => $date,
        ];

        if (null !== $location && '' !== trim($location)) {
            $receipt['location'] = trim($location);
        }

        if (null !== $notes && '' !== trim($notes)) {
            $receipt['notes'] = trim($notes);
        }

        $stored = $this->client->createReceipt($receipt);

        $result = [
            'created' => true,
            'id' => $stored['id'] ?? null,
            'amount' => $stored['amount'] ?? (float) $amount,
            'date' => $date,
            'business' => $business,
            'category' => $category,
            'location' => $stored['location'] ?? ($receipt['location'] ?? null),
            'notes' => $stored['notes'] ?? ($receipt['notes'] ?? null),
            'message' => \sprintf('Logged %s at %s in %s on %s.', $amount, $business, $category, $date),
        ];

        $this->describeAdjustments($result, $businesses, $categories, $business, $category);

        return $result;
    }

    /**
     * Say what the ledger decided, when it decided something the caller did not
     * literally ask for.
     *
     * Both cases are the same shape of honesty: the transaction *was* created,
     * and the caller is told the fact that would otherwise be invisible from the
     * result's headline — that a spelling it passed was rewritten, or that this
     * is a brand-new ledger with no vocabulary to conform to. The alternative
     * is a caller believing it passed one value while the ledger holds another.
     *
     * @param array<string, mixed> $result
     */
    private function describeAdjustments(array &$result, Vocabulary $businesses, Vocabulary $categories, string $business, string $category): void
    {
        $notes = [];

        $fields = [
            'business' => [$businesses, $business],
            'category' => [$categories, $category],
        ];

        foreach ($fields as $field => [$vocabulary, $resolved]) {
            foreach ($vocabulary->corrections() as $asked => $written) {
                $notes[] = \sprintf('the %s "%s" was written as "%s", which is how this ledger already spells it', $field, $asked, $written);
            }

            // An empty vocabulary is the one case where the check is skipped.
            // It is also the only time this tool can widen the vocabulary
            // rather than conform to it — and therefore the moment a typo
            // becomes permanent, since later receipts are matched against it.
            if ($vocabulary->isEmpty()) {
                $notes[] = \sprintf('penny-track had no %s yet, so "%s" was stored exactly as given and is now this ledger\'s spelling of it — check it is not a typo, because later receipts will be matched against it', $field, $resolved);
            }
        }

        if ([] !== $notes) {
            $result['note'] = 'Penny-track adjusted this receipt: '.implode('; ', $notes).'.';
        }
    }

    /**
     * Resolve a caller's spelling against the ledger, or refuse.
     *
     * An empty vocabulary is the first receipt on a new instance: there is
     * nothing to conform to and nothing to correct toward, so the value is
     * passed through — see the note the caller gets back. The alternative,
     * refusing, would leave the tool unable to log the very transaction that
     * establishes the vocabulary it exists to protect.
     */
    private function resolve(string $value, string $field, Vocabulary $vocabulary): string
    {
        $value = trim($value);

        if ('' === $value) {
            throw new InvalidArgumentException(\sprintf('%s must not be empty.', $field));
        }

        if ($vocabulary->isEmpty()) {
            return $value;
        }

        return $vocabulary->resolve($value, $field);
    }

    /**
     * Refuse a transaction that is already in the ledger for that day.
     *
     * The day is read from penny-track as two inclusive instants rather than as
     * one `YYYY-MM-DD`, even though the read tool sends dates and the server
     * accepts them. The reason is the boundary: penny-track parses a `to` date
     * to midnight, so a listing filtered with `to=2026-10-02` returns
     * transactions from 00:00:00 onwards and stops there — it would miss
     * everything logged later that same day, which is the exact case this check
     * exists for. Bracketing the day explicitly says what "that day" means
     * instead of inheriting a default.
     */
    private function assertNotADuplicate(string $date, string $amount, string $business, string $category): void
    {
        foreach ($this->dayOf($date) as $existing) {
            if (!$this->sameAmount($existing, $amount)) {
                continue;
            }

            if ($this->stringField($existing, 'business') !== $business) {
                continue;
            }

            if ($this->stringField($existing, 'category') !== $category) {
                continue;
            }

            $id = $existing['id'] ?? null;
            $createdAt = $existing['created_at'] ?? null;

            throw new DuplicateTransaction(date: $date, amount: $amount, business: $business, category: $category, existingId: is_numeric($id) ? (int) $id : null, existingCreatedAt: \is_string($createdAt) ? $createdAt : null);
        }
    }

    /**
     * Every receipt penny-track holds for one day.
     *
     * @return list<array<string, mixed>>
     */
    private function dayOf(string $date): array
    {
        /** @var list<array<string, mixed>> $receipts */
        $receipts = [];

        for ($page = 1; $page <= self::SCAN_MAX_PAGES; ++$page) {
            $payload = $this->client->listReceipts([
                'from' => $date.' 00:00:00',
                'to' => $date.' 23:59:59',
                'limit' => self::SCAN_PAGE_SIZE,
                'page' => $page,
            ]);

            $rows = $payload['data'] ?? null;

            if (!\is_array($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (\is_array($row)) {
                    $receipts[] = $row;
                }
            }

            $pages = $payload['meta']['pages'] ?? null;

            if (!is_numeric($pages) || $page >= (int) $pages || [] === $rows) {
                break;
            }
        }

        return $receipts;
    }

    /**
     * Whether a stored receipt is for the same money.
     *
     * Compared as 2-decimal strings, which is the form penny-track stores
     * (`DECIMAL(10,2)`) and the form both sides are normalised to — so `2.1`,
     * `2.10` and `2.1000000000000001` are one amount, which is what anyone
     * reading the ledger would say.
     *
     * @param array<string, mixed> $existing
     */
    private function sameAmount(array $existing, string $amount): bool
    {
        $stored = $existing['amount'] ?? null;

        if (!is_numeric($stored)) {
            return false;
        }

        return number_format((float) $stored, 2, '.', '') === $amount;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringField(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * A real calendar day, in `YYYY-MM-DD`.
     */
    private function validateDate(string $date): string
    {
        $date = trim($date);

        if (!preg_match(self::DATE_PATTERN, $date)) {
            throw new InvalidArgumentException(\sprintf('date must be in YYYY-MM-DD format (got "%s"). Pass the date the transaction happened — the date on the receipt — not a timestamp, and not the offset-bearing format calendar listings return.', $date));
        }

        [$y, $m, $d] = explode('-', $date);

        if (!checkdate((int) $m, (int) $d, (int) $y)) {
            throw new InvalidArgumentException(\sprintf('date must be a real calendar date (got "%s"; 2026-02-30 is not).', $date));
        }

        return $date;
    }

    /**
     * A positive amount in whole cents, as a 2-decimal string.
     *
     * Refusing sub-cent precision rather than rounding it is the point: a
     * caller that produced `2.135` has misread something, and silently storing
     * `2.14` would be the ledger disagreeing with the receipt.
     */
    private function validateAmount(float $amount): string
    {
        if ($amount <= 0.0) {
            throw new InvalidArgumentException(\sprintf('amount must be greater than zero (got %s). This ledger records spending, so a negative amount is not a refund — it would be stored as an expense with a nonsense sign.', $amount));
        }

        $rounded = round($amount, 2);

        if (abs($amount - $rounded) > 1e-9) {
            throw new TransactionRefused(\sprintf('amount must be a whole number of cents (got %s). Penny-track stores amounts to two decimal places, so this would be silently rounded — and a receipt that does not survive the round trip is a sign it was misread.', $amount));
        }

        return number_format($rounded, 2, '.', '');
    }
}
