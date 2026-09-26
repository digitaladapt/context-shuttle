<?php

declare(strict_types=1);

namespace App\Alerts;

use DateTimeImmutable;

/**
 * One interactive request awaiting (or carrying) a human answer.
 *
 * Plain data, deliberately not a service: constructed by the ask tools,
 * persisted by {@see PendingRequestStore}, rendered by the /ask form, and
 * read back by the /inputs status endpoint. The id is the capability —
 * 128 random bits, URL-safe, single-use, expiring with the request.
 */
final readonly class PendingRequest
{
    public function __construct(
        public string $id,
        public RequestType $type,
        public string $question,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?string $placeholder = null,
        public string $confirmLabel = 'Yes',
        public string $dismissLabel = 'No',
        public RequestStatus $status = RequestStatus::Pending,
        public string|bool|null $answer = null,
        public ?DateTimeImmutable $answeredAt = null,
    ) {
    }

    /**
     * The request as it should be reported at `$now`: `pending` requests
     * past their expiry read as `expired`. Answers already captured are
     * never masked — an answered request stays answered past expiry.
     */
    public function statusAt(DateTimeImmutable $now): RequestStatus
    {
        if (RequestStatus::Pending === $this->status && $now >= $this->expiresAt) {
            return RequestStatus::Expired;
        }

        return $this->status;
    }

    public function withStatus(RequestStatus $status): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            question: $this->question,
            createdAt: $this->createdAt,
            expiresAt: $this->expiresAt,
            placeholder: $this->placeholder,
            confirmLabel: $this->confirmLabel,
            dismissLabel: $this->dismissLabel,
            status: $status,
            answer: $this->answer,
            answeredAt: $this->answeredAt,
        );
    }

    public function withAnswer(string|bool $answer, DateTimeImmutable $answeredAt): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            question: $this->question,
            createdAt: $this->createdAt,
            expiresAt: $this->expiresAt,
            placeholder: $this->placeholder,
            confirmLabel: $this->confirmLabel,
            dismissLabel: $this->dismissLabel,
            status: RequestStatus::Answered,
            answer: $answer,
            answeredAt: $answeredAt,
        );
    }
}
