<?php

declare(strict_types=1);

namespace App\Tests\Unit\Email;

use App\Email\Imap\MessageFilter;
use App\Email\Imap\SearchValue;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\RawQueryValue;
use PHPUnit\Framework\TestCase;

/**
 * Search values and the query they produce.
 *
 * The two things this file exists to prevent are both *silent* failures:
 *
 * 1. **Finding 3** — the library converts plain query values to modified
 *    UTF-7, a folder-name encoding, and the server then treats the result as
 *    a literal. `Reunión` becomes `Reuni&APM-n`, which matches nothing, with
 *    no error. Verified against Dovecot: the raw UTF-8 form matches, the
 *    mUTF-7 form returns an empty result set.
 * 2. **Percent wildcards** — inside a quoted string `%` is a literal, so
 *    `SUBJECT "%invoice%"` looks for a subject containing percent signs. The
 *    "obvious" way to express a substring search is the way that silently
 *    returns nothing.
 *
 * Both produce the same symptom from a caller's side ("no such email"), so
 * they are asserted on the *generated command*, not just on a result count.
 *
 * @internal
 *
 * @covers \App\Email\Imap\SearchValue
 * @covers \App\Email\Imap\MessageFilter
 */
final class SearchValueTest extends TestCase
{
    public function test_quoting_escapes_backslash_before_quote(): void
    {
        // Backslash first, or the escape for `"` gets doubled.
        self::assertSame('a\\\\b', SearchValue::escape('a\\b'));
        self::assertSame('a\\"b', SearchValue::escape('a"b'));
        self::assertSame('a\\\\\\"b', SearchValue::escape('a\\"b'));
    }

    public function test_quoting_strips_control_characters(): void
    {
        // A stray CRLF is how an IMAP command gets split.
        self::assertSame('ab', SearchValue::escape("a\r\nb"));
        self::assertSame('ab', SearchValue::escape("a\x00b"));
        self::assertSame('ab', SearchValue::escape("a\x7Fb"));
    }

    public function test_quoting_produces_a_quoted_raw_value(): void
    {
        $value = SearchValue::quote('Reunión');

        self::assertInstanceOf(RawQueryValue::class, $value);
        self::assertSame('"Reunión"', $value->value);
    }

    public function test_a_non_ascii_subject_is_sent_as_utf8_not_modified_utf7(): void
    {
        $query = $this->buildQuery(new MessageFilter(subject: 'Reunión'));

        $command = $query->toImap();

        // The bug: the library's own conversion would produce `Reuni&APM-n`,
        // which the server matches literally, returning nothing and no error.
        self::assertStringNotContainsString('&APM-', $command);
        self::assertStringContainsString('"Reunión"', $command);
    }

    public function test_a_non_ascii_sender_is_sent_as_utf8(): void
    {
        $command = $this->buildQuery(new MessageFilter(from: 'josé'))->toImap();

        self::assertStringNotContainsString('&AOk-', $command);
        self::assertStringContainsString('"josé"', $command);
    }

    public function test_substring_terms_are_not_wrapped_in_percent_wildcards(): void
    {
        // Dovecot substring-matches SUBJECT/FROM natively; wrapping in `%`
        // makes the quoted value a literal that matches nothing.
        $command = $this->buildQuery(new MessageFilter(subject: 'invoice'))->toImap();

        self::assertSame('SUBJECT "invoice"', $command);
    }

    public function test_a_quote_in_a_search_term_cannot_break_out_of_the_string(): void
    {
        $command = $this->buildQuery(new MessageFilter(subject: 'a"b'))->toImap();

        self::assertSame('SUBJECT "a\\"b"', $command);
    }

    public function test_untagged_uses_unkeyword_and_tagged_uses_keyword(): void
    {
        self::assertSame(
            'UNKEYWORD "AiRead"',
            $this->buildQuery(new MessageFilter(untagged: 'AiRead'))->toImap(),
        );
        self::assertSame(
            'KEYWORD "AiRead"',
            $this->buildQuery(new MessageFilter(tagged: 'AiRead'))->toImap(),
        );
    }

    public function test_unread_only_uses_unseen(): void
    {
        self::assertSame('UNSEEN', $this->buildQuery(new MessageFilter(unreadOnly: true))->toImap());
    }

    public function test_dates_use_the_imap_date_format(): void
    {
        // IMAP wants `d-Mon-yyyy`; the tool speaks `YYYY-MM-DD` like every
        // other date parameter in this repo.
        self::assertSame(
            'SENTSINCE 21-Sep-2026',
            $this->buildQuery(new MessageFilter(sentSince: '2026-09-21'))->toImap(),
        );
        self::assertSame(
            'SENTBEFORE 21-Sep-2026',
            $this->buildQuery(new MessageFilter(sentBefore: '2026-09-21'))->toImap(),
        );
    }

    public function test_sent_dates_are_used_not_internal_dates(): void
    {
        // SINCE/BEFORE filter on INTERNALDATE (when the message landed in
        // this mailbox); SENTSINCE/SENTBEFORE filter on the Date: header.
        // "Emails from last week" means sent last week.
        //
        // Asserted as a whole command rather than a substring: `SENTSINCE`
        // itself contains `SINCE`, so a `assertStringNotContainsString`
        // would fail on the correct output.
        self::assertSame(
            'SENTSINCE 21-Sep-2026',
            $this->buildQuery(new MessageFilter(sentSince: '2026-09-21'))->toImap(),
        );
        self::assertSame(
            'SENTBEFORE 21-Sep-2026',
            $this->buildQuery(new MessageFilter(sentBefore: '2026-09-21'))->toImap(),
        );
    }

    public function test_filters_combine(): void
    {
        $command = $this->buildQuery(new MessageFilter(
            unreadOnly: true,
            tagged: 'Work',
            subject: 'invoice',
        ))->toImap();

        self::assertStringContainsString('UNSEEN', $command);
        self::assertStringContainsString('KEYWORD "Work"', $command);
        self::assertStringContainsString('SUBJECT "invoice"', $command);
    }

    public function test_an_empty_filter_is_recognised_as_unfiltered(): void
    {
        // A bare `ALL` still costs a round trip, so the caller can skip it.
        self::assertTrue((new MessageFilter())->isUnfiltered());
        self::assertFalse((new MessageFilter(unreadOnly: true))->isUnfiltered());
        self::assertFalse((new MessageFilter(subject: 'x'))->isUnfiltered());
    }

    private function buildQuery(MessageFilter $filter): ImapQueryBuilder
    {
        $query = new ImapQueryBuilder();
        $filter->apply($query);

        return $query;
    }
}
