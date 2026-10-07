<?php

declare(strict_types=1);

namespace App\Tool\Count;

use App\Mcp\StreamingIntent;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\RequestContext;
use Symfony\Component\Clock\ClockInterface;

/**
 * A tool whose only purpose is to be watched while it works.
 *
 * `echo` proves the pipeline end to end with no dependencies; `count` does the
 * same for the *streaming* half of it. It counts from one to `count`, one
 * number a second, and spends each second telling the client what it is doing —
 * which is the only way to exercise a code path that, by definition, produces
 * output before it produces a result.
 *
 * Nothing about it is useful, and that is the point: it holds no configuration,
 * reaches no network, and has no opinion about the world, so a failure in it is
 * a failure in the transport rather than in an integration.
 *
 * ## What goes where
 *
 * Three channels, each carrying one kind of fact, because the protocol gives
 * three and using one for everything would make the client's job guesswork:
 *
 * | Channel | Carries | Means |
 * |---|---|---|
 * | result | every line, once, at the end | the answer |
 * | `notifications/progress` | a percentage | how far along this call is |
 * | `notifications/resources/updated` | a URI | "the `count://stream` resource changed" |
 *
 * The numbers themselves live in the resource, not in the notification. That is
 * forced by the spec — `resources/updated` carries only a URI — and it is also
 * the right split: a notification that carried the payload would have to be
 * accumulated by the client to be read, whereas a resource can be read whole
 * whenever the client cares to, and the read reports how many lines are left.
 *
 * ## Why `notify` exists
 *
 * Progress is gated by the client: `ClientGateway::progress()` is a no-op unless
 * the request carried a `progressToken`, because a server must not send progress
 * to a client that did not ask for it. So on its own, `count` streams nothing to
 * a client that did not opt in — which would make it a poor demonstration and a
 * poor test.
 *
 * `notify` is the tool's own opt-in and drives the resource channel instead. It
 * is deliberately **not** gated on the client having subscribed to
 * `count://stream`: this is a transport fixture, and its job is to put frames on
 * the wire for anyone who asks, from a one-line `curl` up. A server of real
 * resources SHOULD send `resources/updated` only to subscribers, and the check
 * that would enforce it (`$context->getSession()->get('resource_subscriptions')`,
 * the same store `SessionSubscriptionManager` writes) belongs here the day this
 * stops being a test tool — see the note in
 * `docs/examples/streaming-count-tool.md`.
 */
final class CountTool
{
    /**
     * Seconds between lines.
     *
     * One, because a demonstration that takes a minute to watch is a
     * demonstration nobody watches. Real work would space its notifications by
     * progress rather than by the clock.
     */
    private const TICK_SECONDS = 1;

    public function __construct(
        private readonly CountStream $stream,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Count to `count`, one number a second.
     *
     * `$context` is injected by the SDK for every `tools/call`; it is nullable
     * here only so the method can be called directly in a unit test without
     * building a session. A null context means "no client", which behaves
     * exactly as a client that cannot be streamed to: the counting still
     * happens, it just has nowhere to be announced.
     *
     * `count`'s 1–20 range is enforced by the input schema generated from
     * `count.yaml`, not here — validation has one home in this project, and a
     * second copy of the range is a second copy to keep in step.
     *
     * @return array{count: int, notify: bool, streamed: bool, lines: list<int>}
     */
    public function count(int $count = 5, bool $notify = false, ?RequestContext $context = null): array
    {
        // Whether to announce anything at all. A REST caller says no (see
        // StreamingIntent): it has one JSON body to return and cannot be sent an
        // event stream, so a notification from here would not be a message
        // delivered — it would be a message that corrupts the response.
        $streaming = null !== $context && StreamingIntent::allowed($context);
        $gateway = $streaming ? $context->getClientGateway() : null;

        // Declare the run before the first line, so a client that reads the
        // resource mid-run sees this run's total rather than the previous one's.
        // The session is the store here rather than a property of this service:
        // the resource read is a separate request carrying the same session id,
        // so anything held in memory would be invisible to it.
        if (null !== $context) {
            $this->stream->begin($context->getSession(), $count);
        }

        $lines = [];
        for ($number = 1; $number <= $count; ++$number) {
            // State first, announcement second. A client that reads the resource
            // the instant `resources/updated` arrives must find the line the
            // notification was about, so it has to be recorded before the
            // doorbell rings.
            if (null !== $context) {
                $this->stream->emit($context->getSession(), $number);
            }
            $lines[] = $number;

            if (null !== $gateway) {
                // Percentage, and only percentage: the line itself is in the
                // resource and in the final result, and a `progress` message
                // repeating it would be a second place for the same fact to go
                // wrong. A no-op unless the client sent a `progressToken`.
                $gateway->progress(
                    progress: $number,
                    total: $count,
                    message: \sprintf('%d of %d', $number, $count),
                );

                if ($notify) {
                    // A URI and nothing else: this says the resource changed and
                    // the client reads it to find out how. The read itself
                    // reports the emitted count and how many lines are left.
                    $gateway->notify(new ResourceUpdatedNotification(CountResource::URI));
                }
            }

            // Between lines, not after the last one: a trailing second would
            // make every run one second slower than the work it represents.
            // Only when streaming: a REST caller has nothing to watch and no
            // reason to wait, so its run is immediate.
            if ($number < $count && $streaming) {
                $this->clock->sleep(self::TICK_SECONDS);
            }
        }

        return [
            'count' => $count,
            'notify' => $notify,
            'streamed' => $streaming,
            'lines' => $lines,
        ];
    }
}
