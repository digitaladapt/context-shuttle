<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Email;

use App\Email\EmailReader;
use App\Email\FolderGate;
use App\Email\FolderNotAllowedException;
use App\Email\Imap\ImapClient;
use App\Email\Imap\ImapConnectionFactory;
use App\Tests\Support\FakeImapServer;
use App\Tests\Support\ScriptedImap;
use App\Tool\Email\ListEmailsTool;
use App\Tool\Email\ReadEmailTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The read tools, over a scripted server.
 *
 * These assert the *contract*: validation, the envelope shape, ordering,
 * paging, and the folder gate. Wire-level behaviour lives in
 * `ImapClientTest`; the split means a failure here is the tool lying to a
 * caller rather than the parser misreading a byte.
 *
 * @internal
 *
 * @covers \App\Tool\Email\ListEmailsTool
 * @covers \App\Tool\Email\ReadEmailTool
 * @covers \App\Email\EmailReader
 */
final class EmailToolsTest extends TestCase
{
    public function test_lists_messages_newest_first(): void
    {
        // The server returns ascending order whatever the fetch window
        // (finding 16), so ordering has to be ours: a listing that reorders
        // between calls describes a different mailbox.
        $server = $this->inboxWith([
            ScriptedImap::envelope(1, 'Oldest', '2026-09-01T10:00:00+00:00'),
            ScriptedImap::envelope(2, 'Newest', '2026-09-20T10:00:00+00:00'),
            ScriptedImap::envelope(3, 'Middle', '2026-09-10T10:00:00+00:00'),
        ], total: 3);

        $result = $this->listTool($server)->listEmails(folder: 'INBOX');

        self::assertSame(['Newest', 'Middle', 'Oldest'], array_column($result['emails'], 'subject'));
    }

    public function test_a_message_with_no_date_sorts_last(): void
    {
        // Ordinary for junk mail, and sorting it first would put spam on top
        // of every listing.
        $server = $this->inboxWith([
            ScriptedImap::envelope(1, 'Undated', null),
            ScriptedImap::envelope(2, 'Dated', '2026-09-20T10:00:00+00:00'),
        ], total: 2);

        $result = $this->listTool($server)->listEmails(folder: 'INBOX');

        self::assertSame(['Dated', 'Undated'], array_column($result['emails'], 'subject'));
    }

    public function test_the_envelope_carries_no_body(): void
    {
        // A listing must not be expensive and must not be able to change
        // mailbox state, so no body of any spelling belongs in it.
        $server = $this->inboxWith([ScriptedImap::envelope(1, 'Hi', '2026-09-20T10:00:00+00:00')]);

        $result = $this->listTool($server)->listEmails(folder: 'INBOX');

        foreach (['text', 'html', 'body', 'content'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $result['emails'][0]);
        }
    }

    public function test_attachment_metadata_is_included_without_a_body_fetch(): void
    {
        // Finding 13: `BODYSTRUCTURE` gives names, types and sizes cheaply.
        // The alternative (`attachmentCount()`) parses the MIME body and
        // reports zero for a message whose body was never fetched.
        $server = $this->inboxWith([
            ScriptedImap::envelope(1, 'Invoice', '2026-09-20T10:00:00+00:00', attachments: [
                ['name' => 'invoice.pdf', 'type' => 'application/octet-stream', 'size' => 389],
            ]),
        ]);

        $result = $this->listTool($server)->listEmails(folder: 'INBOX');

        self::assertTrue($result['emails'][0]['has_attachments']);
        self::assertSame(
            [['name' => 'invoice.pdf', 'content_type' => 'application/octet-stream', 'size' => 389]],
            $result['emails'][0]['attachments'],
        );
        // The attachment's *content* is never fetched, so no partial fetch
        // for a part number should have been issued.
        self::assertStringNotContainsString('BODY.PEEK[2]', $server->commandLog());
    }

    public function test_truncated_is_set_when_more_messages_exist_than_returned(): void
    {
        // "25 of 300" read as "25" is how a model reports an inbox as
        // handled when it has seen none of it.
        $server = $this->inboxWith([
            ScriptedImap::envelope(1, 'One', '2026-09-20T10:00:00+00:00'),
            ScriptedImap::envelope(2, 'Two', '2026-09-19T10:00:00+00:00'),
        ], total: 50);

        $result = $this->listTool($server)->listEmails(folder: 'INBOX', limit: 2);

        self::assertTrue($result['truncated']);
        self::assertSame(50, $result['total']);
    }

    public function test_uidvalidity_is_reported(): void
    {
        // So a caller paging can notice the mailbox was rebuilt underneath it.
        $server = $this->inboxWith(
            [ScriptedImap::envelope(1, 'Hi', '2026-09-20T10:00:00+00:00')],
            uidvalidity: 4242,
        );

        self::assertSame(4242, $this->listTool($server)->listEmails(folder: 'INBOX')['uidvalidity']);
    }

