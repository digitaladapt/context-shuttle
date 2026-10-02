<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\PennyTrack;

use App\PennyTrack\Domain\DuplicateTransaction;
use App\PennyTrack\Domain\TransactionRefused;
use App\PennyTrack\Domain\VocabularyMiss;
use App\PennyTrack\PennyTrackClient;
use App\PennyTrack\PennyTrackCredentials;
use App\PennyTrack\PennyTrackEndpoint;
use App\Tests\Support\RecordingHttpClient;
use App\Tool\PennyTrack\CreateTransactionTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The transaction-creation tool, through the HTTP it actually sends.
 *
 * Written against `RecordingHttpClient` rather than a mocked `PennyTrackClient`
 * because the behaviour worth pinning down includes *which requests go out and
 * in what order*: the vocabulary is read before anything is compared, and a
 * refusal has to happen with no `POST` at all. A mock would let a test pass
 * while the tool quietly created a transaction it had decided to refuse.
 *
 * @internal
 *
 * @covers \App\Tool\PennyTrack\CreateTransactionTool
 * @covers \App\PennyTrack\PennyTrackClient
 */
final class CreateTransactionToolTest extends TestCase
{
    private const BUSINESSES = '["Backblaze","Amazon Web Services","Coffee Shop"]';
    private const CATEGORIES = '["Software","Cloud Storage","Dining"]';

    /**
     * A client with the read key and a write key, pointed at an example host.
     */
    private function client(RecordingHttpClient $http): PennyTrackClient
    {
        return new PennyTrackClient(
            $http,
            new PennyTrackEndpoint('https://penny.example.com'),
            new PennyTrackCredentials('read-key', 'write-key'),
        );
    }

    /**
     * Queue the vocabulary reads every successful path starts with, in the order
     * the tool makes them.
     */
    private function queueVocabulary(RecordingHttpClient $http): void
    {
        $http->queue([self::BUSINESSES, self::CATEGORIES]);
    }

    /**
     * A response body for the day-of-ledger scan.
     */
    private function dayBody(string $json = '[]'): string
    {
        return \sprintf('{"data": %s, "meta": {"page": 1, "limit": 100, "total": 0, "pages": 0}}', $json);
    }

    private function tool(RecordingHttpClient $http): CreateTransactionTool
    {
        return new CreateTransactionTool($this->client($http));
    }

