<?php

declare(strict_types=1);

namespace App\Email\Imap;

use DateTimeImmutable;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\RawQueryValue;
use DirectoryTree\ImapEngine\Enums\ImapSearchKey;
use DirectoryTree\ImapEngine\MessageQueryInterface;

/**
 * Filters a listing can ask for, translated to IMAP search keys.
 *
 * Split out from the tool so the mapping — and its two traps — is testable
 * without a server.
 *
 * **Trap one: which date.** `SINCE`/`BEFORE` filter on `INTERNALDATE`, the
 * moment the message was *delivered* to this mailbox; `SENTSINCE`/
 * `SENTBEFORE` filter on the `Date:` header, when it was *sent*. Verified:
 * a message whose `Date:` was 2026-09-19 matched `SENTBEFORE 2026-09-20`
 * while its `INTERNALDATE` was later. "Emails from last week" means *sent*
 * last week, so the tool's `sent_since`/`sent_before` map here — and the
 * other pair is deliberately not exposed, because two date filters whose
 * difference is invisible in the tool signature is a bug waiting to happen.
 *
 * **Trap two: which encoding.** Every free-text value goes through
 * {@see SearchValue} (finding 3).
 *
 * **Trap three: no `%` wildcards.** The obvious way to make a "substring"
 * search is `%term%`, and it does not work: inside a quoted string `%` is a
 * literal, so the server looks for a subject containing a percent sign.
 * Dovecot substring-matches `SUBJECT`/`FROM` natively (verified), so the
 * value goes out bare.
 */
final readonly class MessageFilter
{
    public function __construct(
        public ?bool $unreadOnly = null,
        public ?string $tagged = null,
        public ?string $untagged = null,
        public ?string $sentSince = null,
        public ?string $sentBefore = null,
        public ?string $from = null,
        public ?string $subject = null,
    ) {
    }

    /**
     * Apply these filters.
     *
     * Accepts either a live message query or a bare `ImapQueryBuilder`:
     * both forward `where()` to the same builder, and the test suite asserts
     * on `toImap()` — the *command* — because the two failures this guards
     * against (finding 3's encoding and the `%` wildcard trap) both produce
     * an empty result set with no error, so a result-count assertion would
     * pass while the bug was live.
     */
    public function apply(MessageQueryInterface|ImapQueryBuilder $query): MessageQueryInterface|ImapQueryBuilder
    {
        if (true === $this->unreadOnly) {
            $query->where(ImapSearchKey::Unseen);
        }

        if (null !== $this->tagged && '' !== $this->tagged) {
            $query->where(ImapSearchKey::Keyword, SearchValue::quote($this->tagged));
        }

        if (null !== $this->untagged && '' !== $this->untagged) {
            $query->where(ImapSearchKey::Unkeyword, SearchValue::quote($this->untagged));
        }

        // SENTBEFORE is exclusive of the date given, SINCE-inclusive is not
        // what we want either: IMAP compares whole days, so `SENTBEFORE` on
        // the day *after* the range's last day is the inclusive behaviour the
        // tool advertises.
        if (null !== $this->sentSince && '' !== $this->sentSince) {
            $query->where(ImapSearchKey::SentSince, self::date($this->sentSince));
        }

        if (null !== $this->sentBefore && '' !== $this->sentBefore) {
            $query->where(ImapSearchKey::SentBefore, self::date($this->sentBefore));
        }

        if (null !== $this->from && '' !== $this->from) {
            $query->where(ImapSearchKey::From, SearchValue::quote($this->from));
        }

        if (null !== $this->subject && '' !== $this->subject) {
            $query->where(ImapSearchKey::Subject, SearchValue::quote($this->subject));
        }

        return $query;
    }

    /**
     * Whether these filters need a search at all.
     *
     * A bare `ALL` still costs a round trip, and the caller's folder choice
     * already bounds the result.
     */
    public function isUnfiltered(): bool
    {
        return null === $this->unreadOnly
            && null === $this->tagged
            && null === $this->untagged
            && null === $this->sentSince
            && null === $this->sentBefore
            && null === $this->from
            && null === $this->subject;
    }

    /**
     * A date as IMAP wants it: `d-Mon-yyyy`, unquoted.
     *
     * The tool accepts `YYYY-MM-DD` (matching every other date parameter in
     * this repo); IMAP's own grammar is `date = date-text`, e.g. `1-Feb-1994`.
     */
    private static function date(string $isoDate): RawQueryValue
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate);

        // The tool validates the format, so a failure here is a programming
        // error rather than user input; fall back to the raw value so the
        // server reports it rather than us inventing a date.
        if (false === $parsed) {
            return new RawQueryValue($isoDate);
        }

        return new RawQueryValue($parsed->format('j-M-Y'));
    }
}
