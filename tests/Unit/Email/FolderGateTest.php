<?php

declare(strict_types=1);

namespace App\Tests\Unit\Email;

use App\Email\Domain\FolderAccess;
use App\Email\Domain\MailFolder;
use App\Email\FolderGate;
use App\Email\FolderNotAllowedException;
use PHPUnit\Framework\TestCase;

/**
 * The folder gate — the permission model.
 *
 * These tests are the design's central promise made executable: each
 * operation has its own allowlist, nothing is on unless an operator named a
 * folder, and a refusal says which env var would allow it.
 *
 * @internal
 *
 * @covers \App\Email\FolderGate
 * @covers \App\Email\FolderNotAllowedException
 */
final class FolderGateTest extends TestCase
{
    public function test_nothing_is_permitted_by_default(): void
    {
        $gate = new FolderGate();

        foreach (['INBOX', 'Archive', 'Trash'] as $folder) {
            foreach ([
                FolderGate::OPERATION_READ,
                FolderGate::OPERATION_TAG,
                FolderGate::OPERATION_MARK,
                FolderGate::OPERATION_MOVE_FROM,
                FolderGate::OPERATION_MOVE_TO,
            ] as $operation) {
                self::assertFalse(
                    $gate->allows($operation, $folder),
                    \sprintf('%s must be denied for %s by default', $operation, $folder),
                );
            }
        }
    }

    public function test_each_operation_has_its_own_allowlist(): void
    {
        $gate = new FolderGate(readFolders: 'INBOX');

        self::assertTrue($gate->allows(FolderGate::OPERATION_READ, 'INBOX'));

        // The whole point: read access to a folder grants nothing else on it.
        self::assertFalse($gate->allows(FolderGate::OPERATION_TAG, 'INBOX'));
        self::assertFalse($gate->allows(FolderGate::OPERATION_MARK, 'INBOX'));
        self::assertFalse($gate->allows(FolderGate::OPERATION_MOVE_FROM, 'INBOX'));
        self::assertFalse($gate->allows(FolderGate::OPERATION_MOVE_TO, 'INBOX'));
    }

    public function test_source_and_target_move_lists_are_separate(): void
    {
        $gate = new FolderGate(moveFromFolders: 'INBOX', moveToFolders: 'Archive');

        self::assertTrue($gate->allows(FolderGate::OPERATION_MOVE_FROM, 'INBOX'));
        self::assertFalse($gate->allows(FolderGate::OPERATION_MOVE_TO, 'INBOX'));

        self::assertTrue($gate->allows(FolderGate::OPERATION_MOVE_TO, 'Archive'));
        self::assertFalse($gate->allows(FolderGate::OPERATION_MOVE_FROM, 'Archive'));
    }

    public function test_inbox_is_matched_case_insensitively(): void
    {
        $gate = new FolderGate(readFolders: 'INBOX');

        // IMAP requires INBOX to be case-insensitive, so a deployment that
        // wrote it in any case must work.
        self::assertTrue($gate->allows(FolderGate::OPERATION_READ, 'inbox'));
        self::assertTrue($gate->allows(FolderGate::OPERATION_READ, 'Inbox'));
        self::assertTrue($gate->allows(FolderGate::OPERATION_READ, 'INBOX'));
    }

    public function test_other_folders_are_ca_se_sensitive(): void
    {
        $gate = new FolderGate(readFolders: 'Archive');

        self::assertTrue($gate->allows(FolderGate::OPERATION_READ, 'Archive'));

        // Matching these would silently widen an operator's allowlist.
        self::assertFalse($gate->allows(FolderGate::OPERATION_READ, 'archive'));
        self::assertFalse($gate->allows(FolderGate::OPERATION_READ, 'ARCHIVE'));
    }

    public function test_non_ascii_folders_match_across_the_imap_utf7_boundary(): void
    {
        // Finding 19: the server reports `T&AOs-st-&ANw-nicode` for a folder
        // named `Tëst-Ünicode`, and the library compares raw paths. An
        // operator writing the readable name in `.env` must still match.
        $gate = new FolderGate(readFolders: 'Tëst-Ünicode');

        self::assertTrue($gate->allows(FolderGate::OPERATION_READ, 'Tëst-Ünicode'));
        self::assertTrue($gate->allows(FolderGate::OPERATION_READ, 'T&AOs-st-&ANw-nicode'));
    }

