<?php

declare(strict_types=1);

namespace App\Alerts;

use DateTimeImmutable;
use Exception;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persistence for interactive requests, backed by symfony/cache.
 *
 * Cache rather than a database, per docs/design/ALERTS.md: one deployment
 * serves one boss, request volume is tiny, and TTL semantics are exactly
 * what the store needs — no schema, no migrations, no Doctrine.
 *
 * The cache key is derived from the id (never the raw id): ids are
 * URL-safe random strings, but they are also capabilities, and cache
 * backends may log or index keys. Hashing keeps the capability out of
 * anywhere that isn't the request itself.
 *
 * Expiry is lazy: the cache TTL is `timeout_seconds` + a grace period so
 * a request that expires between polls can still report `expired` rather
 * than 404, and {@see PendingRequest::statusAt()} performs the logical
 * check on read. Nothing sweeps; if the store ever proves noisy, a
 * janitor command is the follow-up (documented in the design).
 */
final class PendingRequestStore
{
    /**
     * Extra cache lifetime beyond the request's own expiry, so an expired
     * request still reads as `expired` (rather than `not found`) for a
     * while after the deadline.
     */
    public const EXPIRY_GRACE_SECONDS = 3600;

    public function __construct(
        #[Autowire(service: 'interactive_requests')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Persist a new request. Its cache TTL is derived from the request's
     * own lifetime plus the grace period.
     *
     * @throws InvalidArgumentException when the id is not a valid key part
     */
    public function save(PendingRequest $request): void
    {
        $item = $this->cache->getItem($this->key($request->id));
        $item->set($this->serialize($request));
        $item->expiresAfter($this->ttlSeconds($request));
        $this->cache->save($item);
    }

    /**
     * Fetch a request by id, as it should be reported at `$now` — a
     * pending request past its expiry is returned with status `expired`,
     * and an answered request stays answered.
     *
     * @throws InvalidArgumentException when the id is not a valid key part
     */
    public function find(string $id, DateTimeImmutable $now): ?PendingRequest
    {
        $request = $this->fetch($id);
        if (null === $request) {
            return null;
        }

        return $request->withStatus($request->statusAt($now));
    }

    /**
     * Record an answer, honouring single-use and expiry semantics:
     *
     *  - unknown id → NotFound
     *  - pending past expiry → Expired (answer discarded)
     *  - already answered → AlreadyAnswered (first answer wins)
     *  - otherwise → Answered, stored
     *
     * @throws InvalidArgumentException when the id is not a valid key part
     */
    public function answer(string $id, string|bool $answer, DateTimeImmutable $now): AnswerResult
    {
        $item = $this->cache->getItem($this->key($id));
        if (!$item->isHit()) {
            return AnswerResult::NotFound;
        }

        $request = $this->deserialize($item->get());
        if (null === $request) {
            return AnswerResult::NotFound;
        }

        return match ($request->statusAt($now)) {
            RequestStatus::Expired => AnswerResult::Expired,
            RequestStatus::Answered => AnswerResult::AlreadyAnswered,
            RequestStatus::Pending => $this->storeAnswer($item, $request, $answer, $now),
        };
    }

    private function fetch(string $id): ?PendingRequest
    {
        if (!$this->isWellFormedId($id)) {
            return null;
        }

        $item = $this->cache->getItem($this->key($id));
        if (!$item->isHit()) {
            return null;
        }

        return $this->deserialize($item->get());
    }

    private function storeAnswer(
        CacheItemInterface $item,
        PendingRequest $request,
        string|bool $answer,
        DateTimeImmutable $now,
    ): AnswerResult {
        $answered = $request->withAnswer($answer, $now);

        $item->set($this->serialize($answered));
        // Measured from the original expiry, so an answered request does
        // not become immortal — it lives until expiry + grace like any
        // other record.
        $item->expiresAfter($this->ttlSeconds($answered));
        $this->cache->save($item);

        return AnswerResult::Answered;
    }

    /**
     * Cache TTL from the request's own lifetime: the full lifetime plus
     * grace. Computed from `createdAt`/`expiresAt` rather than "now" so
     * the value is stable under test clocks.
     */
    private function ttlSeconds(PendingRequest $request): int
    {
        $lifetime = $request->expiresAt->getTimestamp() - $request->createdAt->getTimestamp();

        return max(1, $lifetime + self::EXPIRY_GRACE_SECONDS);
    }

    /**
     * Derive the cache key from the id: SHA-256 of a namespaced id, so the
     * capability itself never sits in a cache backend's key space.
     */
    private function key(string $id): string
    {
        return 'interactive_request_'.hash('sha256', $id);
    }

    /**
     * Ids are minted by PendingRequestId (32 chars of URL-safe base64).
     * The shape check rejects anything else before it reaches the cache
     * backend, whose valid-key rules are lenient to the point of
     * accepting surprises.
     */
    private function isWellFormedId(string $id): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_\-]{22,64}$/', $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PendingRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->type->value,
            'question' => $request->question,
            'placeholder' => $request->placeholder,
            'confirm_label' => $request->confirmLabel,
            'dismiss_label' => $request->dismissLabel,
            'created_at' => $request->createdAt->format(DateTimeImmutable::ATOM),
            'expires_at' => $request->expiresAt->format(DateTimeImmutable::ATOM),
            'status' => $request->status->value,
            'answer' => $request->answer,
            'answered_at' => $request->answeredAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    private function deserialize(mixed $raw): ?PendingRequest
    {
        if (!\is_array($raw)
            || !isset($raw['id'], $raw['type'], $raw['question'], $raw['created_at'], $raw['expires_at'], $raw['status'])
            || !\is_string($raw['id'])
            || !\is_string($raw['question'])
            || !\is_string($raw['created_at'])
            || !\is_string($raw['expires_at'])
            || !\is_string($raw['type'])
            || !\is_string($raw['status'])
        ) {
            return null;
        }

        $type = RequestType::tryFrom($raw['type']);
        $status = RequestStatus::tryFrom($raw['status']);
        if (null === $type || null === $status) {
            return null;
        }

        try {
            $createdAt = new DateTimeImmutable($raw['created_at']);
            $expiresAt = new DateTimeImmutable($raw['expires_at']);
            $answeredAt = isset($raw['answered_at']) && \is_string($raw['answered_at'])
                ? new DateTimeImmutable($raw['answered_at'])
                : null;
        } catch (Exception) {
            return null;
        }

        $answer = $raw['answer'] ?? null;
        if (null !== $answer && !\is_string($answer) && !\is_bool($answer)) {
            return null;
        }

        return new PendingRequest(
            id: $raw['id'],
            type: $type,
            question: $raw['question'],
            createdAt: $createdAt,
            expiresAt: $expiresAt,
            placeholder: isset($raw['placeholder']) && \is_string($raw['placeholder']) ? $raw['placeholder'] : null,
            confirmLabel: isset($raw['confirm_label']) && \is_string($raw['confirm_label']) ? $raw['confirm_label'] : 'Yes',
            dismissLabel: isset($raw['dismiss_label']) && \is_string($raw['dismiss_label']) ? $raw['dismiss_label'] : 'No',
            status: $status,
            answer: $answer,
            answeredAt: $answeredAt,
        );
    }
}
