<?php

declare(strict_types=1);

namespace App\Tool\Email;

use App\Email\EmailReader;
use App\Email\Imap\ImapConnectionFactory;
use InvalidArgumentException;
use RuntimeException;

/**
 * `mark_email_read` / `mark_email_unread` — change the human's unread state.
 *
 * Two methods rather than one `set_seen: boolean`, because
 * `mark_email_read` and `mark_email_unread` are what a person says. One tool
 * with an enum is the same thing wearing a hat; two names are legible
 * without reading a schema.
 *
 * This is the *only* place in the codebase that writes a system flag, and it
 * is gated separately (`IMAP_MARK_FOLDERS`) from tagging. That separation is
 * the design's point: an operator can let the agent label its own state
 * while leaving the shared unread count alone.
 *
 * Both return the state **as the server has it afterwards**, not the state
 * that was requested — the difference between "we issued a STORE" and "the
 * flag is set".
 */
final readonly class MarkEmailTool
{
    public function __construct(
        private EmailReader $reader,
        private ImapConnectionFactory $connection,
    ) {
    }

    /**
     * Mark one message as read.
     *
     * @return array<string, mixed>
     */
    public function markEmailRead(string $folder, int $uid): array
    {
        return $this->mark($folder, $uid, true);
    }

    /**
     * Mark one message as unread.
     *
     * @return array<string, mixed>
     */
    public function markEmailUnread(string $folder, int $uid): array
    {
        return $this->mark($folder, $uid, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function mark(string $folder, int $uid, bool $seen): array
    {
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

        $observed = $this->reader->setSeen($folder, $uid, $seen);

        return [
            'uid' => $uid,
            'folder' => $folder,
            // The observed value, which is what a caller should branch on.
            'seen' => $observed,
        ];
    }
}