    public function test_a_folder_outside_the_allowlist_is_refused_with_the_env_var(): void
    {
        $server = new FakeImapServer();

        try {
            $this->listTool($server)->listEmails(folder: 'Archive');
            self::fail('expected a refusal');
        } catch (FolderNotAllowedException $e) {
            self::assertStringContainsString('Archive', $e->getMessage());
            self::assertStringContainsString('IMAP_READ_FOLDERS', $e->getMessage());
        }

        // A refusal must not touch the network: nothing to leak, nothing to
        // be slow about.
        self::assertStringNotContainsString('SELECT', $server->commandLog());
    }

    public function test_inbox_is_accepted_case_insensitively(): void
    {
        foreach (['INBOX', 'inbox', 'Inbox'] as $name) {
            $server = $this->inboxWith([]);

            self::assertSame([], $this->listTool($server)->listEmails(folder: $name)['emails']);
        }
    }

    public function test_an_invalid_date_is_rejected(): void
    {
        $server = new FakeImapServer();

        try {
            $this->listTool($server)->listEmails(folder: 'INBOX', sent_since: 'not-a-date');
            self::fail('expected a rejection');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('sent_since', $e->getMessage());
        }

        // 2026-02-30 matches the format but is not a real date; a lenient
        // parse would roll it into March and search the wrong range.
        $this->expectException(InvalidArgumentException::class);
        $this->listTool($server)->listEmails(folder: 'INBOX', sent_before: '2026-02-30');
    }

    public function test_an_out_of_range_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/limit must be between 1 and 100/');

