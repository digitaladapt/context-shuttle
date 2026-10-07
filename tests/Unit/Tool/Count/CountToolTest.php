<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Count;

use App\Mcp\StreamingIntent;
use App\Tool\Count\CountResource;
use App\Tool\Count\CountStream;
use App\Tool\Count\CountTool;
use Fiber;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Notification\ProgressNotification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\ClientGateway;
use Mcp\Server\Protocol;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * The streaming example, exercised the way the transport exercises it.
 *
 * `ClientGateway::notify()` suspends the fiber it is called from, and that
 * suspension is exactly what makes the transport choose SSE over a buffered
 * body. So the assertions here run the tool inside a real fiber and collect
 * what it yields, which is the same sequence of frames a client would receive —
 * a mock that merely counted calls would not prove the tool ever suspends, and
 * a tool that does not suspend does not stream.
 *
 * `MockClock` in place of the real one means the seconds a run spends sleeping
 * are asserted, not waited: a test that slept for real would be slower than the
 * thing it tests.
 *
 * @internal
 *
 * @covers \App\Tool\Count\CountTool
 */
final class CountToolTest extends TestCase
{
    /**
     * @return array{notifications: list<object>, result: array<string, mixed>, elapsed: float}
     */
    private function collect(int $count, bool $notify, ?RequestContext $context, ?SessionInterface $session = null): array
    {
        $clock = new MockClock('2026-01-01 00:00:00');
        $started = $clock->now();
        $tool = new CountTool(new CountStream(new ArrayAdapter()), $clock);

        // A context whose gateway is built from the session under test, so the
        // yielded payloads carry the same session id the transport would see.
        if (null !== $context && null !== $session) {
            $gateway = new ClientGateway($session);
            $property = new ReflectionProperty(RequestContext::class, 'clientGateway');
            $property->setValue($context, $gateway);
        }

        $notifications = [];
        $fiber = new Fiber(static fn (): array => $tool->count($count, $notify, $context));
        $yielded = $fiber->start();

        while ($fiber->isSuspended()) {
            $notifications[] = $yielded['notification'];
            $yielded = $fiber->resume();
        }

        return [
            'notifications' => $notifications,
            'result' => $fiber->getReturn(),
            'elapsed' => (float) $clock->now()->format('U') - (float) $started->format('U'),
        ];
    }

    /**
     * A context carrying a progress token, which is what makes progress
     * notifications sendable at all.
     */
    /**
     * @param array<string, mixed>|null $meta
     */
    private function context(SessionInterface $session, ?array $meta = null): RequestContext
    {
        $request = new CallToolRequest('count', []);
        $request = $request->withMeta($meta ?? ['progressToken' => 'tok']);

        $context = new RequestContext($session, $request);

        // The SDK records this when it dispatches the request; `progress()`
        // reads it back to find the token, so a hand-built context needs it too.
        $session->set(Protocol::SESSION_ACTIVE_REQUEST_META, $request->getMeta());

        return $context;
    }

    public function test_counts_to_the_requested_number(): void
    {
        $session = new Session(new InMemorySessionStore());
        $out = $this->collect(6, false, $this->context($session), $session);

        self::assertSame([1, 2, 3, 4, 5, 6], $out['result']['lines']);
        self::assertTrue($out['result']['streamed']);
    }

    public function test_it_waits_one_second_between_lines_and_not_after_the_last(): void
    {
        $session = new Session(new InMemorySessionStore());
        $out = $this->collect(4, false, $this->context($session), $session);

        // Three gaps for four lines: sleeping after the last line would make a
        // run one second slower than the work it represents.
        self::assertSame(3.0, $out['elapsed']);
    }

    public function test_it_emits_progress_for_each_line(): void
    {
        $session = new Session(new InMemorySessionStore());
        $out = $this->collect(3, false, $this->context($session), $session);

        $progress = array_values(array_filter(
            $out['notifications'],
            static fn (object $n): bool => $n instanceof ProgressNotification,
        ));

        self::assertCount(3, $progress);
        self::assertSame([1.0, 2.0, 3.0], array_map(static fn (ProgressNotification $n): float => $n->progress, $progress));
        self::assertSame([3.0, 3.0, 3.0], array_map(static fn (ProgressNotification $n): float => $n->total, $progress));
    }

