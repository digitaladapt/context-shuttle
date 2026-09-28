<?php

declare(strict_types=1);

namespace App\Tool\Email;

use App\Email\Domain\MailFolder;
use App\Email\EmailReader;
use App\Email\Imap\ImapConnectionFactory;
use App\Email\MoveDestination;
use RuntimeException;

/**
 * `list_email_folders` — where am I allowed to work?
 *
 * The one tool whose job is to *disclose the permission boundary* rather than
 * to cross it: each folder reports what this deployment lets a caller do with
 * it, computed from the allowlists rather than guessed from server flags
 * (finding 9 — special-use attributes did not survive `LIST` on the reference
 * server, so "is this the trash?" is not answerable by asking the server).
 *
 * Folders the deployment can neither read nor write are omitted: a caller
 * seeing `Sent` in a list it cannot touch learns nothing except how to be
 * told no.
 *
 * Unconfigured, this fails with a sentence naming the env vars — the same
 * contract every other tool here honours, rather than a container
 * `TypeError`.
 */
final readonly class ListFoldersTool
{
    public function __construct(
        private EmailReader $reader,
        private ImapConnectionFactory $connection,
        private MoveDestination $destinations,
    ) {
    }

    /**
     * List the mail folders this deployment may work with.
     *
     * @return array<string, mixed>
     */
    public function listEmailFolders(): array
    {
        if (!$this->connection->isConfigured()) {
            throw new RuntimeException('Email is not configured. Set IMAP_HOST, IMAP_USERNAME and IMAP_PASSWORD in .env.local (IMAP_PASSWORD may also live in the secrets vault: bin/console secrets:set IMAP_PASSWORD).');
        }

        $result = $this->reader->listFolders();

        $payload = [
            'folders' => array_map(
                static fn (MailFolder $folder): array => $folder->toArray(),
                $result['folders'],
            ),
            'count' => \count($result['folders']),
            // Which named destinations exist, so a caller can see that
            // `trash` is unavailable rather than discovering it by being
            // refused — the same "discover the boundary without tripping it"
            // job this tool does for folders.
            'move_destinations' => $this->destinations->configuredNames(),
        ];

        // A configured folder that matched nothing is a deployment mistake
        // worth surfacing: a typo in IMAP_TRASH_FOLDER would otherwise look
        // exactly like a mail server with no trash.
        //
        // The wording says matched, not absent, on purpose — telling an
        // operator a populated folder "does not exist" sends them looking for
        // a missing mailbox instead of a typo. Case and separator are already
        // folded away by the match, so those cannot be the cause and are not
        // offered as suggestions; this is a genuine name mismatch.
        if ([] !== $result['unmatched']) {
            $payload['warnings'] = \sprintf(
                'These configured folders matched no folder on the mail server: %s. '
                .'Case and the `.`/`/` separator are ignored when matching, so this is not a '
                .'casing or separator problem — check the spelling against the names list_email_folders reports.',
                implode(', ', $result['unmatched']),
            );
        }

        return $payload;
    }
}
