<?php

declare(strict_types=1);

namespace App\Email;

use DirectoryTree\ImapEngine\Support\Str;

/**
 * The identity of a mailbox, for comparison.
 *
 * Two folder names written differently can still name the same mailbox, and
 * the differences that actually occur are exactly three:
 *
 * - **Modified UTF-7.** IMAP folder identity on the wire is mUTF-7 (finding
 *   19): `Tëst-Ünicode` travels as `T&AOs-st-&ANw-nicode`. Decoding first
 *   means everything downstream compares human-readable names.
 * - **Case.** IMAP guarantees only that `INBOX` is case-insensitive, but an
 *   operator does not mean `Archive` and `archive` to be different mailboxes —
 *   and a small model that capitalises one and not the other is naming the
 *   same folder.
 * - **Separator.** `.` and `/` are both established hierarchy separators, and
 *   which one a deployment happens to use is a property of the server, not of
 *   the mailbox: a name configured `INBOX.Bank` and a server path
 *   `INBOX/Bank` are one folder.
 *
 * {@see self::canonical()} folds all three away to produce a comparison key;
 * {@see self::equals()} is the predicate built on it.
 *
 * **The key is only ever a key.** It is never shown to a caller and never
 * written back to a server — the server's own spelling is what a caller sees
 * and what goes on the wire (the client resolves a caller's spelling to the
 * server's real path before issuing any command). Normalizing a *shown* name
 * would be a lie about what is on the server, which is the opposite of this
 * tool's job.
 *
 * The function is pure and static on purpose: the folder gate, the client's
 * folder resolver and the error paths must all agree on folder identity, and
 * the surest way to make them agree is to give them one function.
 */
final class FolderIdentity
{
    /**
     * A pure function with a name; there is nothing to instantiate.
     */
    private function __construct()
    {
    }

    /**
     * The comparison key for a folder name.
     *
     * Decodes mUTF-7, folds `.` and `/` to a single separator, and lowercases.
     * Idempotent: canonicalising a key returns the key.
     */
    public static function canonical(string $folder): string
    {
        $folder = trim($folder);

        if (str_contains($folder, '&')) {
            $folder = Str::fromImapUtf7($folder);
        }

        // One separator for the whole comparison. Which one is arbitrary —
        // both sides of every comparison come through here — but `/` is the
        // one the protocol itself uses, so pick it.
        $folder = strtr($folder, ['.' => '/']);

        return mb_strtolower($folder, 'UTF-8');
    }

    /**
     * Whether two folder names name the same mailbox.
     */
    public static function equals(string $a, string $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }
}
