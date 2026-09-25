<?php

declare(strict_types=1);

namespace App\Email\Domain;

/**
 * One folder as the tools describe it.
 *
 * `path` is the identifier and `name` is the label — the same split
 * `CalendarInfo` makes between `href` and `name`, and for the same reason:
 * the reference server's `displayname` equivalent returned a path, so a name
 * is never a key.
 *
 * `path` is the **decoded** form. Internally IMAP folder identity is
 * modified UTF-7 (finding 19: a mailbox named `Tëst-Ünicode` has the raw
 * path `T&AOs-st-&ANw-nicode`), and the library's own folder comparison
 * uses the raw form — so a folder built from a human-readable name does not
 * match the discovered one. Decoding once, at the boundary, means every
 * comparison above this point — allowlists, tool arguments, output — speaks
 * the name the operator typed.
 */
final readonly class MailFolder
{
    public function __construct(
        public string $path,
        public string $name,
        public FolderAccess $access,
        public int $messageCount = 0,
        public int $unreadCount = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'readable' => $this->access->read,
            'tagging' => $this->access->tag,
            'markable' => $this->access->mark,
            'movable_from' => $this->access->moveFrom,
            'move_target' => $this->access->moveTo,
            'message_count' => $this->messageCount,
            'unread_count' => $this->unreadCount,
        ];
    }
}
