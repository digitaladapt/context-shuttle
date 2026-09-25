<?php

declare(strict_types=1);

namespace App\Tests\Unit\Email;

use App\Email\Imap\ImapClient;
use App\Email\Imap\ImapConnectionFactory;
use App\Email\Imap\MessageFilter;
use App\Tests\Support\FakeImapServer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The IMAP client, driven against a scripted server.
 *
 * These tests exercise the *real* client and the *real* parser, because
 * every defect the email code works around is a wire-level behaviour:
 *
 * - the search encoding (finding 3) is only visible in the issued command;
 * - the partial-fetch token shape is only visible in the parsed response;
 * - the header truncation (finding 10) is only visible in a parsed header.
 *
 * A mocked client would assert that the right methods were called and pass
 * while all three misbehaved. See {@see FakeImapServer} for the details.
 *
 * @internal
 *
 * @covers \App\Email\Imap\ImapClient
 * @covers \App\Email\Imap\ImapConnectionFactory
 */
final class ImapClientTest extends TestCase
{
    public function test_lists_folders_with_the_imap_utf7_path_decoded(): void
    {
        // Finding 19: the server reports modified UTF-7, so a folder named
        // `Tëst-Ünicode` arrives as `T&AOs-st-&ANw-nicode`. Decoding at this
        // boundary is what makes allowlists and tool arguments speak the
        // name the operator typed.
        $server = new FakeImapServer();
        $server->folder('INBOX');
        $server->folder('T&AOs-st-&ANw-nicode');
        $server->folder('Projects/Widget', ['\HasChildren']);
        $server->status('INBOX', 4, 2);

        $folders = $this->client($server)->listFolders();

        $paths = array_column($folders, 'path');
        self::assertContains('INBOX', $paths);
        self::assertContains('Tëst-Ünicode', $paths, 'the mUTF-7 path must be decoded');
        self::assertNotContains('T&AOs-st-&ANw-nicode', $paths);

        $byPath = array_column($folders, null, 'path');
        // `name` is the last path segment, so a caller does not have to
        // split the path itself.
        self::assertSame('Widget', $byPath['Projects/Widget']['name']);
        self::assertSame('Tëst-Ünicode', $byPath['Tëst-Ünicode']['name']);
    }

    public function test_folder_status_reports_uidvalidity(): void
    {
        $server = new FakeImapServer();
        $server->folder('INBOX');
        $server->status('INBOX', 4, 2, 42);

        $status = $this->client($server)->folderStatus('INBOX');

        self::assertSame(4, $status['messages']);
        self::assertSame(2, $status['unread']);
        // So a caller holding a uid from an earlier call can tell whether it
        // still means anything.
        self::assertSame(42, $status['uidvalidity']);
    }

    public function test_a_non_ascii_search_is_sent_as_utf8_and_not_modified_utf7(): void
    {
        // The regression that matters most: the library converts plain
        // values to modified UTF-7, which the server matches *literally*,
        // returning nothing with no error. Asserting on the command is the
        // only way to catch it — both the correct and the broken form
        // produce a well-formed response.
        $server = new FakeImapServer();
        $server->selectable()->status('INBOX', 0, 0);
        $server->on('UID SEARCH', ['* SEARCH']);

        $this->client($server)->listMessages('INBOX', new MessageFilter(subject: 'Reunión'), 25, 0);

        $log = $server->commandLog();

        self::assertStringContainsString('"Reunión"', $log);
        self::assertStringNotContainsString('&APM-', $log, 'mUTF-7 is a folder encoding, not a search-term one');
    }

    public function test_search_terms_are_not_wrapped_in_percent_wildcards(): void
    {
        // Inside a quoted string `%` is a literal, so `"%invoice%"` searches
        // for a subject containing percent signs and matches nothing.
        // Dovecot substring-matches natively.
        $server = new FakeImapServer();
        $server->selectable()->status('INBOX', 0, 0);
        $server->on('UID SEARCH', ['* SEARCH']);

        $this->client($server)->listMessages('INBOX', new MessageFilter(subject: 'invoice'), 25, 0);

        $log = $server->commandLog();
        self::assertStringContainsString('"invoice"', $log);
        self::assertStringNotContainsString('"%"', $log);
        self::assertStringNotContainsString('%%', $log);
    }

