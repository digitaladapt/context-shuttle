<?php

declare(strict_types=1);

namespace App\Email;

use InvalidArgumentException;

/**
 * What may be used as a tag — the guard that keeps `tag_email` from being a
 * second, ungated path to `\Deleted`.
 *
 * **Finding 23 is why this exists.** `Message::flag()` does no validation
 * whatsoever: `$message->flag('\\Deleted', '+')` was accepted against the
 * reference server and `isDeleted()` became `true`. The same call that sets
 * an ordinary keyword sets a *system* flag, and the library does not care
 * which it was handed.
 *
 * Without this guard, `tag_email` would be reachable with a tag of
 * `\Deleted` **without** `IMAP_MOVE_SOURCE_FOLDERS`, which is the whole
 * permission split the design exists to enforce — and "there is no delete"
 * would be a promise about our own code rather than a property of the tool
 * surface.
 *
 * So the rule is: a tag is a thing the agent made up, and system flags are
 * mailbox state. Anything starting with `\` is refused, as is `]` and `~`
 * (IMAP's atext excludes them, and a server may reject or misparse a
 * keyword containing them).
 *
 * The grammar is deliberately narrower than RFC 3501's `atom`: letters,
 * digits, `_`, `-`, `.`, up to 64 characters. A tag is invented by us and
 * read back by us, so there is no interoperability cost to being strict —
 * and every character not allowed is one that cannot end up in a `STORE`
 * command line.
 *
 * **The pattern is anchored with `\z`, not `$`.** PCRE's `$` also matches
 * immediately *before* a trailing newline, so `/^[a-z]+$/` accepts
 * `"tag\n"` — which would put a newline inside a `STORE` command, exactly
 * the injection this class exists to prevent. `\z` matches only at the true
 * end of the subject. (Caught by a test, not by review.)
 *
 * **Surrounding whitespace is trimmed, and that is the one normalization
 * here.** A tag arrives from a model, and `"  AiRead\n"` means `AiRead`; the
 * result of `assertValid()` is guaranteed to contain no control character at
 * all, which is the property that matters for the command line. Anything
 * *interior* is refused outright rather than stripped — `"tag\r\nX"` is not
 * a tag with whitespace around it, it is two things.
 */
final class TagName
{
    /**
     * The maximum length, matching the design's contract.
     *
     * Chosen rather than derived: a keyword is stored inline in the mailbox
     * index, and 64 is comfortably inside every server's limit while being
     * far more than a human-readable label needs.
     */
    public const MAX_LENGTH = 64;

    /**
     * Validate one tag, or fail with a sentence naming the problem.
     *
     * Returns the *normalized* tag, and the returned value is what the
     * caller must use: it is guaranteed to match the grammar exactly, which
     * is the property that keeps a control character out of a `STORE`
     * command line.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValid(string $tag): string
    {
        // The only normalization: surrounding whitespace. See the class
        // docblock for why interior control characters are refused rather
        // than stripped.
        $tag = trim($tag);

        if ('' === $tag) {
            throw new InvalidArgumentException('tag must not be empty. A tag is a short label such as "AiRead" or "NeedsReply".');
        }

        // Checked before the pattern so the message can explain *why* this
        // one matters, rather than lumping it in with "invalid character".
        if (str_starts_with($tag, '\\')) {
            throw new InvalidArgumentException(\sprintf('tag must not start with a backslash: "%s" names a system flag (\\Seen, \\Deleted, \\Flagged…), not a tag. Tags are labels this agent invented; system flags are mailbox state that other mail clients read. Use mark_email_read to change \\Seen, or move_email to remove a message — there is no tool that sets \\Deleted.', $tag));
        }

        if (\strlen($tag) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(\sprintf('tag must be at most %d characters; "%s" is %d.', self::MAX_LENGTH, $tag, \strlen($tag)));
        }

        if (1 !== preg_match('/^[A-Za-z0-9._-]+\z/', $tag)) {
            throw new InvalidArgumentException(\sprintf('tag may only contain letters, digits, dot, underscore and hyphen — "%s" has something else. This is deliberately stricter than the protocol requires: a tag is invented and read back by us, so there is no interoperability to preserve.', $tag));
        }

        return $tag;
    }

    /**
     * Whether a tag is safe to use, without the exception.
     *
     * For callers that want to filter rather than fail — the tool itself
     * always wants the message, so it uses {@see self::assertValid()}.
     */
    public static function isValid(string $tag): bool
    {
        try {
            self::assertValid($tag);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
