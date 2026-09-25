<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Email;

use App\Email\EmailReader;
use App\Email\FolderGate;
use App\Email\FolderNotAllowedException;
use App\Email\Imap\ImapClient;
use App\Email\Imap\ImapConnectionFactory;
use App\Email\Imap\MessageNotFound;
use App\Email\MoveDestination;
use App\Email\MoveNotObserved;
use App\Tests\Support\FakeImapServer;
use App\Tool\Email\MarkEmailTool;
use App\Tool\Email\MoveEmailTool;
use App\Tool\Email\TagEmailTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The write tools, over a scripted server.
 *
 * The theme is *refusal before effect*. Almost every test here asserts that
 * something did **not** happen: no command was sent, no flag was stored, no
 * message moved. That is the design's shape — a gate that lets a request
 * through and then narrows it is the failure mode this whole family exists
 * to avoid.
 *
 * @internal
 *
 * @covers \App\Tool\Email\TagEmailTool
 * @covers \App\Tool\Email\MarkEmailTool
 * @covers \App\Tool\Email\MoveEmailTool
 * @covers \App\Email\MoveDestination
 * @covers \App\Email\MoveNotObserved
 */
final class EmailWriteToolsTest extends TestCase
{
    // ── Tagging ─────────────────────────────────────────────────────────

    public function test_a_tag_is_added_and_the_result_reports_the_tags_afterwards(): void
    {
        $server = $this->taggableInbox();

        $result = $this->tagTool($server, tagFolders: 'INBOX')->tagEmail(folder: 'INBOX', uid: 1, tag: 'AiRead');

        self::assertSame('AiRead', $result['tag']);
        self::assertFalse($result['removed']);
        self::assertSame(['AiRead'], $result['tags']);
    }

    public function test_tagging_sends_a_keyword_store_and_never_a_system_flag(): void
    {
        // The command log is where the promise is visible: `+FLAGS` with a
        // bare keyword, no backslash anywhere.
        $server = $this->taggableInbox();

        $this->tagTool($server, tagFolders: 'INBOX')->tagEmail(folder: 'INBOX', uid: 1, tag: 'AiRead');

        $log = $server->commandLog();
        self::assertStringContainsString('STORE', $log);
        self::assertStringContainsString('AiRead', $log);
        self::assertStringNotContainsString('\\', $log, 'no system flag may appear in the command');
    }

    public function test_a_system_flag_is_refused_before_the_gate_or_the_wire(): void
    {
        // Finding 23: `flag('\Deleted', '+')` is accepted by the library and
        // sets a system flag. Without this guard, `tag_email` would be a
        // second, ungated path to `\Deleted`. The refusal must happen with
        // no command sent at all.
        $server = $this->taggableInbox();

        try {
            $this->tagTool($server, tagFolders: 'INBOX')->tagEmail(folder: 'INBOX', uid: 1, tag: '\\Deleted');
            self::fail('a system flag must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('system flag', $e->getMessage());
        }

        self::assertStringNotContainsString('STORE', $server->commandLog());
        self::assertStringNotContainsString('LOGIN', $server->commandLog());
    }

    public function test_removing_a_tag_sends_the_minus_operation(): void
    {
        $server = $this->taggableInbox();

        $result = $this->tagTool($server, tagFolders: 'INBOX')->tagEmail(folder: 'INBOX', uid: 1, tag: 'AiRead', remove: true);

        self::assertTrue($result['removed']);
        self::assertStringContainsString('-FLAGS', $server->commandLog());
    }

    public function test_a_folder_outside_the_tag_allowlist_is_refused_with_no_command(): void
    {
        $server = $this->taggableInbox();

        try {
            $this->tagTool($server, tagFolders: 'INBOX')->tagEmail(folder: 'Archive', uid: 1, tag: 'AiRead');
            self::fail('Archive is not in IMAP_TAG_FOLDERS');
        } catch (FolderNotAllowedException $e) {
            self::assertStringContainsString('IMAP_TAG_FOLDERS', $e->getMessage());
        }

        self::assertStringNotContainsString('STORE', $server->commandLog());
    }

    public function test_reading_a_folder_does_not_grant_tagging_it(): void
    {
        // The design's central split, asserted directly: INBOX is readable
        // and *not* taggable, so the tag tool refuses it.
        $server = $this->inboxWithFlags('INBOX');

        $tool = $this->tagTool($server, readFolders: 'INBOX');

        $this->expectException(FolderNotAllowedException::class);
        $tool->tagEmail(folder: 'INBOX', uid: 1, tag: 'AiRead');
    }

    public function test_an_unknown_uid_is_a_clear_error(): void
    {
        $server = $this->taggableInbox(uid: 7);

        $this->expectException(MessageNotFound::class);
        $this->expectExceptionMessageMatches('/uid 99999/');

        $this->tagTool($server, tagFolders: 'INBOX')->tagEmail(folder: 'INBOX', uid: 99999, tag: 'AiRead');
    }

    public function test_a_non_positive_uid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/uid/');

        $this->tagTool(new FakeImapServer())->tagEmail(folder: 'INBOX', uid: 0, tag: 'AiRead');
    }

