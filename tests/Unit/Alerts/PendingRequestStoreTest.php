<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerts;

use App\Alerts\AnswerResult;
use App\Alerts\PendingRequest;
use App\Alerts\PendingRequestId;
use App\Alerts\PendingRequestStore;
use App\Alerts\RequestStatus;
use App\Alerts\RequestType;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\ItemInterface as CacheItemInterface;

/**
 * The interactive-request store lifecycle, pinned directly: pending →
 * answered, single-use, lazy expiry, and the not-found-too paths.
 *
 * Uses a real ArrayAdapter rather than a mock — the store's contract is
 * about what a cache does with it (TTL, serialization round-trip), and a
 * mock would encode my assumptions instead of testing them. ArrayAdapter
 * also accepts the same clock as the store's window arithmetic.
 *
 * @internal
 *
 * @covers \App\Alerts\PendingRequestStore
 */
final class PendingRequestStoreTest extends TestCase
{
    private const T0 = '2026-10-01 12:00:00';

    private ?ArrayAdapter $cache = null;

    private function cache(): ArrayAdapter
    {
        if (null === $this->cache) {
            $this->cache = new ArrayAdapter();
        }

        return $this->cache;
    }

    private function store(): PendingRequestStore
    {
        return new PendingRequestStore($this->cache());
    }

    public function test_saves_and_finds_a_pending_request(): void
    {
        $id = PendingRequestId::generate();
        $this->store()->save($this->request($id));

        $found = $this->store()->find($id, $this->at('+0 seconds'));

        self::assertNotNull($found);
        self::assertSame($id, $found->id);
        self::assertSame(RequestType::Text, $found->type);
        self::assertSame('Which CI?', $found->question);
        self::assertSame('type here', $found->placeholder);
        self::assertSame(RequestStatus::Pending, $found->status);
    }

    public function test_unknown_id_is_null(): void
    {
        self::assertNull($this->store()->find('nonexistent-id-000000', $this->at('+0 seconds')));
    }

    public function test_malformed_ids_are_rejected_without_touching_the_cache(): void
    {
        // Too short, invalid characters, empty — none may reach the cache
        // backend, whose own key rules are lenient enough to accept nasty
        // things like braces and parens.
        foreach (['short', 'has spaces here!!!', '', 'a/b+c=d'] as $malformed) {
            self::assertNull($this->store()->find($malformed, $this->at('+0 seconds')));
            self::assertSame(AnswerResult::NotFound, $this->store()->answer($malformed, 'x', $this->at('+0 seconds')));
        }
    }

    public function test_answering_records_the_answer(): void
    {
        $id = PendingRequestId::generate();
        $this->store()->save($this->request($id));

        $result = $this->store()->answer($id, 'Just this one.', $this->at('+5 seconds'));

        self::assertSame(AnswerResult::Answered, $result);

        $found = $this->store()->find($id, $this->at('+6 seconds'));
        self::assertNotNull($found);
        self::assertSame(RequestStatus::Answered, $found->status);
        self::assertSame('Just this one.', $found->answer);
        self::assertNotNull($found->answeredAt);
        self::assertSame($this->at('+5 seconds')->getTimestamp(), $found->answeredAt->getTimestamp());
    }

    public function test_first_answer_wins(): void
    {
        $id = PendingRequestId::generate();
        $this->store()->save($this->request($id));

        self::assertSame(AnswerResult::Answered, $this->store()->answer($id, 'first', $this->at('+5 seconds')));
        self::assertSame(AnswerResult::AlreadyAnswered, $this->store()->answer($id, 'second', $this->at('+6 seconds')));

        $found = $this->store()->find($id, $this->at('+7 seconds'));
        self::assertSame('first', $found?->answer);
    }

    public function test_expiry_is_lazy_and_reported_on_read(): void
    {
        $id = PendingRequestId::generate();
        // 60-second lifetime.
        $this->store()->save($this->request($id, lifetimeSeconds: 60));

        self::assertSame(RequestStatus::Pending, $this->store()->find($id, $this->at('+59 seconds'))?->status);
        self::assertSame(RequestStatus::Expired, $this->store()->find($id, $this->at('+60 seconds'))?->status);
        self::assertSame(RequestStatus::Expired, $this->store()->find($id, $this->at('+61 seconds'))?->status);
    }

