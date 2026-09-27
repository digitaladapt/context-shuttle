<?php

declare(strict_types=1);

namespace App\Email\Imap;

use RuntimeException;

/**
 * The named folder does not exist on this mail server.
 *
 * Distinct from {@see \App\Email\FolderNotAllowedException} on purpose, and
 * the distinction is the whole reason this class exists: "you may not touch
 * that folder" and "that folder does not exist" look identical on the wire
 * and were previously both reported as a permission problem, which sent an
 * operator to the env file for a folder whose name was simply misspelled.
 *
 * The message names the folder the caller typed and points at the tool that
 * lists the real names, so the fix is a rename rather than a configuration
 * change.
 */
final class FolderNotFound extends RuntimeException
{
    public function __construct(
        public readonly string $folder,
    ) {
        parent::__construct(\sprintf(
            'There is no folder named "%s" on this mail server. Folder names are case-sensitive (except the INBOX part), '
            .'and a hierarchy may use a separator you did not expect — use list_email_folders to see the exact names, '
            .'then check IMAP_READ_FOLDERS (and the other folder lists) for a typo or a wrong spelling.',
            $folder,
        ));
    }
}
