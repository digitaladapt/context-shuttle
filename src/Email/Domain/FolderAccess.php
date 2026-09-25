<?php

declare(strict_types=1);

namespace App\Email\Domain;

/**
 * What a deployment may do with one folder.
 *
 * The design's core idea is that *every operation is gated by its own folder
 * allowlist* — reading, tagging, marking, moving in and moving out are
 * separate permissions. This object is that gate's answer, computed once per
 * folder so a caller can discover the boundary (via `list_email_folders`)
 * without tripping it.
 *
 * Composing the flags rather than reading server flags is deliberate:
 * finding 9 showed that special-use attributes (`\Trash`, `\Archive`,
 * `\Junk`) did not survive `LIST` on the reference server, so asking the
 * server "what is this folder for?" is not portable. Asking *our
 * configuration* is.
 *
 * This is the one type in the email namespace that never reaches a tool
 * result verbatim: it is flattened onto a folder row, and its whole purpose
 * is to say which operations will be refused.
 *
 * All flags are off by default. A deployment that configures nothing gets a
 * value object that permits nothing, which is the correct default for
 * mailbox state.
 */
final readonly class FolderAccess
{
    public function __construct(
        public bool $read = false,
        public bool $tag = false,
        public bool $mark = false,
        public bool $moveFrom = false,
        public bool $moveTo = false,
    ) {
    }

    /**
     * A folder with no permissions at all — the identity for "this folder is
     * invisible to us", either because no allowlist names it or because it
     * does not exist on the server.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * True when this deployment can do nothing with the folder, in which
     * case it is omitted from listings: a caller seeing a folder it cannot
     * touch learns nothing except how to be told no.
     */
    public function isEmpty(): bool
    {
        return !$this->read && !$this->tag && !$this->mark && !$this->moveFrom && !$this->moveTo;
    }
}
