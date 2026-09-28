<?php

declare(strict_types=1);

namespace App\Email;

use App\Email\Domain\FolderAccess;
use App\Email\Domain\MailFolder;

/**
 * The folder gate: which operations this deployment permits on which folders.
 *
 * **This is the core of the email design.** Each operation carries its own
 * allowlist, and a request naming a folder outside the relevant list is
 * *refused* — never quietly narrowed, because a silently narrowed write is
 * how a model concludes "done" when nothing happened.
 *
 * Read is the only capability that can be on by default, and even it is
 * opt-in (`IMAP_READ_FOLDERS` is required). Everything that changes mailbox
 * state — tagging, marking, moving — is off until an operator names folders.
 * A fresh deployment therefore has read tools and nothing else.
 *
 * ## Matching
 *
 * Comparisons happen on a **canonical identity** — {@see FolderIdentity},
 * which the client's own folder resolver uses too, so permission and
 * resolution cannot disagree. A folder name is decoded from IMAP's modified
 * UTF-7 (finding 19: a mailbox named `Tëst-Ünicode` travels as
 * `T&AOs-st-&ANw-nicode`), its `.`/`/` hierarchy separators are folded to
 * one, and it is lower-cased. Two names that differ only in case or separator
 * are therefore the same mailbox: `inbox/bob`, `Inbox.Bob` and `INBOX/BOB`
 * all denote one folder.
 *
 * This is deliberately more forgiving than the protocol. IMAP guarantees
 * case-insensitivity only for `INBOX`, and a server treats `.` and `/` as
 * distinct separators — but a caller naming a real folder with imperfect
 * spelling is naming that folder, and the failure mode of refusing it is a
 * model that reports "done" without having done anything. The cost is the one
 * this design has always accepted in exchange: an operator cannot keep both
 * `Archive` and `archive` (or `Bank.2026` and `Bank/2026`) as distinct
 * mailboxes with distinct permissions. A server holding both is pathological,
 * and ambiguous to a human too.
 *
 * The server's own spelling is what is **shown** and what is **sent**; only
 * the comparison is canonical.
 *
 * ## Configuring a folder that does not exist
 *
 * An entry naming no real folder is reported as a configuration error at
 * invocation (not silently ignored): a typo in `IMAP_TRASH_FOLDER` would
 * otherwise look exactly like a mail server with no trash.
 */