    public function test_reading_a_message_uses_body_peek_so_it_is_not_marked_read(): void
    {
        // Findings 1 and 2: the library's `fetchAsUnread` default puts
        // BODY.PEEK on the wire, and the flip to a read-marking fetch is one
        // method call away. A `BODY[` without `PEEK` sets \Seen as a side
        // effect, so this asserts the read path never issues one.
        $server = $this->serverWithOnePlainMessage('hello world');

        $this->client($server)->readMessage('INBOX', 1, false, 100000);

        $log = $server->commandLog();

        self::assertStringContainsString('BODY.PEEK[', $log);
        self::assertDoesNotMatchRegularExpression(
            '/BODY\[(?!.*PEEK)/',
            $log,
            'a non-PEEK body fetch would mark the message read as a side effect',
        );
    }

    public function test_the_body_is_bounded_on_the_wire_with_a_partial_fetch(): void
    {
        // Finding 14: a 3.4 MB message returned 2.3 MB of text. The bound
        // has to be a partial fetch, not a post-hoc truncation, or the bytes
        // cross the network only to be discarded.
        $server = $this->serverWithOnePlainMessage(str_repeat('x', 500), bodySize: 2000000, capped: 500);

        $result = $this->client($server)->readMessage('INBOX', 1, false, 500);

        self::assertStringContainsString('<0.500>', $server->commandLog(), 'the fetch must be partial');
        self::assertNotNull($result);
        self::assertTrue($result['truncated']);
        self::assertSame(500, \strlen((string) $result['text']));
    }

    public function test_the_body_survives_the_partial_fetch_token_shape(): void
    {
        // The trap this test pins: a partial fetch makes Dovecot echo the
        // range as a bare `<0>`, which the library's tokenizer splits into
        // its own tokens. Reading the body via `lookup('[n]')` then returns
        // the range marker instead of the body — this client reads the last
        // literal in the data list instead.
        $body = 'The quick brown fox jumps over the lazy dog.';
        $server = $this->serverWithOnePlainMessage($body, capped: 42);

        $result = $this->client($server)->readMessage('INBOX', 1, false, 100000);

        self::assertNotNull($result);
        self::assertSame($body, $result['text']);
    }

    public function test_an_html_only_message_falls_back_to_the_html_part(): void
    {
        // Finding 12: newsletters — and a large share of real mail — carry
        // no text/plain part at all, and `text()` returns NULL for them. A
        // tool that returned NULL would "read" nothing and look correct.
        $html = '<html><body><h1>Digest</h1></body></html>';

        $server = new FakeImapServer();
        $server->selectable();
        $server->on('BODYSTRUCTURE', [
            \sprintf('* 1 FETCH (UID 1 FLAGS () RFC822.SIZE 900 BODYSTRUCTURE ("text" "html" ("charset" "utf-8") NIL NIL "7bit" %d 1 NIL NIL NIL NIL)', \strlen($html)),
            ')',
        ]);
        $server->on('BODY.PEEK[1]', [
            \sprintf('* 1 FETCH (UID 1 BODY [1] {%d}', \strlen($html)),
            $html,
            ')',
        ]);

        $result = $this->client($server)->readMessage('INBOX', 1, false, 100000);

        self::assertNotNull($result);
        self::assertSame($html, $result['text']);
        // The caller has to be able to tell markup from prose.
        self::assertSame('text/html', $result['content_type']);
    }

    public function test_an_unreadable_uid_returns_null_rather_than_throwing(): void
    {
        // "No such message" is an answer, and the tool layer phrases it.
        $server = new FakeImapServer();
        $server->selectable();
        $server->on('BODYSTRUCTURE', []);

        self::assertNull($this->client($server)->readMessage('INBOX', 99, false, 100000));
    }