        $this->listTool(new FakeImapServer())->listEmails(folder: 'INBOX', limit: 500);
    }

    public function test_a_negative_offset_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/offset/');

        $this->listTool(new FakeImapServer())->listEmails(folder: 'INBOX', offset: -1);
    }

    public function test_reading_a_message_does_not_mark_it_read(): void
    {
        // The design's central promise, checked end to end: the message
        // comes back `seen: false` and nothing in the session stored a flag.
        $server = $this->serverWithBody('hello');

        $result = $this->readTool($server)->readEmail(folder: 'INBOX', uid: 1);

        self::assertFalse($result['seen']);
        self::assertStringNotContainsString('STORE', $server->commandLog());
    }

    public function test_reading_returns_the_body_and_attachment_metadata(): void
    {
        $server = $this->serverWithBody('hello world');

        $result = $this->readTool($server)->readEmail(folder: 'INBOX', uid: 1);

        self::assertSame('hello world', $result['text']);
        self::assertSame('text/plain', $result['content_type']);
        self::assertFalse($result['truncated']);
        self::assertSame(
            [['name' => 'payload.bin', 'content_type' => 'application/octet-stream', 'size' => 389]],
            $result['attachments'],
        );
    }

    public function test_reading_an_unknown_uid_names_the_folder_and_the_uid(): void
    {
        $server = new FakeImapServer();
        $server->selectable(0);
        $server->on('BODYSTRUCTURE', []);

        try {
            $this->readTool($server)->readEmail(folder: 'INBOX', uid: 99999);
            self::fail('expected an error');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('99999', $e->getMessage());
            self::assertStringContainsString('INBOX', $e->getMessage());
            // The hint matters: uids are per-folder, so the obvious next move
            // is to look the message up again rather than guess.
            self::assertStringContainsString('list_emails', $e->getMessage());
        }
    }

    public function test_an_unconfigured_deployment_names_the_env_vars(): void
    {
        $factory = new ImapConnectionFactory('', 0, '', '', '');
        $reader = new EmailReader(new ImapClient($factory), new FolderGate(readFolders: 'INBOX'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/IMAP_HOST/');

        (new ListEmailsTool($reader, $factory))->listEmails(folder: 'INBOX');
    }

    public function test_a_body_larger_than_the_cap_is_fetched_partially_and_flagged(): void
    {
        // Finding 14, and the two halves of the promise:
        //
        // 1. The bound is a partial fetch (`<0.N>`), not a post-hoc
        //    truncation, so the bytes never cross the network. Asserted on
        //    the *command*, which is the only place the difference shows.
        // 2. `truncated` is judged against the size `BODYSTRUCTURE` reports,
        //    not against how many bytes happened to arrive — a call that
        //    received only the partial reply must still say so.
        $server = $this->serverWithBody(
            str_repeat('x', 400),
            bodySize: 2_000_000,
            capped: true,
        );

        $result = $this->readTool($server)->readEmail(folder: 'INBOX', uid: 1);

        self::assertStringContainsString(
            '<0.'.ReadEmailTool::MAX_BODY_BYTES.'>',
            $server->commandLog(),
            'the body fetch must be bounded by a byte range',
        );
        self::assertTrue($result['truncated']);
        self::assertSame(2_000_000, $result['size']);
    }

    public function test_a_body_that_fits_is_not_flagged_as_truncated(): void
    {
        // The other side of the same rule: a small message must not be
        // reported as partial just because it was fetched with a byte range.
        $server = $this->serverWithBody('short body', bodySize: 10);

        $result = $this->readTool($server)->readEmail(folder: 'INBOX', uid: 1);

        self::assertFalse($result['truncated']);
    }

    public function test_tags_come_back_as_a_list_with_system_flags_excluded(): void
    {
        $server = new FakeImapServer();
        $server->folder('INBOX');
        $server->selectable(1);
        $server->status('INBOX', 1, 0);
        $server->on('UID SEARCH', ['* SEARCH 1']);
        $server->on('BODYSTRUCTURE', [
            '* 1 FETCH (UID 1 FLAGS (\Seen AiRead) RFC822.SIZE 300 BODY[HEADER] {0}',
            '',
            ' BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 300 1 NIL NIL NIL NIL))',
        ]);

        $result = $this->listTool($server)->listEmails(folder: 'INBOX');

        self::assertSame(['AiRead'], $result['emails'][0]['tags']);
        self::assertTrue($result['emails'][0]['seen']);
    }

    /**
     * A server holding one message with a plain-text body and one attachment.
     *
     * @param int|null $bodySize the `BODYSTRUCTURE` size, when it should
     *                           differ from the body actually sent
     * @param bool     $capped   reply with a *partial* fetch, the shape
     *                           Dovecot sends for a byte range
     */
    private function serverWithBody(string $body, ?int $bodySize = null, bool $capped = false): FakeImapServer
    {
        $server = $this->inboxWith([], total: 1);

        $envelope = ScriptedImap::envelope(
            uid: 1,
            subject: 'Hi',
            date: '2026-09-20T10:00:00+00:00',
            size: $bodySize ?? \strlen($body),
            attachments: [['name' => 'payload.bin', 'type' => 'application/octet-stream', 'size' => 389]],
        );

        // Replace the default envelope reply with this message's.
        $server->reset('BODYSTRUCTURE');
        $server->on('BODYSTRUCTURE', $envelope);

        // A partial fetch is echoed as `<0>`, and the server sends only the
        // bytes that were asked for.
        $server->on('BODY.PEEK[1]', $capped
            ? [\sprintf('* 1 FETCH (UID 1 BODY [1] <0> {%d}', \strlen($body)), $body, ')']
            : [\sprintf('* 1 FETCH (UID 1 BODY [1] {%d}', \strlen($body)), $body, ')']);

        return $server;
    }

    /**
     * A server holding `$messages`, whose `SEARCH` reports their uids.
     *
     * @param list<list<string>> $messages envelope lines, as
     *                                     {@see ScriptedImap::envelope()} builds them
     */
    private function inboxWith(array $messages, int $total = 0, int $uidvalidity = 1): FakeImapServer
    {
        $server = new FakeImapServer();
        $server->folder('INBOX');
        $server->selectable($total);
        $server->status('INBOX', $total, $total, $uidvalidity);

        $uids = [];
        foreach ($messages as $lines) {
            // The uid is the 3rd field of the first line: `* <n> FETCH (UID <uid> ...`.
            preg_match('/UID (\d+)/', $lines[0], $matches);
            $uids[] = (int) $matches[1];
        }

        $server->on('UID SEARCH', ['* SEARCH'.($uids ? ' '.implode(' ', $uids) : '')]);
        $server->on('BODYSTRUCTURE', array_merge(...$messages ?: [[]]));

        return $server;
    }

    private function listTool(FakeImapServer $server, string $readFolders = 'INBOX'): ListEmailsTool
    {
        return new ListEmailsTool($this->reader($server, $readFolders), $this->factory($server));
    }

    private function readTool(FakeImapServer $server, string $readFolders = 'INBOX'): ReadEmailTool
    {
        return new ReadEmailTool($this->reader($server, $readFolders), $this->factory($server));
    }

    private function reader(FakeImapServer $server, string $readFolders): EmailReader
    {
        return new EmailReader(
            new ImapClient($this->factory($server)),
            new FolderGate(readFolders: $readFolders),
        );
    }

    private function factory(FakeImapServer $server): ImapConnectionFactory
    {
        return new ImapConnectionFactory('fake', 143, 'u', 'p', 'tcp', 15.0, $server->connection());
    }
}
