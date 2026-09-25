<?php

declare(strict_types=1);

namespace App\Tool\Email;

use App\Email\Domain\MailFolder;
use App\Email\EmailReader;
use App\Email\Imap\ImapConnectionFactory;
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
        ];

        // A configured folder that matched nothing is a deployment mistake
        // worth surfacing: a typo in IMAP_TRASH_FOLDER would otherwise look
        // exactly like a mail server with no trash.
        if ([] !== $result['unmatched']) {
            $payload['warnings'] = \sprintf(
                'These configured folders do not exist on the mail server: %s.',
                implode(', ', $result['unmatched']),
            );
        }

        return $payload;
    }
}
