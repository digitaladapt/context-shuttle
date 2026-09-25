<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use Override;

/**
 * A `FakeStream` that answers commands as they are written.
 *
 * The library's `FakeStream` must be fed a positional byte sequence up
 * front, which makes a test depend on how many commands the client happens
 * to issue — and tag numbers are assigned sequentially, so one extra command
 * shifts every subsequent tag and the whole script decodes as garbage.
 *
 * Overriding `fwrite()` inverts that: the server replies *because* it was
 * asked something, exactly like a real one. A test declares "when you see
 * `SELECT`, answer this" and the order stops mattering.
 *
 * Unmatched commands get a `BAD` rather than silence, because a silent fake
 * server turns a test bug into an apparent hang.
 */
class RespondingStream extends FakeStream
{
    /**
     * Replies per command needle, each a queue consumed in order.
     *
     * @var array<string, list<array{0: list<string>, 1: string}>>
     */
    private array $handlers = [];

    /**
     * A callable that answers a command, or null to fall through.
     *
     * @var (callable(string): (list<string>|null))|null
     */
    private $fallback;

    /**
     * Register a reply for any command containing `$needle`.
     *
     * A later declaration for the same needle *appends* to a queue rather
     * than replacing it: the client issues several commands containing
     * `UID FETCH` in one call (headers first, then a body part), and each
     * must get its own reply. Replacing would hand the header fetch the body
     * reply and leave the body fetch unanswered.
     *
     * @param list<string> $lines untagged responses, in order
     */
    public function on(string $needle, array $lines, string $status = 'OK'): self
    {
        $key = strtoupper($needle);

        $this->handlers[$key] ??= [];
        $this->handlers[$key][] = [$lines, $status];

        return $this;
    }

    /**
     * Drop every reply registered for a needle.
     */
    public function forget(string $needle): void
    {
        unset($this->handlers[strtoupper($needle)]);
    }

    /**
     * Answer any otherwise-unmatched command by inspecting it.
     *
     * Used for `LIST`, where the reply depends on the folder the client
     * asked for — the difference between a fake and a script.
     *
     * @param callable(string): (list<string>|null) $fallback
     */
    public function onUnmatched(callable $fallback): self
    {
        $this->fallback = $fallback;

        return $this;
    }

    /**
     * The greeting a server sends on every new connection.
     */
    private string $greeting = '* OK [CAPABILITY IMAP4rev1 LITERAL+ IDLE UIDPLUS MOVE] ready';

    /**
     * Set the greeting line.
     */
    public function greet(string $line): self
    {
        $this->greeting = $line;

        return $this;
    }

    /**
     * A new connection gets a new greeting.
     *
     * This is what makes the fixture a *server* rather than a socket. The
     * email client deliberately opens and closes a connection per operation
     * (the design's rule is that a `Mailbox` is never shared), so a read
     * that issues two fetches lands on two connections — and a fixture that
     * greeted only once would appear to hang on the second one.
     *
     * @param array<string, mixed> $options
     */
    #[Override]
    public function open(?string $transport = null, ?string $host = null, ?int $port = null, ?int $timeout = null, array $options = []): bool
    {
        parent::open($transport, $host, $port, $timeout, $options);

        parent::feed($this->greeting);

        return true;
    }

    #[Override]
    public function fwrite(string $data): int|false
    {
        $result = parent::fwrite($data);

        if (false === $result) {
            return $result;
        }

        // The library's `fgets()` returns `false` the moment the buffer
        // empties, and the parser treats that as end-of-stream — so a reply
        // must already be buffered *before* the client asks for it. That is
        // what makes this a responding stream rather than a queued one:
        // writing a command is the signal to produce its answer, and the
        // answer is in place by the time the read happens.
        foreach (preg_split('/\r\n|\n/', trim($data)) ?: [] as $command) {
            $command = trim($command);

            if ('' === $command) {
                continue;
            }

            // A literal arrives as a second write on the same command
            // (`{8}` then the content), and the server has already answered
            // the command line itself.
            if (str_starts_with($command, '{') || ctype_digit($command[0] ?? '')) {
                continue;
            }

            $this->respond($command);
        }

        return $result;
    }

    private function respond(string $command): void
    {
        $tag = strtok($command, ' ');

        if (false === $tag) {
            return;
        }

        $upper = strtoupper($command);

        // Longest needle first, so a specific handler beats a general one
        // regardless of declaration order.
        $needles = array_keys($this->handlers);
        usort($needles, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($needles as $needle) {
            if (!str_contains($upper, $needle)) {
                continue;
            }

            $queue = $this->handlers[$needle];

            // One reply per matching command, with the last repeating, so a
            // single registered reply is not order-bound.
            [$lines, $status] = 1 === \count($queue)
                ? $queue[0]
                : array_shift($this->handlers[$needle]);

            $this->emit($tag, $lines, $status);

            return;
        }

        if (null !== $this->fallback) {
            $lines = ($this->fallback)($command);

            if (null !== $lines) {
                $this->emit($tag, $lines, 'OK');

                return;
            }
        }

        parent::feed($tag.' BAD no handler for: '.$command);
    }

    /**
     * Emit a scripted reply.
     *
     * A line ending in a literal marker (`{n}`) is the start of a
     * multi-line response: the next entry is the literal's *content*, which
     * must be written verbatim so the byte count is exactly what the server
     * promised. Feeding it through `feed()` would re-terminate it and shift
     * every following token, which is how a fixture ends up parsing as
     * something entirely different from what it looks like.
     *
     * @param list<string> $lines
     */
    private function emit(string $tag, array $lines, string $status): void
    {
        $expectLiteral = false;

        foreach ($lines as $line) {
            if ($expectLiteral) {
                parent::feedRaw($line);
                $expectLiteral = false;

                continue;
            }

            parent::feed($line);

            if (preg_match('/\{(\d+)\}$/', $line, $matches)) {
                // A zero-length literal has no content to consume, so the
                // next entry is another response line rather than its body.
                $expectLiteral = (int) $matches[1] > 0;
            }
        }

        parent::feed($tag.' '.$status.' completed');
    }
}
