<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerts;

use App\Alerts\PendingRequest;
use App\Alerts\RequestStatus;
use App\Alerts\RequestType;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The PendingRequest status machine: lazy expiry on read, answers that
 * outlive expiry, and the with*() copies that make those transitions
 * immutable.
 *
 * @internal
 *
 * @covers \App\Alerts\PendingRequest
 */
final class PendingRequestTest extends TestCase
{
    private const T0 = '2026-10-01 12:00:00';

    public function test_status_at_is_pending_before_expiry(): void
    {
        $request = $this->request(lifetimeSeconds: 600);

        self::assertSame(RequestStatus::Pending, $request->statusAt($this->at('+599 seconds')));
    }

    public function test_status_at_is_expired_from_the_exact_deadline(): void
    {
        $request = $this->request(lifetimeSeconds: 600);

        self::assertSame(RequestStatus::Expired, $request->statusAt($this->at('+600 seconds')));
    }

    public function test_answered_requests_stay_answered_past_expiry(): void
    {
        $answered = $this->request(lifetimeSeconds: 600)
            ->withAnswer('done', $this->at('+100 seconds'));

        self::assertSame(RequestStatus::Answered, $answered->statusAt($this->at('+601 seconds')));
    }

    public function test_with_answer_sets_status_answer_and_timestamp_immutably(): void
    {
        $request = $this->request();

        $answered = $request->withAnswer(true, $this->at('+5 seconds'));

        // Original untouched.
        self::assertSame(RequestStatus::Pending, $request->status);
        self::assertNull($request->answer);
        self::assertNull($request->answeredAt);

        // Copy carries everything plus the answer.
        self::assertSame($request->id, $answered->id);
        self::assertSame($request->question, $answered->question);
        self::assertSame(RequestStatus::Answered, $answered->status);
        self::assertTrue($answered->answer);
        self::assertSame($this->at('+5 seconds')->getTimestamp(), $answered->answeredAt?->getTimestamp());
    }

    public function test_with_status_replaces_only_the_status(): void
    {
        $request = $this->request();
        $expired = $request->withStatus(RequestStatus::Expired);

        self::assertSame(RequestStatus::Pending, $request->status);
        self::assertSame(RequestStatus::Expired, $expired->status);
        self::assertSame($request->id, $expired->id);
        self::assertSame($request->expiresAt->getTimestamp(), $expired->expiresAt->getTimestamp());
    }

    private function request(int $lifetimeSeconds = 600): PendingRequest
    {
        return new PendingRequest(
            id: 'test-id-0123456789abcdef',
            type: RequestType::Text,
            question: 'Which CI?',
            createdAt: $this->at('+0 seconds'),
            expiresAt: $this->at(\sprintf('+%d seconds', $lifetimeSeconds)),
        );
    }

    private function at(string $modifier): DateTimeImmutable
    {
        return new DateTimeImmutable(self::T0, new DateTimeZone('UTC'))->modify($modifier);
    }
}
