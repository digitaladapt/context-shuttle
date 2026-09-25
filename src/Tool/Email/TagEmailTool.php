<?php

declare(strict_types=1);

namespace App\Tool\Email;

use App\Email\EmailReader;
use App\Email\Imap\ImapConnectionFactory;
use App\Email\TagName;
use InvalidArgumentException;
use RuntimeException;

/**
 * `tag_email` — attach or remove a label the agent uses to track its own
 * state, without touching the human's.
 *
 * The whole point of tags is the split from `\Seen`. `\Seen` is the signal
 * every other mail client shows: an agent marking messages read as it looks
 * at them would be impersonating the human, and would destroy the unread
 * count they rely on. A keyword is the agent's own bookkeeping — per-message,
 * searchable, and invisible unless a client is asked to show it.
 *
 * **The tag is validated, and that validation is load-bearing.** The library's
 * `flag()` accepts a system flag without complaint (finding 23: it set
 * `\Deleted`), so without this check `tag_email` would be a second, ungated
 * path to `\Deleted` — reachable *without* `IMAP_MOVE_SOURCE_FOLDERS`. That
 * would make "there is no delete" a promise about our own code rather than a
 * property of the tool surface. `TagName` is why it stays the latter.
 */
final readonly class TagEmailTool
{
    public function __construct(
        private EmailReader $reader,
        private ImapConnectionFactory $connection,
    ) {
    }

    /**
     * Add or remove a tag on one message.
     *
     * @param string $folder the folder holding the message; must be taggable
     * @param int    $uid    the message's UID in that folder
     * @param string $tag    the label; letters, digits, `.`, `_`, `-`, max 64
     * @param bool   $remove remove the tag instead of adding it
     *
     * @return array<string, mixed>
     */
    public function tagEmail(
        string $folder,
        int $uid,
        string $tag,
        ?bool $remove = null,
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

        // Before the gate, because a malformed tag is malformed whether or
        // not the folder is taggable — and the grammar check is the more
        // specific answer.
        $tag = TagName::assertValid($tag);

        $tags = $this->reader->setTag($folder, $uid, $tag, true === $remove);

        return [
            'uid' => $uid,
            'folder' => $folder,
            'tag' => $tag,
            'removed' => true === $remove,
            'tags' => $tags,
        ];
    }
}
