<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use ReflectionObject;

/**
 * A scripted IMAP server for tests: declare replies by command, not by
 * position.
 *
 * Wraps {@see RespondingStream} so a test says *"when you see `SELECT`,
 * answer this"* and stops caring how many commands the client issues or what
 * tag they land on. `LIST` is answered by inspecting the requested folder,
 * because the library resolves a folder by issuing `LIST` for that exact
 * name — a fixed reply would make every folder resolve to whichever one was
 * scripted first.
 *
 * **Why this exists instead of a mocked client.** Every defect the email
 * code works around is a *parsing* behaviour, invisible above the client:
 *
 * - finding 3 — plain query values converted to modified UTF-7, which the
 *   server matches literally, returning nothing and no error;
 * - the partial-fetch response, where Dovecot echoes `<0>` in a position the
 *   library's tokenizer splits into separate tokens, so the body is no
 *   longer adjacent to its `[n]` key and `lookup('[n]')` yields the range
 *   marker instead of the body;
 * - finding 10 — `getValue()` dropping everything after the first token of a
 *   structured header, which for `Authentication-Results` is the entire
 *   SPF/DKIM/DMARC verdict.
 *
 * A mock of our own client would assert that the right methods were called
 * and would pass while all three misbehaved. These tests drive the real
 * client over a scripted wire, so a regression surfaces as a wrong answer.
 */
final class FakeImapServer
{
    /** @var array<string, list<string>> folder path => LIST flags */
    private array $folders = [];

    private readonly RespondingStream $stream;

    private readonly ImapConnection $connection;

    public function __construct()
    {
        $this->stream = new RespondingStream();

        // `open()` feeds the greeting, so it is not called here: the
        // connection's own `connect()` opens the stream, exactly as it would
        // against a real server.
        $this->connection = new ImapConnection($this->stream);

        // The session scaffolding every test needs.
        $this->on('LOGIN', ['OK']);
        $this->on('LOGOUT', ['* BYE signing off']);

        // `find($uid)` resolves the UID first with a `(UID)`-only fetch, so
        // it is answered generically here: the reply is the same shape
        // whatever the id, and making every test restate it would bury the
        // part each test is actually about.
        $this->on('(UID)', ['* 1 FETCH (UID 1)']);

        // `starttls` negotiates before login. Answering it keeps the
        // encryption-mode tests offline.
        $this->on('STARTTLS', ['OK']);

        // `LIST` is answered by name. The client resolves a folder with
        // `LIST "" "<path>"`, so replying with a fixed folder would make
        // every lookup return the same one — and `find('NoSuchFolder')`
        // would succeed, which is the opposite of what the error path needs
        // to be tested against.
        // The closure reads `$this->folders` lazily, at command time, and by
        // then the fixture is fully constructed. Capturing the array by
        // reference at registration time would freeze it before any test
        // declared a folder.
        $this->stream->onUnmatched(fn (string $command): ?array => str_contains(strtoupper($command), 'LIST')
            ? $this->listReply($command)
            : null);
    }

    /**
     * Register a reply for any command containing `$needle`.
     *
     * @param list<string> $lines untagged responses, in order
     */
    public function on(string $needle, array $lines, string $status = 'OK'): self
    {
        $this->stream->on($needle, $lines, $status);

        return $this;
    }

    /**
     * Drop any replies registered for `$needle`.
     *
     * A test that declares its own message reuses the shared `BODYSTRUCTURE`
     * key, and without this the default scaffolding would shadow it.
     */
    public function reset(string $needle): self
    {
        $this->stream->forget($needle);

        return $this;
    }

    /**
     * Declare a folder so it can be resolved by `LIST`.
     *
     * @param list<string> $extraFlags e.g. `\HasChildren`
     */
    public function folder(string $path, array $extraFlags = []): self
    {
        $this->folders[$path] = ['\HasNoChildren', ...$extraFlags];

        return $this;
    }

    /**
     * A `STATUS` reply for one folder.
     */
    public function status(string $path, int $messages, int $unread, int $uidvalidity = 1): self
    {
        return $this->on('STATUS', [\sprintf(
            '* STATUS "%s" (MESSAGES %d RECENT 0 UIDNEXT %d UIDVALIDITY %d UNSEEN %d)',
            $path,
            $messages,
            $messages + 1,
            $uidvalidity,
            $unread,
        )]);
    }

    /**
     * The `SELECT` prelude a read-write mailbox sends.
     */
    public function selectable(int $exists = 0, int $uidvalidity = 1): self
    {
        if ([] === $this->folders) {
            $this->folder('INBOX');
        }

        return $this->on('SELECT', [
            '* FLAGS (\Answered \Flagged \Deleted \Seen \Draft)',
            \sprintf('* %d EXISTS', $exists),
            '* 0 RECENT',
            \sprintf('* OK [UIDVALIDITY %d] UIDs valid', $uidvalidity),
            '* OK [READ-WRITE] Select completed',
        ]);
    }

    public function connection(): ImapConnection
    {
        return $this->connection;
    }

    /**
     * Every command the client sent.
     *
     * This is the assertion that catches finding 3 — a result count cannot
     * distinguish "the server matched nothing" from "we asked the wrong
     * question", and the bug produced exactly that ambiguity.
     *
     * The library keeps `$written` protected with no accessor, and its own
     * `assertWritten()` *consumes* entries, which makes it useless for
     * asserting on several commands.
     *
     * @return list<string>
     */
    public function written(): array
    {
        $reflection = new ReflectionObject($this->stream);

        /** @var list<string> $written */
        $written = array_values($reflection->getProperty('written')->getValue($this->stream));

        return $written;
    }

    public function commandLog(): string
    {
        return implode("\n", $this->written());
    }

    /**
     * Build a `LIST` reply from the folder the client asked for.
     *
     * @return list<string>
     */
    private function listReply(string $command): array
    {
        // `LIST "" "INBOX"` — the reference is empty and the pattern is the
        // last quoted token.
        preg_match_all('/"([^"]*)"/', $command, $matches);
        $pattern = $matches[1][1] ?? '';

        $replies = [];

        foreach ($this->folders as $path => $flags) {
            // `*` matches everything; otherwise the pattern is the exact
            // folder name the library is looking for. `INBOX` is matched
            // case-insensitively because IMAP requires it.
            $matchesPattern = '*' === $pattern
                || 0 === strcasecmp($pattern, $path)
                || ('inbox' === strtolower($path) && 'inbox' === strtolower($pattern));

            if (!$matchesPattern) {
                continue;
            }

            $replies[] = \sprintf('* LIST (%s) "/" "%s"', implode(' ', $flags), $path);
        }

        return $replies;
    }
}