    public function test_an_expired_request_refuses_late_answers(): void
    {
        $id = PendingRequestId::generate();
        $this->store()->save($this->request($id, lifetimeSeconds: 60));

        self::assertSame(AnswerResult::Expired, $this->store()->answer($id, 'too late', $this->at('+61 seconds')));

        // And the refusal is not a silent success: still expired, no answer.
        $found = $this->store()->find($id, $this->at('+62 seconds'));
        self::assertNotNull($found);
        self::assertSame(RequestStatus::Expired, $found->status);
        self::assertNull($found->answer);
    }

    public function test_an_answered_request_stays_answered_past_expiry(): void
    {
        $id = PendingRequestId::generate();
        $this->store()->save($this->request($id, lifetimeSeconds: 60));

        $this->store()->answer($id, 'made it', $this->at('+30 seconds'));

        $found = $this->store()->find($id, $this->at('+90 seconds'));
        self::assertNotNull($found);
        self::assertSame(RequestStatus::Answered, $found->status);
        self::assertSame('made it', $found->answer);
    }

    public function test_confirm_answers_round_trip_as_bools(): void
    {
        $id = PendingRequestId::generate();
        $this->store()->save($this->request($id, type: RequestType::Confirm, lifetimeSeconds: 600));

        self::assertSame(AnswerResult::Answered, $this->store()->answer($id, true, $this->at('+5 seconds')));

        $found = $this->store()->find($id, $this->at('+6 seconds'));
        self::assertTrue($found?->answer);
    }

    public function test_confirm_labels_round_trip(): void
    {
        $id = PendingRequestId::generate();
        $this->store()->save(new PendingRequest(
            id: $id,
            type: RequestType::Confirm,
            question: 'Deploy?',
            createdAt: $this->at('+0 seconds'),
            expiresAt: $this->at('+600 seconds'),
            confirmLabel: 'Deploy it',
            dismissLabel: 'Not yet',
        ));

        $found = $this->store()->find($id, $this->at('+1 seconds'));
        self::assertNotNull($found);
        self::assertSame('Deploy it', $found->confirmLabel);
        self::assertSame('Not yet', $found->dismissLabel);
    }

    public function test_saving_updates_an_existing_record(): void
    {
        $id = PendingRequestId::generate();
        $this->store()->save($this->request($id));

        // Re-save same id with a different question — the store must
        // overwrite, not append.
        $this->store()->save(new PendingRequest(
            id: $id,
            type: RequestType::Text,
            question: 'A better question?',
            createdAt: $this->at('+0 seconds'),
            expiresAt: $this->at('+600 seconds'),
        ));

        self::assertSame('A better question?', $this->store()->find($id, $this->at('+1 seconds'))?->question);
    }

    public function test_the_raw_cache_key_does_not_contain_the_id(): void
    {
        // The id is a capability; cache backends may log keys, so the key
        // must be derived, not the literal id.
        $id = PendingRequestId::generate();
        $this->store()->save($this->request($id));

        $keys = array_keys($this->cache()->getValues());

        self::assertNotEmpty($keys);
        foreach ($keys as $key) {
            self::assertStringNotContainsString($id, $key);
        }
    }

    public function test_record_ttl_outlives_logical_expiry_by_the_grace_window(): void
    {
        // The cache TTL must outlive the request's own expiry (so an
        // expired request still reads as `expired`, not `not found`), and
        // not outlive it by more than the grace window. Asserted through
        // the cache's public metadata rather than private state.
        $id = PendingRequestId::generate();
        $this->store()->save($this->request($id, lifetimeSeconds: 600));

        $item = $this->cache()->getItem('interactive_request_'.hash('sha256', $id));
        $expiry = $item->getMetadata()[CacheItemInterface::METADATA_EXPIRY];

        $expected = time() + 600 + PendingRequestStore::EXPIRY_GRACE_SECONDS;
        self::assertGreaterThanOrEqual($expected - 5, (int) $expiry);
        self::assertLessThanOrEqual($expected + 5, (int) $expiry);
    }

    private function request(
        string $id,
        RequestType $type = RequestType::Text,
        int $lifetimeSeconds = 600,
    ): PendingRequest {
        return new PendingRequest(
            id: $id,
            type: $type,
            question: 'Which CI?',
            createdAt: $this->at('+0 seconds'),
            expiresAt: $this->at(\sprintf('+%d seconds', $lifetimeSeconds)),
            placeholder: RequestType::Text === $type ? 'type here' : null,
        );
    }

    private function at(string $modifier): DateTimeImmutable
    {
        return new DateTimeImmutable(self::T0, new DateTimeZone('UTC'))->modify($modifier);
    }
}
