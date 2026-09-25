<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Email;

use App\Email\EmailReader;
use App\Email\FolderGate;
use App\Email\Imap\ImapClient;
use App\Email\Imap\ImapConnectionFactory;
use App\Tests\Support\FakeImapServer;
use App\Tool\Email\ListFoldersTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `list_email_folders` — the tool whose job is to disclose the permission
 * boundary rather than to cross it.
 *
 * The interesting assertions are about what is *absent*: a folder the
 * deployment can do nothing with must not appear, because a caller seeing
 * `Sent` in a list it cannot touch learns nothing except how to be told no.
 *
 * @internal
 *
 * @covers \App\Tool\Email\ListFoldersTool
 */
final class ListFoldersToolTest extends TestCase
{
    public function test_only_folders_the_deployment_can_use_are_listed(): void
    {
        $server = new FakeImapServer();
        $server->folder('INBOX');
        $server->folder('Archive');
        $server->folder('Junk');
        $server->status('INBOX', 4, 2);
        $server->status('Archive', 10, 0);
        $server->status('Junk', 99, 99);

        // Read is granted on two of the three; Junk is named nowhere.
        $result = $this->tool($server, readFolders: 'INBOX,Archive')->listEmailFolders();

        $paths = array_column($result['folders'], 'path');
        self::assertContains('INBOX', $paths);
        self::assertContains('Archive', $paths);
        self::assertNotContains('Junk', $paths, 'a folder with no permission must be omitted entirely');
        self::assertSame(2, $result['count']);
    }

    public function test_each_folder_reports_what_may_be_done_with_it(): void
    {
        $server = new FakeImapServer();
        $server->folder('INBOX');
        $server->folder('Archive');
        $server->status('INBOX', 4, 2);
        $server->status('Archive', 10, 0);

        $result = $this->tool(
            $server,
            readFolders: 'INBOX,Archive',
            tagFolders: 'INBOX',
            moveToFolders: 'Archive',
        )->listEmailFolders();

        $byPath = array_column($result['folders'], null, 'path');

        // This is the whole point of the tool: a caller can discover the
        // boundary without tripping it.
        self::assertTrue($byPath['INBOX']['readable']);
        self::assertTrue($byPath['INBOX']['tagging']);
        self::assertFalse($byPath['INBOX']['markable']);
        self::assertFalse($byPath['INBOX']['move_target']);

        self::assertTrue($byPath['Archive']['readable']);
        self::assertFalse($byPath['Archive']['tagging']);
        self::assertTrue($byPath['Archive']['move_target']);
    }

    public function test_permission_flags_come_from_configuration_not_server_flags(): void
    {
        // Finding 9: special-use attributes did not survive `LIST` on the
        // reference server, so "is this the trash?" is not answerable by
        // asking the server. The flags are computed from our allowlists.
        //
        // `Archive` here is a perfectly ordinary mailbox. It reports
        // `move_target: true` purely because the deployment said so.
        $server = new FakeImapServer();
        $server->folder('Archive');
        $server->status('Archive', 1, 1);

        $result = $this->tool($server, moveToFolders: 'Archive')->listEmailFolders();

        $byPath = array_column($result['folders'], null, 'path');
        self::assertTrue($byPath['Archive']['move_target']);
        self::assertFalse($byPath['Archive']['readable']);
    }

    public function test_message_and_unread_counts_are_reported(): void
    {
        $server = new FakeImapServer();
        $server->folder('INBOX');
        $server->status('INBOX', 42, 7);

        $result = $this->tool($server, readFolders: 'INBOX')->listEmailFolders();

        self::assertSame(42, $result['folders'][0]['message_count']);
        self::assertSame(7, $result['folders'][0]['unread_count']);
    }

    public function test_a_nested_folder_reports_its_leaf_name(): void
    {
        $server = new FakeImapServer();
        $server->folder('Projects/Widget');
        $server->status('Projects/Widget', 0, 0);

        $result = $this->tool($server, readFolders: 'Projects/Widget')->listEmailFolders();

        self::assertSame('Projects/Widget', $result['folders'][0]['path']);
        // So a caller does not have to split the path itself.
        self::assertSame('Widget', $result['folders'][0]['name']);
    }

    public function test_a_configured_folder_that_does_not_exist_is_reported(): void
    {
        // A typo in IMAP_TRASH_FOLDER would otherwise look exactly like a
        // mail server with no trash.
        $server = new FakeImapServer();
        $server->folder('INBOX');
        $server->status('INBOX', 0, 0);

        $result = $this->tool($server, readFolders: 'INBOX,NoSuchFolder')->listEmailFolders();

        self::assertArrayHasKey('warnings', $result);
        self::assertStringContainsString('NoSuchFolder', $result['warnings']);
    }

    public function test_no_warning_when_every_configured_folder_exists(): void
    {
        $server = new FakeImapServer();
        $server->folder('INBOX');
        $server->status('INBOX', 0, 0);

        $result = $this->tool($server, readFolders: 'INBOX')->listEmailFolders();

        self::assertArrayNotHasKey('warnings', $result);
    }

    public function test_inbox_is_never_reported_as_missing(): void
    {
        // INBOX always exists per RFC 3501, so reporting it would be noise.
        // It is also the one folder a server need not return from `LIST`.
        $server = new FakeImapServer();
        $server->folder('Archive');
        $server->status('Archive', 0, 0);

        $result = $this->tool($server, readFolders: 'INBOX,Archive')->listEmailFolders();

        self::assertArrayNotHasKey('warnings', $result);
    }

    public function test_an_unconfigured_deployment_names_the_env_vars(): void
    {
        $factory = new ImapConnectionFactory('', 0, '', '', '');
        $reader = new EmailReader(new ImapClient($factory), new FolderGate(readFolders: 'INBOX'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/IMAP_HOST/');

        (new ListFoldersTool($reader, $factory))->listEmailFolders();
    }

    public function test_a_folder_that_cannot_be_measured_is_still_listed(): void
    {
        // A `\Noselect` parent, or a permissions quirk on `STATUS`. Reporting
        // zero is honest ("not known"), and failing the whole listing over
        // one unreadable parent is worse.
        $server = new FakeImapServer();
        $server->folder('Projects', ['\HasChildren']);
        // Deliberately no STATUS handler for it.

        $result = $this->tool($server, readFolders: 'Projects')->listEmailFolders();

        self::assertSame('Projects', $result['folders'][0]['path']);
        self::assertSame(0, $result['folders'][0]['message_count']);
    }

    private function tool(
        FakeImapServer $server,
        string $readFolders = '',
        string $tagFolders = '',
        string $markFolders = '',
        string $moveFromFolders = '',
        string $moveToFolders = '',
    ): ListFoldersTool {
        $factory = new ImapConnectionFactory('fake', 143, 'u', 'p', 'tcp', 15.0, $server->connection());

        $gate = new FolderGate(
            readFolders: $readFolders,
            tagFolders: $tagFolders,
            markFolders: $markFolders,
            moveFromFolders: $moveFromFolders,
            moveToFolders: $moveToFolders,
        );

        return new ListFoldersTool(new EmailReader(new ImapClient($factory), $gate), $factory);
    }
}
