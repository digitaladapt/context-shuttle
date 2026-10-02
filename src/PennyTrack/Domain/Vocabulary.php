<?php

declare(strict_types=1);

namespace App\PennyTrack\Domain;

/**
 * The set of values going on the report.
 *
 * penny-track stores `business` and `category` as free text, so it has no idea
 * a category is new — it accepts any string, and the fact that "Software" and
 * "software" are now two categories is only visible on the dashboard, weeks
 * later, as a chart that does not add up. That is the problem this class
 * exists to prevent, and it is a deliberate departure from how this project
 * treats upstream data elsewhere: every other tool here passes values through
 * untouched ("re-deciding them here would be a second implementation that can
 * disagree with the first"), but a *write* is exactly where the ledger's lack
 * of an opinion has to be supplied by someone.
 *
 * The rule has two halves, and the split between them is the whole point:
 *
 * - **A near miss is corrected.** A value that differs from a stored one only
 *   in case, spacing or punctuation resolves to the stored spelling, and the
 *   stored spelling is what gets written. `backblaze` becomes `Backblaze`, and
 *   `Backblaze Inc` and `Backblaze  Inc.` are one merchant. This is the part
 *   that makes the tool pleasant to call: a model reading a receipt email has
 *   no way to know the capitalisation the operator happens to use.
 * - **A miss is refused.** A value that matches nothing is refused, with the
 *   nearest known values named so the caller can retry with a real one. This
 *   is the part that makes the correction safe: a *different* merchant can
 *   never be folded into an existing one, because folding only ever removes
 *   case, spacing and punctuation — never letters or words. So a receipt
 *   saying `Backblaze, Inc.` does **not** resolve to a stored `Backblaze`:
 *   dropping `Inc.` is dropping a word, and a rule that dropped words would
 *   also fold `Acme Consulting` into `Acme`, which are two different
 *   businesses. It is refused instead — and because the refusal names
 *   `Backblaze` as the closest match, recovering costs one more call with a
 *   value the caller has now been handed.
 *
 * Together those mean a caller cannot widen the vocabulary, only conform to
 * it — which is the property the duplicate and consistency checks downstream
 * depend on, since they compare against values that are now guaranteed to be
 * exactly what the ledger already holds.
 *
 * A correction is never silent: this class records what it rewrote
 * ({@see corrections()}), and the tool that used it puts that in the result, so
 * a wrong correction is visible to the caller rather than only in the ledger.
 */
final class Vocabulary
{
    /**
     * Folded form => the stored spelling it resolves to.
     *
     * @var array<string, string>
     */
    private array $byFolded;

    /**
     * The values this instance rewrote, keyed by the exact string it was
     * asked about: `asked-for spelling => the spelling written instead`.
     *
     * Recorded per instance rather than returned from `resolve()` because
     * resolve() has one obvious return value and the caller needs both: the
     * value to write, and whether writing it changed anything the caller
     * should be told about.
     *
     * @var array<string, string>
     */
    private array $corrections = [];

    /**
     * @param list<string> $values the distinct values already in the ledger,
     *                             in penny-track's own (alphabetical) order
     */
    public function __construct(
        private array $values,
    ) {
        $byFolded = [];

        foreach ($values as $value) {
            $folded = self::fold($value);

            // Two stored spellings can fold together ("Acme Inc." and
            // "Acme Inc" are distinct rows to penny-track but one value to a
            // case-insensitive matcher). Either answer conforms to the
            // existing data, so the rule is only that the answer must be
            // *stable*: first in penny-track's order wins, and the same input
            // always resolves the same way. Refusing instead would make a
            // value the ledger demonstrably holds impossible to log.
            $byFolded[$folded] ??= $value;
        }

        $this->byFolded = $byFolded;
    }

    /**
     * Whether the ledger holds no values for this field yet.
     *
     * This is not the same as "nothing matched", and the difference matters
     * once: a brand-new penny-track instance has no categories at all, so
     * every value is unmatched and a strict rule would make the very first
     * transaction unloggable through this tool. There is nothing to conform
     * to, and nothing to correct toward, so the check is skipped for that
     * field — loudly, by the caller, rather than silently here.
     */
    public function isEmpty(): bool
    {
        return [] === $this->values;
    }

    /**
     * Every known value, as penny-track spells them.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Resolve a caller's spelling to the ledger's.
     *
     * @param string $field the field name, used only to build a useful refusal
     *
     * @throws VocabularyMiss when nothing in the ledger folds to this value
     */
    public function resolve(string $value, string $field): string
    {
        $value = trim($value);
        $folded = self::fold($value);

        $canonical = $this->byFolded[$folded] ?? null;

        if (null === $canonical) {
            throw new VocabularyMiss($field, $value, $this->suggest($value));
        }

        if ($canonical !== $value) {
            $this->corrections[$value] = $canonical;
        }

        return $canonical;
    }

    /**
     * The corrections this instance made, `asked-for => written`.
     *
     * Empty when every value resolved to itself — which is the common case,
     * and the reason the tool can report "nothing was corrected" without
     * having to guess.
     *
     * @return array<string, string>
     */
    public function corrections(): array
    {
        return $this->corrections;
    }

    /**
     * The nearest known values, best first.
     *
     * Only ever used to write a refusal, so it favours suggestions a caller can
     * recognise over precision: a shared word ("Backblaze" for "Backblaze,
     * Inc") is a better lead than a small edit distance between two unrelated
     * names, so containment is tried before distance.
     *
     * @return list<string>
     */
    public function suggest(string $value, int $limit = 5): array
    {
        $needle = self::fold($value);

        if ('' === $needle) {
            return [];
        }

        /** @var list<array{value: string, score: int}> $scored */
        $scored = [];

        foreach ($this->values as $candidate) {
            $haystack = self::fold($candidate);

            // Containment either way: the input is a fragment of a known value
            // ("Backblaze" -> "Backblaze, Inc.") or has one bolted on
            // ("Netflix.com" -> "Netflix").
            if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
                $scored[] = ['value' => $candidate, 'score' => 0];
            } else {
                $scored[] = ['value' => $candidate, 'score' => levenshtein($needle, $haystack) + 1];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $a['score'] <=> $b['score'] ?: strcmp($a['value'], $b['value']));

        return array_map(
            static fn (array $row): string => $row['value'],
            \array_slice($scored, 0, $limit),
        );
    }

    /**
     * The comparison form: case, spacing and punctuation removed, nothing else.
     *
     * Deleting *only* those three is what makes the correction safe. Anything
     * that removed or reordered letters would let two different names collide,
     * and the tool would then be inventing a merchant rather than recognising
     * one.
     */
    private static function fold(string $value): string
    {
        $value = trim($value);

        if (!mb_check_encoding($value, 'UTF-8')) {
            // Not text we can reason about. Fall back to what is certainly
            // safe — ASCII alphanumerics — rather than guessing, which keeps
            // the worst case a refusal instead of a wrong match.
            return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
        }

        // Letters and digits survive (in any script, so a non-Latin merchant
        // name still folds to itself); everything else becomes a separator, so
        // "Backblaze, Inc." and "Backblaze Inc" agree. Runs collapse, so
        // "Acme  Corp" agrees with "Acme Corp".
        $spaced = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value, 'UTF-8'));

        return trim((string) preg_replace('/\s+/', ' ', $spaced));
    }
}
