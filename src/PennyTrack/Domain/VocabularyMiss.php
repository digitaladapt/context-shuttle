<?php

declare(strict_types=1);

namespace App\PennyTrack\Domain;

/**
 * A transaction was refused because it did not name an existing value.
 *
 * Carries the field, the value as it was given, and the nearest known values,
 * so the message and a test's assertion can both be built from the same facts
 * rather than from a string that has to be parsed.
 */
final class VocabularyMiss extends TransactionRefused
{
    /**
     * @param list<string> $suggestions nearest known values, best first
     */
    public function __construct(
        public readonly string $field,
        public readonly string $value,
        public readonly array $suggestions,
    ) {
        parent::__construct(self::describe($field, $value, $suggestions));
    }

    /**
     * @param list<string> $suggestions
     */
    private static function describe(string $field, string $value, array $suggestions): string
    {
        $hint = [] === $suggestions
            ? ''
            : \sprintf(' The closest existing %s: %s.', $field, implode(', ', array_map(
                static fn (string $candidate): string => \sprintf('"%s"', $candidate),
                $suggestions,
            )));

        return \sprintf(
            'No existing %s is spelled "%s", so this transaction was not created. A %s must already exist in penny-track before it can be used — this tool will not invent one, because a new spelling splits a category or a merchant in two and there is no way to tell a new name from a typo. Log one receipt with this %s by hand in penny-track, then it will resolve.%s',
            $field,
            $value,
            $field,
            $field,
            $hint,
        );
    }
}