    public function test_notify_false_emits_no_resource_notifications(): void
    {
        $session = new Session(new InMemorySessionStore());
        $out = $this->collect(3, false, $this->context($session), $session);

        self::assertCount(0, array_filter(
            $out['notifications'],
            static fn (object $n): bool => $n instanceof ResourceUpdatedNotification,
        ));
    }

    public function test_notify_true_emits_one_resource_notification_per_line(): void
    {
        $session = new Session(new InMemorySessionStore());
        $out = $this->collect(3, true, $this->context($session), $session);

        $updated = array_values(array_filter(
            $out['notifications'],
            static fn (object $n): bool => $n instanceof ResourceUpdatedNotification,
        ));

        self::assertCount(3, $updated);

        // A URI and nothing else: the payload is the resource, and the client
        // is expected to come and read it.
        foreach ($updated as $notification) {
            self::assertSame(CountResource::URI, $notification->uri);
        }
    }

    /**
     * With no client to talk to, the counting still happens.
     *
     * This is the REST path's shape seen from the tool's side: a caller that
     * cannot be streamed to still gets the answer, it just gets no narration.
     */
    public function test_without_a_context_it_still_counts_but_does_not_stream(): void
    {
        $out = $this->collect(4, true, null);

        self::assertSame([1, 2, 3, 4], $out['result']['lines']);
        self::assertFalse($out['result']['streamed']);
        self::assertCount(0, $out['notifications']);

        // And it does not wait: there is nothing to watch, so there is nothing
        // to slow down for.
        self::assertSame(0.0, $out['elapsed']);
    }

    /**
     * The REST case, decided by the flag rather than by the absence of a
     * context: a real gateway exists and must be left alone.
     */
    public function test_streaming_intent_false_suppresses_every_notification(): void
    {
        $session = new Session(new InMemorySessionStore());
        $context = $this->context($session, [StreamingIntent::META_KEY => false]);

        $out = $this->collect(4, true, $context, $session);

        self::assertFalse($out['result']['streamed']);
        self::assertSame([1, 2, 3, 4], $out['result']['lines']);
        self::assertCount(0, $out['notifications']);
        self::assertSame(0.0, $out['elapsed']);
    }

    /**
     * The lines are recorded whether or not anyone was told about them, so the
     * resource is still readable after a non-streamed (REST) run.
     */
    public function test_the_run_is_recorded_even_when_not_streamed(): void
    {
        $stream = new CountStream(new ArrayAdapter());
        $session = new Session(new InMemorySessionStore());
        $clock = new MockClock();

        $tool = new CountTool($stream, $clock);
        $context = $this->context($session, [StreamingIntent::META_KEY => false]);
        $tool->count(3, true, $context);

        self::assertSame([1, 2, 3], $stream->run($session)['emitted']);
    }

    public function test_progress_is_skipped_when_the_client_sent_no_token(): void
    {
        $session = new Session(new InMemorySessionStore());
        // No progressToken: the SDK's `progress()` is a no-op, which is why
        // `notify` exists — it is the channel that does not need the client to
        // have opted in.
        $context = $this->context($session, meta: []);

        $out = $this->collect(3, true, $context, $session);

        self::assertCount(0, array_filter(
            $out['notifications'],
            static fn (object $n): bool => $n instanceof ProgressNotification,
        ));
        self::assertCount(3, array_filter(
            $out['notifications'],
            static fn (object $n): bool => $n instanceof ResourceUpdatedNotification,
        ));
    }

    public function test_defaults_are_five_and_no_notify(): void
    {
        $session = new Session(new InMemorySessionStore());
        $out = $this->collect(5, false, $this->context($session), $session);

        self::assertSame(5, $out['result']['count']);
        self::assertFalse($out['result']['notify']);
        self::assertSame([1, 2, 3, 4, 5], $out['result']['lines']);
    }

    /**
     * The tool reads its intent from the request it was handed, and nothing
     * else — so two callers sharing a process cannot affect one another.
     */
    public function test_one_callers_intent_does_not_leak_into_another(): void
    {
        $stream = new CountStream(new ArrayAdapter());

        $restSession = new Session(new InMemorySessionStore());
        $restContext = $this->context($restSession, [StreamingIntent::META_KEY => false]);

        $mcpSession = new Session(new InMemorySessionStore());
        $mcpContext = $this->context($mcpSession);

        $rest = $this->collect(2, true, $restContext, $restSession);
        $mcp = $this->collect(2, true, $mcpContext, $mcpSession);

        self::assertFalse($rest['result']['streamed']);
        self::assertTrue($mcp['result']['streamed']);
    }
}
