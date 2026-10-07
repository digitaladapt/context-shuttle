<?php

declare(strict_types=1);

namespace App\Tool\Count;

use LogicException;
use Mcp\Server\Session\SessionInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The lines a `count` run has emitted so far, keyed by session.
 *
 * The tool and the resource are **two different HTTP requests**. The tool call
 * ends when it ends; the client then reads `count://stream` on a later request,
 * carrying the same `Mcp-Session-Id`. So the numbers have to outlive the call,
 * and a property of a shared service will not do: under a worker that serves
 * two sessions, one caller's run would overwrite the other's lines.
 *
 * ## Why a cache pool and not the session
 *
 * The obvious home is the session, and it is the wrong one. The SDK keeps its
 * own per-request state on the session — `_mcp.outgoing_queue`, the queue that
 * notifications are flushed from — and the two writers collide badly:
 * `Session::set()` only touches an in-memory copy that `save()` then writes
 * wholesale, so a `save()` from inside the tool re-writes the queue as the
 * tool's copy of the session remembers it, resurrecting notifications the
 * transport has already consumed. The symptom is that every notification is
 * re-sent on every subsequent tick.
 *
 * A separate pool removes the conflict rather than trying to schedule around
 * it: this class writes its own key and never touches `_mcp.outgoing_queue`,
 * and the SDK's queue semantics are left alone. The session is used only for
 * its id — read, never written — which is the one thing the two requests
 * genuinely share.
 *
 * The pool is a named one (`count_stream`) rather than `cache.app` so this
 * fixture's state can be flushed on its own, and so it can be pointed at a
 * shared backend for a multi-worker deployment, exactly like `mcp_sessions`.
 */
final class CountStream
{
    /** Long enough to outlive any run a client will read back; matches the session TTL. */
    private const TTL_SECONDS = 3600;

    private const KEY_PREFIX = 'count-stream-';

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * Starts a new run, discarding whatever the previous one left.
     */
    public function begin(SessionInterface $session, int $total): void
    {
        $this->write($session, ['emitted' => [], 'total' => $total]);
    }

    /**
     * Records one emitted line.
     *
     * Written through immediately, because the client may read the resource
     * *while the tool is still running* — that is the whole point of the
     * notification, and it is a separate request that loads its own copy.
     *
     * @throws LogicException if called before {@see self::begin()}, which is
     *                        the only way the remaining count could be wrong
     */
    public function emit(SessionInterface $session, int $number): void
    {
        $run = $this->run($session);

        if (0 === $run['total'] && [] === $run['emitted']) {
            throw new LogicException('CountStream::begin() must be called before the first emit().');
        }

        $run['emitted'][] = $number;
        $this->write($session, $run);
    }

    /**
     * @return array{emitted: list<int>, total: int}
     */
    public function run(SessionInterface $session): array
    {
        $item = $this->pool->getItem($this->key($session));

        if (!$item->isHit()) {
            // No run for this session — a first read, or a process that
            // restarted between the call and the read. An empty stream is the
            // truthful answer and the one a client's first read should meet.
            return ['emitted' => [], 'total' => 0];
        }

        $run = $item->get();

        if (!\is_array($run) || !isset($run['emitted'], $run['total']) || !\is_array($run['emitted'])) {
            return ['emitted' => [], 'total' => 0];
        }

        return [
            'emitted' => array_values(array_map(intval(...), $run['emitted'])),
            'total' => (int) $run['total'],
        ];
    }

    /**
     * How many lines the run still owes.
     *
     * `max(0, …)` rather than plain subtraction because "how many are left" has
     * a truthful answer even if a caller emits past the declared total: none.
     */
    public function remaining(SessionInterface $session): int
    {
        $run = $this->run($session);

        return max(0, $run['total'] - \count($run['emitted']));
    }

    /**
     * @param array{emitted: list<int>, total: int} $run
     */
    private function write(SessionInterface $session, array $run): void
    {
        $item = $this->pool->getItem($this->key($session));
        $item->set($run);
        $item->expiresAfter(self::TTL_SECONDS);

        $this->pool->save($item);
    }

    /**
     * The session id, not the session: it is the only identifier both requests
     * share, and using it keeps this store's keys independent of how the SDK
     * serializes its own session data.
     */
    private function key(SessionInterface $session): string
    {
        return self::KEY_PREFIX.$session->getId()->toRfc4122();
    }
}