    public function test_creates_a_transaction_and_posts_the_expected_body(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody()]);
        $http->queue(['{"id": 42, "amount": 2.13, "business": "Backblaze", "category": "Software", "created_at": "2026-10-02T00:00:00+00:00"}']);

        $result = $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');

        self::assertTrue($result['created']);
        self::assertSame(42, $result['id']);
        // The ledger's own serialisation wins over the caller's argument, so
        // amount comes back as the float penny-track returns; the string form
        // is what goes on the wire, asserted below.
        self::assertSame(2.13, $result['amount']);
        self::assertSame('2026-10-02', $result['date']);
        self::assertArrayNotHasKey('note', $result, 'nothing was adjusted, so nothing should be reported');

        $post = $http->calls[3];
        self::assertSame('POST', $post['method']);
        self::assertSame('https://penny.example.com/api/receipts', $post['url']);
        self::assertSame([
            'amount' => '2.13',
            'business' => 'Backblaze',
            'category' => 'Software',
            'created_at' => '2026-10-02',
        ], $http->jsonBody(3));
    }

    /**
     * The read key is used to read and the write key to write — which is the
     * whole reason there are two, since penny-track refuses a read-only key for
     * anything that mutates the ledger.
     */
    public function test_reads_with_the_read_key_and_writes_with_the_write_key(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody()]);
        $http->queue(['{"id": 1}']);

        $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');

        self::assertStringContainsString('read-key', $http->requestHeaders(0)['x-api-key'][0]);
        self::assertStringContainsString('read-key', $http->requestHeaders(1)['x-api-key'][0]);
        self::assertStringContainsString('read-key', $http->requestHeaders(2)['x-api-key'][0]);
        self::assertStringContainsString('write-key', $http->requestHeaders(3)['x-api-key'][0]);
    }

    /**
     * The day scan asks for a *bracketed* day rather than a plain date.
     *
     * penny-track parses a `to` date to midnight, so a date-shaped range would
     * silently stop at 00:00:00 and miss everything logged later that day —
     * which is exactly the transaction the duplicate check is looking for.
     */
    public function test_the_duplicate_scan_brackets_the_whole_day(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody()]);
        $http->queue(['{"id": 1}']);

        $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');

        $query = $http->calls[2]['options']['query'] ?? [];
        self::assertSame('2026-10-02 00:00:00', $query['from']);
        self::assertSame('2026-10-02 23:59:59', $query['to']);
    }

    public function test_refuses_a_duplicate_and_does_not_post(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody('[{"id": 7, "amount": 2.13, "business": "Backblaze", "category": "Software", "created_at": "2026-10-02T09:00:00+00:00"}]')]);

        try {
            $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');
            self::fail('expected a duplicate refusal');
        } catch (DuplicateTransaction $e) {
            self::assertSame(7, $e->existingId);
            self::assertSame('2026-10-02', $e->date);
            self::assertStringContainsString('Receipt #7 already covers it', $e->getMessage());
        }

        self::assertCount(3, $http->calls, 'a refused duplicate must not reach the ledger');
    }

    /**
     * The duplicate rule is the tool's own, so it catches what penny-track's
     * five-minute window does not: the same amount, merchant and category logged
     * hours apart on the same day.
     */
    public function test_a_duplicate_hours_later_is_still_a_duplicate(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody('[{"id": 9, "amount": 2.13, "business": "Backblaze", "category": "Software", "created_at": "2026-10-02T07:00:00+00:00"}]')]);

        $this->expectException(DuplicateTransaction::class);

        $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');
    }

    /**
     * Amount is compared as money, not as a float, so a value the ledger stores
     * and a value the caller passes are the same amount even when their types
     * differ.
     */
    public function test_an_amount_that_differs_only_in_representation_is_a_duplicate(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody('[{"id": 9, "amount": 2.1, "business": "Backblaze", "category": "Software", "created_at": "2026-10-02T07:00:00+00:00"}]')]);

        $this->expectException(DuplicateTransaction::class);

        $this->tool($http)->createTransaction(2.10, '2026-10-02', 'Backblaze', 'Software');
    }

    /**
     * Same amount, same day, different merchant: two real purchases, so both are
     * logged.
     */
    public function test_a_same_day_same_amount_different_business_is_allowed(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody('[{"id": 9, "amount": 2.13, "business": "Coffee Shop", "category": "Dining", "created_at": "2026-10-02T07:00:00+00:00"}]')]);
        $http->queue(['{"id": 10}']);

        $result = $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');

        self::assertTrue($result['created']);
    }

    public function test_refuses_an_unknown_category_and_does_not_post(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);

        try {
            $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Sofware');
            self::fail('expected a vocabulary refusal');
        } catch (VocabularyMiss $e) {
            self::assertSame('category', $e->field);
            self::assertContains('Software', $e->suggestions);
        }

        self::assertCount(2, $http->calls, 'an unknown category must not reach the ledger');
    }

    public function test_refuses_an_unknown_business_and_does_not_post(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);

        try {
            $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblase', 'Software');
            self::fail('expected a vocabulary refusal');
        } catch (VocabularyMiss $e) {
            self::assertSame('business', $e->field);
            self::assertContains('Backblaze', $e->suggestions);
        }

        self::assertCount(2, $http->calls);
    }

    /**
     * The near-miss correction, end to end: a caller spelling it the way the
     * receipt email happened to is still logged against the stored spelling.
     */
    public function test_a_near_miss_is_written_as_the_stored_spelling_and_reported(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody()]);
        $http->queue(['{"id": 11, "amount": 2.13, "business": "Backblaze", "category": "Software"}']);

        $result = $this->tool($http)->createTransaction(2.13, '2026-10-02', 'backblaze', 'software');

        self::assertSame('Backblaze', $result['business']);
        self::assertSame('Software', $result['category']);

        $body = $http->jsonBody(3);
        self::assertSame('Backblaze', $body['business']);
        self::assertSame('Software', $body['category']);

        self::assertArrayHasKey('note', $result, 'a correction must be visible to the caller');
        self::assertStringContainsString('"backblaze" was written as "Backblaze"', $result['note']);
        self::assertStringContainsString('"software" was written as "Software"', $result['note']);
    }

    /**
     * The correction is what makes the duplicate check work: the caller's
     * spelling is resolved *before* the comparison, so a near miss on the second
     * call still matches the first call's stored value.
     */
    public function test_a_near_miss_still_finds_the_duplicate(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody('[{"id": 7, "amount": 2.13, "business": "Backblaze", "category": "Software", "created_at": "2026-10-02T09:00:00+00:00"}]')]);

        $this->expectException(DuplicateTransaction::class);

        $this->tool($http)->createTransaction(2.13, '2026-10-02', 'BACKBLAZE', 'software');
    }

    public function test_optional_location_and_notes_are_sent_when_given(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody()]);
        $http->queue(['{"id": 12}']);

        $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software', 'Online', 'Annual backup plan');

        $body = $http->jsonBody(3);
        self::assertSame('Online', $body['location']);
        self::assertSame('Annual backup plan', $body['notes']);
    }

    public function test_omitted_location_and_notes_are_absent_from_the_request(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody()]);
        $http->queue(['{"id": 12}']);

        $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');

        self::assertArrayNotHasKey('location', $http->jsonBody(3));
        self::assertArrayNotHasKey('notes', $http->jsonBody(3));
    }

    /**
     * A ledger with nothing in it is the first receipt on a new instance: the
     * vocabulary checks are skipped, because there is nothing to conform to and
     * refusing would make the first transaction unloggable. That is reported,
     * since it is the one moment a typo becomes permanent.
     */
    public function test_an_empty_ledger_accepts_the_first_receipt_and_says_so(): void
    {
        $http = new RecordingHttpClient();
        $http->queue(['[]', '[]']);
        $http->queue([$this->dayBody()]);
        $http->queue(['{"id": 1}']);

        $result = $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');

        self::assertTrue($result['created']);
        self::assertArrayHasKey('note', $result);
        self::assertStringContainsString('had no business yet', $result['note']);
        self::assertStringContainsString('had no category yet', $result['note']);
    }

    /**
     * An empty field does not disable the other one: with no businesses yet the
     * business is passed through, and the category is still checked.
     */
    public function test_an_empty_business_ledger_still_guards_the_category(): void
    {
        $http = new RecordingHttpClient();
        $http->queue(['[]', self::CATEGORIES]);

        try {
            $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Softwre');
            self::fail('expected a vocabulary refusal');
        } catch (VocabularyMiss $e) {
            self::assertSame('category', $e->field);
        }

        self::assertCount(2, $http->calls);
    }

    public function test_rejects_a_non_calendar_date(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(InvalidArgumentException::class);

        $this->tool($http)->createTransaction(2.13, '2026-02-30', 'Backblaze', 'Software');
    }

    public function test_rejects_a_timestamp_where_a_date_belongs(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM-DD');

        $this->tool($http)->createTransaction(2.13, '2026-10-02T09:00:00+00:00', 'Backblaze', 'Software');
    }

    public function test_rejects_a_zero_or_negative_amount(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than zero');

        $this->tool($http)->createTransaction(0.0, '2026-10-02', 'Backblaze', 'Software');
    }

    /**
     * Sub-cent precision is refused rather than rounded, because a rounded
     * receipt is the ledger disagreeing with the thing that was paid for.
     */
    public function test_rejects_sub_cent_precision(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(TransactionRefused::class);
        $this->expectExceptionMessage('whole number of cents');

        $this->tool($http)->createTransaction(2.135, '2026-10-02', 'Backblaze', 'Software');
    }

    public function test_rejects_an_empty_business(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);

        $this->expectException(InvalidArgumentException::class);

        $this->tool($http)->createTransaction(2.13, '2026-10-02', '   ', 'Software');
    }

    /**
     * A validation failure from the ledger is surfaced as the ledger's own
     * per-field reason rather than a bare status, because a caller has to be
     * able to act on it.
     */
    public function test_reports_the_ledgers_own_validation_errors(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody()]);
        $http->queueWithStatus('{"errors": {"category": "This value is too long."}}', 422);

        try {
            $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');
            self::fail('expected a refusal');
        } catch (TransactionRefused $e) {
            self::assertStringContainsString('category', $e->getMessage());
            self::assertStringContainsString('This value is too long', $e->getMessage());
        }
    }

    /**
     * penny-track's own duplicate guard fires inside a five-minute window and is
     * passed through as a refusal, distinct from this tool's same-day rule.
     */
    public function test_passes_through_the_ledgers_own_duplicate_guard(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody()]);
        $http->queueWithStatus('{"error": "A receipt with the same amount, business, and category was logged within the last 5 minutes."}', 409);

        try {
            $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');
            self::fail('expected a refusal');
        } catch (TransactionRefused $e) {
            self::assertStringContainsString('within the last 5 minutes', $e->getMessage());
        }
    }

    /**
     * A refused write key names the write variable, not the read one — the
     * likeliest way to find out a key is read-only is to try to write with it.
     */
    public function test_a_refused_write_key_names_the_write_variable(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody()]);
        $http->queueWithStatus('{"error": "Invalid API key"}', 401);

        try {
            $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');
            self::fail('expected an auth failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('PENNYTRACK_WRITE_API_KEY', $e->getMessage());
            self::assertStringNotContainsString('PENNYTRACK_API_KEY (a read-only key is sufficient)', $e->getMessage());
        }
    }

    /**
     * And the read path names the read variable, so the two cannot be confused.
     */
    public function test_a_refused_read_key_names_the_read_variable(): void
    {
        $http = new RecordingHttpClient();
        $http->queueWithStatus('{"error": "Invalid API key"}', 401);

        try {
            $this->tool($http)->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');
            self::fail('expected an auth failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('PENNYTRACK_API_KEY', $e->getMessage());
        }
    }

    public function test_errors_clearly_when_the_write_key_is_unconfigured(): void
    {
        $http = new RecordingHttpClient();
        $this->queueVocabulary($http);
        $http->queue([$this->dayBody()]);

        $client = new PennyTrackClient(
            $http,
            new PennyTrackEndpoint('https://penny.example.com'),
            new PennyTrackCredentials('read-key', ''),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PENNYTRACK_WRITE_API_KEY');

        (new CreateTransactionTool($client))->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');
    }

    public function test_errors_clearly_when_the_url_is_unconfigured(): void
    {
        $http = new RecordingHttpClient();

        $client = new PennyTrackClient(
            $http,
            new PennyTrackEndpoint(''),
            new PennyTrackCredentials('read-key', 'write-key'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PENNYTRACK_URL');

        (new CreateTransactionTool($client))->createTransaction(2.13, '2026-10-02', 'Backblaze', 'Software');
    }
}
