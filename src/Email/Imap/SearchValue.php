<?php

declare(strict_types=1);

namespace App\Email\Imap;

use DirectoryTree\ImapEngine\Connection\RawQueryValue;

/**
 * Builds IMAP search values that survive a non-ASCII search — finding 3.
 *
 * **The defect this exists for.** `ImapQueryBuilder::compileBasic()` runs
 * every plain value through `Str::toImapUtf7()`, converting `Reunión` into
 * `Reuni&APM-n`. Modified UTF-7 is a *folder-name* encoding; as a search
 * term the server treats it as a literal, so it answers `* SEARCH` with no
 * results **and no error**. Verified both ways against Dovecot: the raw
 * UTF-8 form matches, the mUTF-7 form silently matches nothing.
 *
 * A silent zero-match is the worst available failure for "did I miss an
 * email?", which is why this is one of only two places the email code
 * reaches past the library's public API.
 *
 * `RawQueryValue` is the library's own escape hatch — `compileBasic()` emits
 * it verbatim, skipping the conversion — so the quoting RFC 3501 requires
 * becomes our responsibility: escape `\` and `"`, and wrap in quotes.
 *
 * **Wildcards are deliberately not escaped.** `*` and `%` are IMAP's own
 * search wildcards, and a caller searching for `invoi*` is asking a
 * reasonable question. A literal `*` is not worth defending against here:
 * the search is a convenience filter over a folder the caller has already
 * been granted read access to, so the worst case is returning more matches
 * than intended, never disclosing anything it could not list anyway.
 *
 * Control characters **are** stripped. The library's own `Str::escape()`
 * does this, and there is no legitimate reason for a NUL or a CR to reach a
 * command line — a stray CRLF in a search term is how a command gets split.
 */
final class SearchValue
{
    /**
     * Quote a search value for direct use in a query.
     */
    public static function quote(string $value): RawQueryValue
    {
        return new RawQueryValue('"'.self::escape($value).'"');
    }

    /**
     * Escape a value for a quoted string, per RFC 3501 §4.3.
     *
     * `\` must be escaped before `"` or the backslash doubles incorrectly.
     */
    public static function escape(string $value): string
    {
        // Strip CR, LF and other control characters (ASCII 0-31 and 127).
        $value = (string) preg_replace('/[\r\n\x00-\x1F\x7F]/', '', $value);

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
