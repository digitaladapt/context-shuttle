<?php

declare(strict_types=1);

namespace App\Messages;

/**
 * The one place a conversation name is decided.
 *
 * A conversation is an append-only log keyed by name, and the name is the
 * *address* of that log — anything that cannot be typed twice the same way
 * cannot be an address. So: lowercase, digits, dot, underscore and hyphen,
 * starting with a letter or digit, at most 64 characters. No spaces (a name
 * quoted in a shell or a URL would need escaping), no uppercase (the store
 * is exact-match and `Andrew`/`andrew` would be two logs that are "the same
 * one" to a human and two different ones to a query).
 *
 * The default — `default` — is the conversation a caller that names nothing
 * gets. It is valid by construction, which is why it is spelled here rather
 * than validated: a default that could fail validation would be a boot-time
 * contradiction, not a runtime one.
 */
final readonly class ConversationName
{
    public const string DEFAULT = 'default';

    private const string PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    private function __construct(
        public string $value,
    ) {
    }

    /**
     * Validate a caller-supplied name, or fall back to the default.
     *
     * An empty or whitespace-only name is "the caller named nothing", which
     * is the default conversation — not an error, because every caller that
     * omits the parameter on purpose should land in the same place as one
     * that sent `""`. Anything else that does not match the pattern is
     * refused with a message that states the pattern, so the caller can fix
     * the name rather than guess.
     */
    public static function from(?string $raw): self
    {
        $trimmed = trim((string) $raw);

        if ('' === $trimmed) {
            return new self(self::DEFAULT);
        }

        if (1 !== preg_match(self::PATTERN, $trimmed)) {
            throw new ConversationNameException('A conversation name is lowercase letters, digits, dot, underscore or hyphen, '."starting with a letter or digit, up to 64 characters — got '{$trimmed}'.");
        }

        return new self($trimmed);
    }
}