final readonly class FolderGate
{
    public const OPERATION_READ = 'read';

    public const OPERATION_TAG = 'tag';

    public const OPERATION_MARK = 'mark';

    public const OPERATION_MOVE_FROM = 'move-from';

    public const OPERATION_MOVE_TO = 'move-to';

    /**
     * @param string $readFolders     comma-separated folder names, `IMAP_READ_FOLDERS`
     * @param string $tagFolders      `IMAP_TAG_FOLDERS`
     * @param string $markFolders     `IMAP_MARK_FOLDERS`
     * @param string $moveFromFolders `IMAP_MOVE_SOURCE_FOLDERS`
     * @param string $moveToFolders   `IMAP_MOVE_TARGET_FOLDERS` plus the named destinations
     *
     * Takes the raw env strings rather than pre-split arrays so the
     * container has nothing to coerce: `%imap_read_folders%` is a string
     * from `.env`, and parsing it here means an operator's `INBOX, Archive`
     * (with the space they naturally typed) works.
     */
    public function __construct(
        private string $readFolders = '',
        private string $tagFolders = '',
        private string $markFolders = '',
        private string $moveFromFolders = '',
        private string $moveToFolders = '',
    ) {
    }

    /**
     * Parse a comma-separated env value into a list of decoded folder names.
     *
     * Empty entries are dropped rather than treated as "the root folder": a
     * trailing comma in `.env` must not become a permission.
     *
     * @return list<string>
     */
    public static function parseList(string $value): array
    {
        $folders = [];

        foreach (explode(',', $value) as $folder) {
            $folder = trim($folder);
            if ('' !== $folder) {
                $folders[] = $folder;
            }
        }

        return array_values(array_unique($folders));
    }

    /**
     * Every configured folder, from every list, deduplicated.
     *
     * @return list<string>
     */
    private function allConfigured(): array
    {
        return array_values(array_unique(array_merge(
            $this->list($this->readFolders),
            $this->list($this->tagFolders),
            $this->list($this->markFolders),
            $this->list($this->moveFromFolders),
            $this->list($this->moveToFolders),
        )));
    }

    /**
     * Split one env value, memoized.
     *
     * `access()` runs once per folder per listing and the split is pure, so
     * re-splitting the same string for every folder is wasted work. A
     * readonly class cannot memoize into a property, so the cache is static
     * and keyed by the raw string — which is also safe, because the value
     * comes from configuration and cannot change within a process.
     *
     * @return list<string>
     */
    private function list(string $value): array
    {
        /** @var array<string, list<string>> $cache */
        static $cache = [];

        return $cache[$value] ??= self::parseList($value);
    }

    /**
     * The access this deployment grants one folder.
     *
     * `$folder` may be either the decoded path or a raw server path; both are
     * normalized before comparison so a caller cannot accidentally bypass a
     * rule by passing the encoded form.
     */
    public function access(string $folder): FolderAccess
    {
        return new FolderAccess(
            read: $this->matches($folder, $this->list($this->readFolders)),
            tag: $this->matches($folder, $this->list($this->tagFolders)),
            mark: $this->matches($folder, $this->list($this->markFolders)),
            moveFrom: $this->matches($folder, $this->list($this->moveFromFolders)),
            moveTo: $this->matches($folder, $this->list($this->moveToFolders)),
        );
    }

    /**
     * Whether one folder is allowed for one operation.
     */
    public function allows(string $operation, string $folder): bool
    {
        return match ($operation) {
            self::OPERATION_READ => $this->access($folder)->read,
            self::OPERATION_TAG => $this->access($folder)->tag,
            self::OPERATION_MARK => $this->access($folder)->mark,
            self::OPERATION_MOVE_FROM => $this->access($folder)->moveFrom,
            self::OPERATION_MOVE_TO => $this->access($folder)->moveTo,
            default => false,
        };
    }

    /**
     * Refuse a folder the operation's allowlist does not name.
     *
     * The message names the operation, the folder and the env var that would
     * allow it, so a caller (or the operator reading a log) can act on it
     * rather than guessing. A model told only "permission denied" retries;
     * a model told which env var is unset can say what needs configuring.
     *
     * @throws FolderNotAllowedException
     */
    public function assertAllowed(string $operation, string $folder): void
    {
        if ($this->allows($operation, $folder)) {
            return;
        }

        $envVar = match ($operation) {
            self::OPERATION_READ => 'IMAP_READ_FOLDERS',
            self::OPERATION_TAG => 'IMAP_TAG_FOLDERS',
            self::OPERATION_MARK => 'IMAP_MARK_FOLDERS',
            self::OPERATION_MOVE_FROM => 'IMAP_MOVE_SOURCE_FOLDERS',
            self::OPERATION_MOVE_TO => 'IMAP_MOVE_TARGET_FOLDERS',
            default => null,
        };

        throw new FolderNotAllowedException(\sprintf('This deployment is not permitted to %s the folder "%s".%s', $operation, $folder, null === $envVar ? '' : \sprintf(' Set %s to allow it.', $envVar)));
    }

    /**
     * Attach access flags to discovered folders, dropping the ones this
     * deployment can do nothing with.
     *
     * A caller seeing a folder it cannot touch learns nothing except how to
     * be told no, so those are omitted rather than listed with every flag
     * false.
     *
     * @param list<MailFolder> $folders
     *
     * @return list<MailFolder>
     */
    public function apply(array $folders): array
    {
        $visible = [];

        foreach ($folders as $folder) {
            $access = $this->access($folder->path);

            if ($access->isEmpty()) {
                continue;
            }

            $visible[] = new MailFolder(
                path: $folder->path,
                name: $folder->name,
                access: $access,
                messageCount: $folder->messageCount,
                unreadCount: $folder->unreadCount,
            );
        }

        // Order by name for a stable listing, matching the calendar tools'
        // rule: a listing that reorders between calls describes a different
        // mailbox.
        usort($visible, static fn (MailFolder $a, MailFolder $b): int => strcasecmp($a->path, $b->path));

        return $visible;
    }

    /**
     * Configured folders that matched no real folder.
     *
     * Reported at invocation rather than silently ignored — see the class
     * docblock. `INBOX` is excluded because it always exists per RFC 3501.
     *
     * @param list<string> $discovered decoded paths of folders that exist
     *
     * @return list<string> the configured names with no counterpart
     */
    public function unmatched(array $discovered): array
    {
        $known = array_map(static fn (string $path): string => self::normalize($path), $discovered);

        $missing = [];

        foreach ($this->allConfigured() as $configured) {
            $normalized = self::normalize($configured);

            if ('inbox' === $normalized) {
                continue;
            }

            if (!\in_array($normalized, $known, true) && !\in_array($configured, $missing, true)) {
                $missing[] = $configured;
            }
        }

        return $missing;
    }

    /**
     * Compare one folder against one allowlist.
     *
     * @param list<string> $allowed
     */
    private function matches(string $folder, array $allowed): bool
    {
        $candidate = self::normalize($folder);

        foreach ($allowed as $entry) {
            if ($candidate === self::normalize($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The comparable form of a folder name — {@see FolderIdentity::canonical()}.
     *
     * A named method rather than calling the helper inline at each site, so
     * the rule is stated once and the class docblock's promise has a single
     * place to be true or false.
     */
    private static function normalize(string $folder): string
    {
        return FolderIdentity::canonical($folder);
    }
}
