<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Count;

use App\Tool\Count\CountStream;
use LogicException;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The store behind `count://stream`.
 *
 * Runs against a real cache pool rather than a mock, because the behaviour that
 * matters here is what survives a round trip through it — and, above all, that
 * a second session's run cannot overwrite a first one's.
 *
 * @internal
 *
 * @covers \App\Tool\Count\CountStream
 */
final class CountStreamTest extends TestCase
{
    private function stream(): CountStream
    {
        return new CountStream(new ArrayAdapter());
    }

    private function session(): SessionInterface
    {
        return new Session(new InMemorySessionStore());
    }

    public function test_a_session_with_no_run_reports_an_empty_stream(): void
    {
        $run = $this->stream()->run($this->session());

        self::assertSame([], $run['emitted']);
        self::assertSame(0, $run['total']);
    }

    public function test_emitting_is_recorded_in_order(): void
    {
        $stream = $this->stream();
        $session = $this->session();

        $stream->begin($session, 3);
        $stream->emit($session, 1);
        $stream->emit($session, 2);

        self::assertSame([1, 2], $stream->run($session)['emitted']);
        self::assertSame(1, $stream->remaining($session));
    }

    public function test_emitting_before_beginning_is_refused(): void
    {
        $stream = $this->stream();

        $this->expectException(LogicException::class);
        $stream->emit($this->session(), 1);
    }

    public function test_beginning_again_discards_the_previous_run(): void
    {
        $stream = $this->stream();
        $session = $this->session();

        $stream->begin($session, 2);
        $stream->emit($session, 1);
        $stream->emit($session, 2);

        $stream->begin($session, 5);

        // The interesting question is what the run just started has produced,
        // not a tally of every run this session has ever made.
        self::assertSame([], $stream->run($session)['emitted']);
        self::assertSame(5, $stream->remaining($session));
    }

    public function test_two_sessions_do_not_see_each_other(): void
    {
        $stream = $this->stream();
        $first = $this->session();
        $second = $this->session();

        $stream->begin($first, 2);
        $stream->emit($first, 1);
        $stream->emit($first, 2);

        // The second caller must not inherit the first caller's lines — the
        // failure this store exists to avoid, and the reason it is keyed by
        // session rather than held in a property.
        self::assertSame([], $stream->run($second)['emitted']);
        self::assertSame(0, $stream->remaining($second));
    }

    public function test_remaining_never_goes_negative(): void
    {
        $stream = $this->stream();
        $session = $this->session();

        $stream->begin($session, 1);
        $stream->emit($session, 1);
        $stream->emit($session, 2);

        self::assertSame(0, $stream->remaining($session));
    }

    /**
     * A value that round-tripped through the pool and came back as something
     * unexpected reads as "no run" rather than fataling.
     */
    public function test_corrupt_state_reads_as_empty(): void
    {
        $pool = new ArrayAdapter();
        $stream = new CountStream($pool);
        $session = $this->session();

        $item = $pool->getItem('count-stream-'.$session->getId()->toRfc4122());
        $item->set('not-an-array');
        $pool->save($item);

        self::assertSame([], $stream->run($session)['emitted']);
    }
}