    public function test_an_unconfigured_deployment_names_the_env_vars(): void
    {
        $factory = new ImapConnectionFactory('', 0, '', '', '');
        $reader = new EmailReader(new ImapClient($factory), new FolderGate(tagFolders: 'INBOX'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/IMAP_HOST/');

        (new TagEmailTool($reader, $factory))->tagEmail(folder: 'INBOX', uid: 1, tag: 'AiRead');
    }

    // ── Marking ─────────────────────────────────────────────────────────

    public function test_marking_read_reports_the_state_the_server_confirms(): void
    {
        // The design asks for verified state, not intent. The scripted
        // re-read returns `\Seen`, so that is what the result says.
        $server = $this->markableInbox(seenAfter: true);

        $result = $this->markTool($server, markFolders: 'INBOX')->markEmailRead(folder: 'INBOX', uid: 1);

        self::assertTrue($result['seen']);
    }

    public function test_marking_read_actually_clears_the_observed_state_when_the_server_says_so(): void
    {
        // The other direction: the reply has no `\Seen`, so the tool reports
        // `false` even though `mark_email_read` was called. A tool that
        // echoed its intent would report `true` here and be wrong.
        $server = $this->markableInbox(seenAfter: false);

        $result = $this->markTool($server, markFolders: 'INBOX')->markEmailRead(folder: 'INBOX', uid: 1);

        self::assertFalse($result['seen']);
    }

    public function test_marking_unread_sends_the_minus_operation(): void
    {
        $server = $this->markableInbox(seenAfter: false);

        $this->markTool($server, markFolders: 'INBOX')->markEmailUnread(folder: 'INBOX', uid: 1);

        $log = $server->commandLog();
        self::assertStringContainsString('-FLAGS', $log);
        self::assertStringContainsString('\\Seen', $log);
    }

    public function test_marking_a_folder_outside_the_allowlist_is_refused_with_no_command(): void
    {
        $server = $this->markableInbox();

        try {
            $this->markTool($server, markFolders: 'INBOX')->markEmailRead(folder: 'Archive', uid: 1);
            self::fail('Archive is not in IMAP_MARK_FOLDERS');
        } catch (FolderNotAllowedException $e) {
            self::assertStringContainsString('IMAP_MARK_FOLDERS', $e->getMessage());
        }

        self::assertStringNotContainsString('STORE', $server->commandLog());
    }

    public function test_tagging_a_folder_does_not_grant_marking_it(): void
    {
        // The split that matters most in practice: an operator may let the
        // agent label its own state while leaving the shared unread count
        // alone. INBOX is taggable and not markable here.
        $server = $this->inboxWithFlags('INBOX');

        $tool = $this->markTool($server, tagFolders: 'INBOX');

        $this->expectException(FolderNotAllowedException::class);
        $tool->markEmailRead(folder: 'INBOX', uid: 1);
    }

    // ── Moving ──────────────────────────────────────────────────────────

    public function test_a_move_reports_the_folder_the_message_was_observed_in(): void
    {
        // `to_folder` is where the message *is*, not the configured value
        // echoed back — the difference between "we issued a move" and "it
        // moved".
        $server = $this->movableInbox();

        $result = $this->moveTool($server, moveFromFolders: 'INBOX')->moveEmail(folder: 'INBOX', uid: 1, destination: 'archive');

        self::assertTrue($result['moved']);
        self::assertSame('INBOX', $result['from_folder']);
        self::assertSame('Archive', $result['to_folder']);
        self::assertSame('archive', $result['destination']);
        // The new uid in the destination, which is not the uid passed in.
        self::assertSame(3, $result['new_uid']);
    }

    public function test_a_move_verifies_by_message_id_because_the_library_loses_the_new_uid(): void
    {
        // Finding 26: `move()` returns NULL — Dovecot reports the new UID in
        // an *untagged* `COPYUID` that the library reads from the *tagged*
        // response. So the destination is searched for the Message-ID, and
        // that search is what the assertion is about.
        $server = $this->movableInbox();

        $this->moveTool($server, moveFromFolders: 'INBOX')->moveEmail(folder: 'INBOX', uid: 1, destination: 'archive');

        self::assertStringContainsString('HEADER "Message-ID"', $server->commandLog());
    }

    public function test_a_move_that_cannot_be_observed_is_not_reported_as_done(): void
    {
        // The message vanishes: the destination search finds nothing. A tool
        // that echoed `moved: true` here would be asserting something it
        // cannot see.
        $server = $this->movableInbox(willBeFound: false);

        $this->expectException(MoveNotObserved::class);
        $this->expectExceptionMessageMatches('/not being reported as complete/');

        $this->moveTool($server, moveFromFolders: 'INBOX')->moveEmail(folder: 'INBOX', uid: 1, destination: 'archive');
    }

    public function test_trash_resolves_through_the_delete_alias_when_both_are_set(): void
    {
        // `IMAP_DELETE_FOLDER` wins over `IMAP_TRASH_FOLDER`: an operator who
        // set both was more specific.
        $server = $this->movableInbox(destinationFolder: 'Pending-Delete');

        $result = $this->moveTool(
            $server,
            moveFromFolders: 'INBOX',
            trashFolder: 'Trash',
            deleteFolder: 'Pending-Delete',
        )->moveEmail(folder: 'INBOX', uid: 1, destination: 'trash');

        self::assertSame('Pending-Delete', $result['to_folder']);
    }

    public function test_trash_falls_back_to_the_trash_folder_when_the_alias_is_unset(): void
    {
        $server = $this->movableInbox(destinationFolder: 'Trash', destination: 'trash');

        $result = $this->moveTool($server, moveFromFolders: 'INBOX', trashFolder: 'Trash')->moveEmail(
            folder: 'INBOX',
            uid: 1,
            destination: 'trash',
        );

        self::assertSame('Trash', $result['to_folder']);
    }

    public function test_an_unconfigured_destination_is_refused_by_name(): void
    {
        // A deployment that has not said where trash is does not have a
        // trash. The message names the variable to set.
        $server = $this->movableInbox();

        try {
            $this->moveTool($server, moveFromFolders: 'INBOX')->moveEmail(folder: 'INBOX', uid: 1, destination: 'trash');
            self::fail('trash is not configured');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('IMAP_TRASH_FOLDER', $e->getMessage());
            self::assertStringContainsString('IMAP_DELETE_FOLDER', $e->getMessage());
        }

        self::assertStringNotContainsString('MOVE', $server->commandLog());
    }

    public function test_folder_destination_requires_a_target_and_checks_the_target_allowlist(): void
    {
        $server = $this->movableInbox();

        // Missing target: named specifically, because the alternative is a
        // caller guessing that "folder" needed a second parameter.
        try {
            $this->moveTool($server, moveFromFolders: 'INBOX')->moveEmail(folder: 'INBOX', uid: 1, destination: 'folder');
            self::fail('target_folder is required');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('target_folder is required', $e->getMessage());
        }

        // A target outside the allowlist is refused, even though the source
        // is permitted.
        try {
            $this->moveTool($server, moveFromFolders: 'INBOX')->moveEmail(
                folder: 'INBOX',
                uid: 1,
                destination: 'folder',
                target_folder: 'Junk',
            );
            self::fail('Junk is not in IMAP_MOVE_TARGET_FOLDERS');
        } catch (FolderNotAllowedException $e) {
            self::assertStringContainsString('IMAP_MOVE_TARGET_FOLDERS', $e->getMessage());
        }

        self::assertStringNotContainsString('MOVE', $server->commandLog());
    }

    public function test_named_destinations_do_not_require_the_target_allowlist(): void
    {
        // Each destination class is separately configured. `archive` answers
        // to `IMAP_ARCHIVE_FOLDER`, not to `IMAP_MOVE_TARGET_FOLDERS` — so a
        // deployment can offer the named destinations without opening the
        // escape hatch.
        $server = $this->movableInbox();

        $result = $this->moveTool($server, moveFromFolders: 'INBOX', moveToFolders: '')
            ->moveEmail(folder: 'INBOX', uid: 1, destination: 'archive');

        self::assertSame('Archive', $result['to_folder']);
    }

    public function test_a_move_from_a_folder_outside_the_source_allowlist_is_refused(): void
    {
        $server = $this->movableInbox();

        try {
            $this->moveTool($server, moveFromFolders: 'Archive')
                ->moveEmail(folder: 'INBOX', uid: 1, destination: 'archive');
            self::fail('INBOX is not in IMAP_MOVE_SOURCE_FOLDERS');
        } catch (FolderNotAllowedException $e) {
            self::assertStringContainsString('IMAP_MOVE_SOURCE_FOLDERS', $e->getMessage());
        }

        self::assertStringNotContainsString('MOVE', $server->commandLog());
    }

    public function test_an_unknown_destination_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/destination must be one of/');

        $this->moveTool($this->movableInbox())->moveEmail(
            folder: 'INBOX',
            uid: 1,
            destination: 'somewhere',
        );
    }

    public function test_a_read_only_folder_does_not_grant_moving_from_it(): void
    {
        // Reading a folder is the only capability on by default, and it
        // grants nothing else — move specifically, because that is the one
        // that removes a message.
        $server = $this->movableInbox();

        $this->expectException(FolderNotAllowedException::class);

        $this->moveTool($server, moveFromFolders: 'Archive')
            ->moveEmail(folder: 'INBOX', uid: 1, destination: 'archive');
    }

    // ── Destinations ────────────────────────────────────────────────────

    public function test_move_destination_reports_which_named_destinations_exist(): void
    {
        $none = new MoveDestination();
        self::assertSame([], $none->configuredNames());
        self::assertFalse($none->isConfigured(MoveDestination::TRASH));

        $both = new MoveDestination(trashFolder: 'Trash', archiveFolder: 'Archive');
        self::assertSame(['trash', 'archive'], $both->configuredNames());
    }

    public function test_the_delete_alias_alone_is_enough_to_configure_trash(): void
    {
        // The spelling most operators will actually use.
        $destinations = new MoveDestination(deleteFolder: 'Pending-Delete');

        self::assertTrue($destinations->isConfigured(MoveDestination::TRASH));
        self::assertSame('Pending-Delete', $destinations->forName(MoveDestination::TRASH));
    }

    public function test_folder_destination_reflects_the_target_verbatim(): void
    {
        $destinations = new MoveDestination();

        self::assertSame('Projects/Widget', $destinations->forName(MoveDestination::FOLDER, 'Projects/Widget'));
        self::assertNull($destinations->forName(MoveDestination::FOLDER, null));
        self::assertNull($destinations->forName(MoveDestination::FOLDER, '   '));
    }

    // ── Harness ─────────────────────────────────────────────────────────

    /**
     * An INBOX whose single message has the given flags, and that can be
     * tagged.
     *
     * The response is scripted to return `$flagsAfter` on the *re-read*, so
     * the tool's claim about observed state has something to be right or
     * wrong about.
     */
    private function inboxWithFlags(string $folder, string $flagsAfter = 'AiRead', ?int $uid = 1): FakeImapServer
    {
        $server = new FakeImapServer();
        $server->folder($folder);
        $server->selectable(1);
        $server->status($folder, 1, 0);

        // Three replies are needed and each is keyed on what is actually
        // asked for: the lookup (`FLAGS`), the write acknowledgement
        // (`STORE`), then the verifying re-read (`FLAGS` again). The queue
        // hands them out in order, so the second FLAGS reply is the one that
        // describes the state after the change — which is what the tool's
        // claim is about.
        $server->on('STORE', []);
        $server->on('UID FETCH', [
            '* '.($uid ?? 1).' FETCH (UID '.($uid ?? 1).' FLAGS ('.('' === $flagsAfter ? '' : $flagsAfter).') RFC822.SIZE 300)',
        ]);

        return $server;
    }

    private function taggableInbox(?int $uid = 1): FakeImapServer
    {
        $server = $this->inboxWithFlags('INBOX', 'AiRead', $uid);

        // The verifying re-read after the write is a `FLAGS`-only fetch for
        // the id the test declared. Registering it here rather than in
        // `inboxWithFlags` keeps the *lookup* and the *verification* as
        // separate replies, which is what makes the "observed state" claim
        // testable.
        $server->message($uid ?? 1);
        $server->on('UID FETCH', [
            '* '.($uid ?? 1).' FETCH (UID '.($uid ?? 1).' FLAGS (AiRead) RFC822.SIZE 300)',
        ]);

        return $server;
    }

    private function markableInbox(bool $seenAfter = true): FakeImapServer
    {
        $server = $this->inboxWithFlags('INBOX', $seenAfter ? '\\Seen' : '');

        $server->on('UID FETCH', [
            '* 1 FETCH (UID 1 FLAGS ('.($seenAfter ? '\\Seen' : '').') RFC822.SIZE 300)',
        ]);

        return $server;
    }

    /**
     * An INBOX whose message can be moved to `$destinationFolder`.
     *
     * The verification search is the interesting part: `willBeFound: false`
     * scripts a destination that does *not* contain the message, which is
     * how the `MoveNotObserved` path is reached.
     */
    private function movableInbox(
        string $destinationFolder = 'Archive',
        string $destination = 'archive',
        bool $willBeFound = true,
    ): FakeImapServer {
        $headers = "From: Sender <sender@example.com>\r\n"
            ."Subject: Movable\r\n"
            ."Message-ID: <movable@example.com>\r\n\r\n";

        $server = new FakeImapServer();
        $server->folder('INBOX');
        $server->folder($destinationFolder);
        $server->selectable(1);
        $server->status('INBOX', 1, 0);
        $server->status($destinationFolder, $willBeFound ? 1 : 0, 0);

        // The source lookup asks for `FLAGS BODY.PEEK[HEADER]` — not
        // BODYSTRUCTURE — because `moveMessage()` needs the Message-ID and
        // nothing else. Keying the reply on the item that is actually
        // requested is what makes the fixture model the client.
        $server->message(1);
        $server->on('BODY.PEEK[HEADER]', [
            \sprintf('* 1 FETCH (UID 1 FLAGS () RFC822.SIZE 300 BODY[HEADER] {%d}', \strlen($headers)),
            $headers,
            ')',
        ]);

        $server->on('MOVE', []);

        // The destination search is two commands: a `SEARCH HEADER` to find
        // the uid, then the ordinary `(UID)` lookup that `find()` always
        // performs. When the message is not there, the search returns
        // nothing and the lookup must not be answered at all.
        $server->on('HEADER', $willBeFound ? ['* SEARCH 3'] : ['* SEARCH']);

        if ($willBeFound) {
            $server->on('UID FETCH', [
                '* 3 FETCH (UID 3 FLAGS (AiRead) RFC822.SIZE 300)',
            ]);
        }

        return $server;
    }

    /**
     * A tag tool whose allowlists are exactly what the test passes.
     *
     * The defaults are deliberately **empty**: a helper that grants a
     * permission by default would let a test asserting "this is refused"
     * pass or fail for the wrong reason.
     */
    private function tagTool(
        FakeImapServer $server,
        string $tagFolders = '',
        string $readFolders = '',
    ): TagEmailTool {
        return new TagEmailTool($this->reader($server, $readFolders, $tagFolders), $this->factory($server));
    }

    private function markTool(
        FakeImapServer $server,
        string $markFolders = '',
        string $tagFolders = '',
    ): MarkEmailTool {
        return new MarkEmailTool($this->reader($server, '', $tagFolders, $markFolders), $this->factory($server));
    }

    private function moveTool(
        FakeImapServer $server,
        string $moveFromFolders = '',
        string $moveToFolders = '',
        string $trashFolder = '',
        string $archiveFolder = 'Archive',
        string $deleteFolder = '',
    ): MoveEmailTool {
        $reader = $this->reader(
            $server,
            '',
            '',
            '',
            $moveFromFolders,
            $moveToFolders,
        );

        return new MoveEmailTool(
            $reader,
            $this->factory($server),
            new MoveDestination($trashFolder, $archiveFolder, $deleteFolder),
        );
    }

    private function reader(
        FakeImapServer $server,
        string $readFolders = '',
        string $tagFolders = '',
        string $markFolders = '',
        string $moveFromFolders = '',
        string $moveToFolders = '',
    ): EmailReader {
        return new EmailReader(
            new ImapClient($this->factory($server)),
            new FolderGate(
                readFolders: $readFolders,
                tagFolders: $tagFolders,
                markFolders: $markFolders,
                moveFromFolders: $moveFromFolders,
                moveToFolders: $moveToFolders,
            ),
        );
    }

    private function factory(FakeImapServer $server): ImapConnectionFactory
    {
        return new ImapConnectionFactory('fake', 143, 'u', 'p', 'tcp', 15.0, $server->connection());
    }
}
