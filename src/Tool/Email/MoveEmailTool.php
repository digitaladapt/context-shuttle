<?php

declare(strict_types=1);

namespace App\Tool\Email;

use App\Email\EmailReader;
use App\Email\Imap\ImapConnectionFactory;
use App\Email\MoveDestination;
use InvalidArgumentException;
use RuntimeException;

/**
 * `move_email` — the only tool that removes a message from a folder, and it
 * never destroys one.
 *
 * **There is no delete.** IMAP's only delete is a flag plus a mailbox-wide
 * `EXPUNGE`, and expunging would clear messages other clients had flagged —
 * so the destructive verbs are moves into configured folders, where a human
 * can recover them. `destination: "trash"` *is* the "delete", and it points
 * at a folder.
 *
 * Two properties are worth stating because they are what make the tool
 * trustworthy rather than merely careful:
 *
 * - **`to_folder` is where the message was observed*, not the configured
 *   value echoed back. The library's `move()` returns `NULL` for the new UID
 *   (finding 26 — Dovecot reports it in an untagged response the library
 *   reads from the tagged one), so the destination is searched for the
 *   message's `Message-ID` afterwards. "We issued a move" and "it moved" are
 *   different claims, and this tool only makes the second.
 * - **The source and target allowlists are separate checks.** `destination:
 *   "folder"` requires both, so an operator can allow "file things out of
 *   INBOX into Archive" without allowing "pull anything out of Archive".
 */
final readonly class MoveEmailTool
{
    public function __construct(
        private EmailReader $reader,
        private ImapConnectionFactory $connection,
        private MoveDestination $destinations,
    ) {
    }

    /**
     * Move one message to a configured destination.
     *
     * @param string      $folder        source folder; must be a move source
     * @param int         $uid           the message's UID in that folder
     * @param string      $destination   `trash`, `archive` or `folder`
     * @param string|null $target_folder required when `destination` is `folder`
     *
     * @return array<string, mixed>
     */
    public function moveEmail(
        string $folder,
        int $uid,
        string $destination,
        ?string $target_folder = null,
    ): array {
        if (!$this->connection->isConfigured()) {
            throw new RuntimeException('Email is not configured. Set IMAP_HOST, IMAP_USERNAME and IMAP_PASSWORD in .env.local (IMAP_PASSWORD may also live in the secrets vault: bin/console secrets:set IMAP_PASSWORD).');
        }

        $folder = trim($folder);
        if ('' === $folder) {
            throw new InvalidArgumentException('folder must not be empty.');
        }

        if ($uid < 1) {
            throw new InvalidArgumentException('uid must be a positive integer (the uid shown by list_emails).');
        }

        $destination = strtolower(trim($destination));

        if (!\in_array($destination, MoveDestination::ASTRAY, true)) {
            throw new InvalidArgumentException(\sprintf('destination must be one of: %s. Got "%s".', implode(', ', MoveDestination::ASTRAY), $destination));
        }

        if (MoveDestination::FOLDER === $destination && null === $target_folder) {
            // Named specifically, because the alternative is a caller
            // guessing that "folder" needed a second parameter.
            throw new InvalidArgumentException('target_folder is required when destination is "folder" — it names the folder to move into. Use destination "trash" or "archive" for the configured destinations.');
        }

        $to = $this->destinations->forName($destination, $target_folder);

        if (null === $to) {
            throw new InvalidArgumentException($this->unconfiguredMessage($destination));
        }

        $observed = $this->reader->moveMessage($folder, $uid, $to, $destination);

        return [
            'uid' => $uid,
            'from_folder' => $folder,
            'to_folder' => $observed['to_folder'],
            'destination' => $destination,
            'new_uid' => $observed['uid'],
            'tags' => $observed['tags'],
            'moved' => true,
        ];
    }

    /**
     * Why a destination is unavailable, in terms the operator can act on.
     *
     * A missing `target_folder` is a caller error; an unset
     * `IMAP_TRASH_FOLDER` is a deployment choice. The two read differently on
     * purpose.
     */
    private function unconfiguredMessage(string $destination): string
    {
        return match ($destination) {
            MoveDestination::TRASH => 'destination "trash" is not available: this deployment has not configured '
                .'IMAP_TRASH_FOLDER (or IMAP_DELETE_FOLDER) to name a folder. Nothing here deletes — a trash '
                .'destination is a folder a human can recover the message from.',
            MoveDestination::ARCHIVE => 'destination "archive" is not available: this deployment has not '
                .'configured IMAP_ARCHIVE_FOLDER to name a folder.',
            default => \sprintf(
                'target_folder "%s" is not a folder this deployment may move into. Set IMAP_MOVE_TARGET_FOLDERS '
                .'to allow it, or check the folder name with list_email_folders.',
                (string) $this->destinations->forName($destination),
            ),
        };
    }
}