    public function test_keywords_are_reported_as_tags_and_system_flags_are_not(): void
    {
        // Finding 4: custom keywords are where the agent's own state lives.
        // `\Seen` is the human's flag and is reported separately, so "tags"
        // means exactly "things the agent (or a rule) invented".
        $server = new FakeImapServer();
        $server->selectable(1);
        $server->status('INBOX', 1, 0);
        $server->on('UID SEARCH', ['* SEARCH 1']);
        $server->on('BODYSTRUCTURE', [
            '* 1 FETCH (UID 1 FLAGS (\Seen \Flagged AiRead NeedsReply) RFC822.SIZE 10 BODY[HEADER] {0}',
            '',
            ' BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 10 1 NIL NIL NIL NIL))',
        ]);

        $rows = $this->client($server)->listMessages('INBOX', new MessageFilter(), 25, 0);

        self::assertCount(1, $rows);
        self::assertSame(['AiRead', 'NeedsReply'], $rows[0]['tags']);
        self::assertTrue($rows[0]['seen']);
        self::assertTrue($rows[0]['flagged']);
    }

    public function test_structured_headers_are_read_raw_so_verdicts_survive(): void
    {
        // Finding 10: `getValue()` returned only `mx.devgnome.com` for
        // `mx.devgnome.com; spf=fail ...` — the entire SPF/DKIM/DMARC
        // verdict, silently dropped, from the one header a model would check
        // before trusting a message. Asserted through the *parsed* client
        // result rather than on a raw string, so the fix is pinned where it
        // matters.
        $spoofHeaders = implode("\r\n", [
            'From: "Urgent Security" <noreply@evil.example>',
            'Subject: URGENT',
            'Authentication-Results: mx.devgnome.com; spf=fail smtp.mailfrom=evil.example; dkim=none; dmarc=fail',
            'Return-Path: <bounce@evil.example>',
            'Reply-To: attacker@evil.example',
        ]);

        $server = new FakeImapServer();
        $server->selectable();
        $server->on('BODYSTRUCTURE', [
            \sprintf('* 1 FETCH (UID 1 FLAGS () RFC822.SIZE 400 BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 4 1 NIL NIL NIL NIL) BODY[HEADER] {%d}', \strlen($spoofHeaders)),
            $spoofHeaders,
            ')',
        ]);
        $server->on('BODY.PEEK[1]', [
            '* 1 FETCH (UID 1 BODY [1] {4}',
            'body',
            ')',
        ]);

        $result = $this->client($server)->readMessage('INBOX', 1, true, 100000);

        self::assertNotNull($result);
        $headers = $result['headers'];

        self::assertArrayHasKey('Authentication-Results', $headers);
        self::assertStringContainsString('spf=fail', $headers['Authentication-Results']);
        self::assertStringContainsString('dkim=none', $headers['Authentication-Results']);
        self::assertStringContainsString('dmarc=fail', $headers['Authentication-Results']);
        self::assertSame('<bounce@evil.example>', $headers['Return-Path']);
        self::assertSame('attacker@evil.example', $headers['Reply-To']);
    }

    public function test_headers_are_not_fetched_unless_asked_for(): void
    {
        $server = $this->serverWithOnePlainMessage('body');

        $result = $this->client($server)->readMessage('INBOX', 1, false, 100000);

        self::assertNotNull($result);
        self::assertSame([], $result['headers']);
    }

    public function test_an_unconfigured_deployment_fails_with_a_sentence_naming_the_env_vars(): void
    {
        $factory = new ImapConnectionFactory('', 0, '', '', '');

        self::assertFalse($factory->isConfigured());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/IMAP_HOST/');

        $factory->mailbox();
    }

    public function test_an_injected_connection_counts_as_configured(): void
    {
        // The test seam must not require fake credentials to be set.
        $factory = $this->factory(new FakeImapServer());

        self::assertTrue($factory->isConfigured());
    }

    public function test_the_default_port_follows_the_encryption_mode(): void
    {
        // An unset IMAP_PORT arrives as 0; two settings that must agree is a
        // bug waiting to happen, so the port is derived from the encryption
        // mode instead. Asserted through the mailbox the factory builds,
        // because that is the value the library actually receives.
        self::assertSame(993, $this->effectivePort('ssl', 0));
        self::assertSame(143, $this->effectivePort('starttls', 0));
        self::assertSame(143, $this->effectivePort('tcp', 0));
        // An explicit port always wins.
        self::assertSame(1430, $this->effectivePort('ssl', 1430));
    }

    public function test_an_empty_encryption_setting_defaults_to_ssl(): void
    {
        // Silence should mean TLS on a real deployment, not a cleartext
        // connection because the env var happened to be blank.
        self::assertSame('ssl', $this->effectiveEncryption(''));
        self::assertSame('ssl', $this->effectiveEncryption('nonsense'));
        self::assertSame('tcp', $this->effectiveEncryption('tcp'));
    }