    public function test_parse_list_trims_and_drops_empty_entries(): void
    {
        // A trailing comma in .env must not become a permission.
        self::assertSame(['INBOX', 'Archive'], FolderGate::parseList(' INBOX , Archive ,, '));
        self::assertSame([], FolderGate::parseList(''));
        self::assertSame([], FolderGate::parseList('  ,  ,  '));
    }

    public function test_a_refusal_names_the_env_var_that_would_allow_it(): void
    {
        $gate = new FolderGate();

        try {
            $gate->assertAllowed(FolderGate::OPERATION_TAG, 'INBOX');
            self::fail('expected a refusal');
        } catch (FolderNotAllowedException $e) {
            self::assertStringContainsString('tag', $e->getMessage());
            self::assertStringContainsString('INBOX', $e->getMessage());
            self::assertStringContainsString('IMAP_TAG_FOLDERS', $e->getMessage());
        }
    }

    public function test_a_permitted_operation_does_not_throw(): void
    {
        $gate = new FolderGate(readFolders: 'INBOX');

        $gate->assertAllowed(FolderGate::OPERATION_READ, 'INBOX');

        // assertAllowed is void; reaching here without an exception is the
        // assertion. Adding a concrete one keeps PHPUnit from marking the
        // test as risky.
        $this->addToAssertionCount(1);
    }

    public function test_apply_drops_folders_with_no_permission(): void
    {
        $gate = new FolderGate(readFolders: 'INBOX', moveToFolders: 'Archive');

        $folders = [
            new MailFolder('INBOX', 'INBOX', FolderAccess::none()),
            new MailFolder('Archive', 'Archive', FolderAccess::none()),
            new MailFolder('Junk', 'Junk', FolderAccess::none()),
        ];

        $visible = $gate->apply($folders);

        // Junk is grantable by nothing, so it is omitted entirely: a caller
        // seeing a folder it cannot touch learns nothing except how to be
        // told no.
        self::assertSame(['Archive', 'INBOX'], array_map(
            static fn (MailFolder $f): string => $f->path,
            $visible,
        ));
    }

    public function test_apply_attaches_the_right_flags_per_folder(): void
    {
        $gate = new FolderGate(readFolders: 'INBOX,Archive', tagFolders: 'INBOX');

        $visible = $gate->apply([
            new MailFolder('INBOX', 'INBOX', FolderAccess::none()),
            new MailFolder('Archive', 'Archive', FolderAccess::none()),
        ]);

        foreach ($visible as $folder) {
            if ('INBOX' === $folder->path) {
                self::assertTrue($folder->access->read);
                self::assertTrue($folder->access->tag);
                self::assertFalse($folder->access->mark);
            } else {
                self::assertTrue($folder->access->read);
                self::assertFalse($folder->access->tag);
            }
        }
    }

    public function test_unmatched_reports_configured_folders_that_do_not_exist(): void
    {
        $gate = new FolderGate(readFolders: 'INBOX,Archive', markFolders: 'NoSuchFolder');

        // A typo in a configured folder would otherwise look exactly like a
        // mail server missing that folder.
        self::assertSame(['NoSuchFolder'], $gate->unmatched(['INBOX', 'Archive', 'Junk']));
    }

    public function test_unmatched_never_reports_inbox(): void
    {
        // INBOX always exists per RFC 3501, so reporting it would be noise.
        $gate = new FolderGate(readFolders: 'INBOX');

        self::assertSame([], $gate->unmatched([]));
    }

    public function test_unmatched_is_deduplicated_and_includes_move_targets(): void
    {
        $gate = new FolderGate(readFolders: 'Nowhere', tagFolders: 'Nowhere', moveToFolders: 'Elsewhere');

        self::assertSame(['Nowhere', 'Elsewhere'], $gate->unmatched(['INBOX']));
    }
}
