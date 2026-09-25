<?php

declare(strict_types=1);

namespace App\Tests\Unit\Email;

use App\Email\TagName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tag guard — the reason `tag_email` is not a second path to `\Deleted`.
 *
 * **Finding 23 is the whole story here.** The library's `Message::flag()`
 * performs no validation: `flag('\\Deleted', '+')` was accepted against the
 * reference server and `isDeleted()` became `true`. The same call that sets
 * an ordinary keyword sets a *system* flag.
 *
 * Without this guard, `tag_email` would be reachable with a tag of
 * `\Deleted` *without* `IMAP_MOVE_SOURCE_FOLDERS` — and "there is no delete"
 * would become a promise about our own code rather than a property of the
 * tool surface. These tests are what keeps it the latter.
 *
 * @internal
 *
 * @covers \App\Email\TagName
 */
final class TagNameTest extends TestCase
{
    /**
     * The system flags, each of which must be refused.
     *
     * Asserted one by one rather than as a single "starts with backslash"
     * case, because the interesting failure would be a guard that rejected
     * *some* of them — `\Deleted` is the dangerous one, `\Recent` is
     * server-managed, and a partial guard is not a guard.
     */
    /**
     * @return iterable<string, array{string}>
     */
    public static function systemFlags(): iterable
    {
        yield '\\Deleted — the one that matters most' => ['\\Deleted'];
        yield '\\Seen' => ['\\Seen'];
        yield '\\Flagged' => ['\\Flagged'];
        yield '\\Answered' => ['\\Answered'];
        yield '\\Draft' => ['\\Draft'];
        yield '\\Recent' => ['\\Recent'];
    }

    #[DataProvider('systemFlags')]
    public function test_a_system_flag_is_refused(string $flag): void
    {
        $this->expectException(InvalidArgumentException::class);
        // The message must name the mechanism, not just say "invalid".
        $this->expectExceptionMessageMatches('/system flag/');

        TagName::assertValid($flag);
    }

    public function test_a_bare_backslash_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TagName::assertValid('\\');
    }

    public function test_an_ordinary_tag_is_accepted_and_returned_trimmed(): void
    {
        self::assertSame('AiRead', TagName::assertValid('AiRead'));
        self::assertSame('AiRead', TagName::assertValid('  AiRead  '));
    }

    public function test_the_documented_character_set_is_accepted(): void
    {
        foreach (['AiRead', 'needs-reply', 'auto.filed', 'ns_1', 'A1', 'Work2026'] as $tag) {
            self::assertSame($tag, TagName::assertValid($tag));
        }
    }

    public function test_a_space_is_refused(): void
    {
        // Would need quoting on the wire, and a keyword is not meant to be
        // prose.
        $this->expectException(InvalidArgumentException::class);

        TagName::assertValid('needs reply');
    }

    public function test_characters_outside_the_set_are_refused(): void
    {
        // Every one of these is a character that would otherwise reach a
        // STORE command line.
        foreach (['tag]', 'tag[', 'tag(', 'tag)', 'tag{', 'tag}', 'tag*', 'tag%', 'tag"', "tag'", 'tag\\', 'tag,', 'tag;', 'tag+', 'tag/'] as $tag) {
            self::assertFalse(
                TagName::isValid($tag),
                \sprintf('"%s" must not be usable as a tag', $tag),
            );
        }
    }

    public function test_an_interior_control_character_is_refused(): void
    {
        // A CRLF *inside* a keyword is how a command gets split — the same
        // reason SearchValue strips them. This is not a tag with whitespace
        // around it; it is two things.
        self::assertFalse(TagName::isValid("tag\r\nX"));
        self::assertFalse(TagName::isValid("ta\x00g"));
        self::assertFalse(TagName::isValid("tag\x1Ftag"));
    }

    public function test_surrounding_whitespace_is_trimmed_away(): void
    {
        // The one normalization: a tag arrives from a model, and
        // `"  AiRead\n"` means AiRead. The returned value is what the caller
        // must use, and it is always clean.
        self::assertSame('AiRead', TagName::assertValid("  AiRead\n"));
        self::assertSame('AiRead', TagName::assertValid("\tAiRead "));
        self::assertSame('AiRead', TagName::assertValid("AiRead\x00"));
    }

    public function test_the_returned_tag_never_contains_a_control_character(): void
    {
        // The safety property the grammar exists to enforce: whatever comes
        // back out of assertValid() cannot inject anything into a STORE
        // command line, because it matched the grammar exactly.
        $inputs = [
            'AiRead',
            "  AiRead\n",
            "\tAiRead ",
            "AiRead\x00",
            "AiRead\r\n",
            'needs-reply',
        ];

        foreach ($inputs as $input) {
            $out = TagName::assertValid($input);

            self::assertSame(
                0,
                preg_match('/[\x00-\x1F\x7F]/', $out),
                \sprintf('assertValid(%s) returned a control character', json_encode($input)),
            );
            self::assertSame($out, TagName::assertValid($out), 'the result must be a fixed point');
        }
    }

    public function test_a_non_ascii_tag_is_refused(): void
    {
        // Not a limitation to work around: IMAP keywords are not required to
        // carry 8-bit data, and the agent invents its own tags, so there is
        // nothing to gain by allowing it.
        self::assertFalse(TagName::isValid('café'));
        self::assertFalse(TagName::isValid('tag-ünicode'));
    }

    public function test_an_empty_tag_is_refused_with_a_sentence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not be empty/');

        TagName::assertValid('   ');
    }

    public function test_the_length_limit_is_enforced_at_the_boundary(): void
    {
        // 64 is allowed, 65 is not — asserted on both sides so an off-by-one
        // is a failure rather than a silent widening.
        self::assertSame(64, \strlen(TagName::assertValid(str_repeat('a', 64))));
        self::assertFalse(TagName::isValid(str_repeat('a', 65)));
    }

    public function test_the_length_error_reports_the_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is 65/');

        TagName::assertValid(str_repeat('a', 65));
    }

    public function test_is_valid_does_not_throw_for_any_of_the_refusals(): void
    {
        // The non-throwing form is used where a filter is wanted, so it must
        // not leak an exception on any input this class refuses.
        foreach (['', '\\Deleted', 'a b', 'a]b', 'café', str_repeat('a', 65)] as $tag) {
            self::assertFalse(TagName::isValid($tag));
        }

        self::assertTrue(TagName::isValid('Fine'));
    }
}