    public function test_the_mailbox_is_closed_after_a_failed_callback(): void
    {
        // A tool that throws must still log out rather than leaving a socket
        // for the worker to leak.
        $server = new FakeImapServer();
        $factory = $this->factory($server);

        // The callback throws, and the assertion is that the exception
        // escapes `with()` rather than being swallowed by the `finally`.
        // Captured rather than caught-and-ignored so there is no unreachable
        // statement for the analyser to object to.
        $thrown = null;

        try {
            $factory->with(static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertSame('boom', $thrown->getMessage());

        self::assertStringContainsString('LOGOUT', $server->commandLog());
    }

    public function test_a_missing_folder_is_a_clear_error_naming_it(): void
    {
        $server = new FakeImapServer();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/NoSuchFolder/');

        $this->client($server)->folderStatus('NoSuchFolder');
    }

    /**
     * A server that knows one message: a `text/plain` body whose
     * `BODYSTRUCTURE` reports `$bodySize`, with a reply body of `$body`.
     *
     * @param int|null $capped when set, the body reply is a *partial* fetch
     *                         (`BODY[1] <0> {n}`), the shape Dovecot sends
     *                         when the client asks for a byte range
     */
    private function serverWithOnePlainMessage(string $body, ?int $bodySize = null, ?int $capped = null): FakeImapServer
    {
        $server = new FakeImapServer();
        $server->selectable();

        $encoded = $body;

        $headers = "From: A <a@b.test>\r\nSubject: Hi\r\n\r\n";
        $size = $bodySize ?? 4096;

        // One read issues three distinct fetches — `(UID)` to resolve the
        // id, the envelope, then the body part — so each is answered
        // separately. A single queue keyed on the shared `UID FETCH` prefix
        // hands the first reply to whichever command arrives first, which
        // silently produced a *wrong body* rather than a visible failure.
        //
        // Dovecot's exact ordering within the envelope reply: the header
        // literal, then `BODYSTRUCTURE` as the final data item. Getting that
        // wrong made an earlier revision of this fixture parse as a
        // multipart with no parts — itself an argument for driving the real
        // parser rather than mocking the client.
        $server->on('BODYSTRUCTURE', [
            \sprintf('* 1 FETCH (UID 1 FLAGS () RFC822.SIZE %d BODY[HEADER] {%d}', $size, \strlen($headers)),
            $headers,
            \sprintf(' BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "7bit" %d 1 NIL NIL NIL NIL))', $size),
        ]);

        return $server->on('BODY.PEEK[1]', null !== $capped
            ? [
                \sprintf('* 1 FETCH (UID 1 BODY [1] <0> {%d}', \strlen($encoded)),
                $encoded,
                ')',
            ]
            : [
                \sprintf('* 1 FETCH (UID 1 BODY [1] {%d}', \strlen($encoded)),
                $encoded,
                ')',
            ]);
    }

    private function client(FakeImapServer $server): ImapClient
    {
        return new ImapClient($this->factory($server));
    }

    private function factory(
        FakeImapServer $server,
        int $port = 143,
        string $encryption = 'tcp',
    ): ImapConnectionFactory {
        return new ImapConnectionFactory('fake', $port, 'u', 'p', $encryption, 15.0, $server->connection());
    }

    /**
     * The port the factory resolves for a given encryption mode.
     *
     * A fake connection is injected so the assertion is about the resolved
     * configuration rather than about reaching a real server.
     */
    private function effectivePort(string $encryption, int $configuredPort): int
    {
        $factory = new ImapConnectionFactory(
            'host.example',
            $configuredPort,
            'u',
            'p',
            $encryption,
            15.0,
            (new FakeImapServer())->connection(),
        );

        return (int) $factory->mailbox()->config('port');
    }

    private function effectiveEncryption(string $encryption): string
    {
        $factory = new ImapConnectionFactory(
            'host.example',
            993,
            'u',
            'p',
            $encryption,
            15.0,
            (new FakeImapServer())->connection(),
        );

        return (string) $factory->mailbox()->config('encryption');
    }
}
