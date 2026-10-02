<?php

declare(strict_types=1);

namespace App\Tests\Unit\PennyTrack;

use App\PennyTrack\Domain\Vocabulary;
use App\PennyTrack\Domain\VocabularyMiss;
use PHPUnit\Framework\TestCase;

/**
 * The safeguard that keeps a ledger's vocabulary from drifting.
 *
 * This is the piece that decides what may be written, so the tests are written
 * as the two halved promises the class makes: a near miss is corrected, and a
 * genuine miss is refused. The interesting cases are the ones on the boundary
 * between those two — a value that shares most of its letters with a stored one
 * but is not the same word, which must be refused rather than folded.
 *
 * @internal
 *
 * @covers \App\PennyTrack\Domain\Vocabulary
 * @covers \App\PennyTrack\Domain\VocabularyMiss
 */
final class VocabularyTest extends TestCase
{
    /**
     * @param list<string> $values
     */
    private function vocabulary(array $values = ['Backblaze', 'Software', 'Dining', 'Amazon Web Services']): Vocabulary
    {
        return new Vocabulary($values);
    }

    /**
     * The correction half: these are all the same merchant to a person reading
     * the ledger, so they resolve to the one stored spelling.
     */
    public function test_case_spacing_and_punctuation_are_corrected_to_the_stored_spelling(): void
    {
        $vocabulary = $this->vocabulary();

        foreach (['backblaze', 'BACKBLAZE', '  Backblaze  '] as $asked) {
            self::assertSame(
                'Backblaze',
                $vocabulary->resolve($asked, 'business'),
                \sprintf('"%s" should resolve to the stored spelling', $asked),
            );
        }
    }

    /**
     * And the same correction where the stored spelling has more than one word:
     * the punctuation after `Inc` is not a difference in the *name*, only in how
     * it was typed, so it folds away.
     */
    public function test_punctuation_after_a_word_is_corrected(): void
    {
        $vocabulary = $this->vocabulary(['Backblaze Inc', 'Software']);

        foreach (['backblaze inc', 'Backblaze  Inc.', 'Backblaze, Inc'] as $asked) {
            self::assertSame('Backblaze Inc', $vocabulary->resolve($asked, 'business'));
        }
    }

    public function test_a_correction_is_reported_rather_than_made_silently(): void
    {
        $vocabulary = $this->vocabulary(['Backblaze Inc']);
        $vocabulary->resolve('backblaze inc', 'business');

        self::assertSame(['backblaze inc' => 'Backblaze Inc'], $vocabulary->corrections());
    }

    public function test_an_exact_match_is_not_reported_as_a_correction(): void
    {
        $vocabulary = $this->vocabulary();
        $vocabulary->resolve('Backblaze', 'business');

        self::assertSame([], $vocabulary->corrections());
    }

    /**
     * The refusal half, and the rule that makes the correction safe: folding
     * never drops a word, so a longer name is not the shorter one.
     */
    public function test_a_value_that_only_shares_a_prefix_is_refused_not_folded(): void
    {
        $vocabulary = $this->vocabulary(['Backblaze']);

        $this->expectException(VocabularyMiss::class);
        $this->expectExceptionMessage('No existing business is spelled "Backblaze, Inc."');

        $vocabulary->resolve('Backblaze, Inc.', 'business');
    }

    public function test_a_different_merchant_is_refused(): void
    {
        $vocabulary = $this->vocabulary();

        $this->expectException(VocabularyMiss::class);

        $vocabulary->resolve('Acme Consulting', 'business');
    }

    public function test_a_refusal_names_the_nearest_existing_values(): void
    {
        $vocabulary = $this->vocabulary();

        try {
            $vocabulary->resolve('Backblaze B2', 'business');
            self::fail('expected a refusal');
        } catch (VocabularyMiss $e) {
            // "Backblaze" contains the input, so containment puts it first even
            // though the input is the longer string.
            self::assertContains('Backblaze', $e->suggestions);
            self::assertSame('business', $e->field);
            self::assertSame('Backblaze B2', $e->value);
        }
    }

    public function test_a_typo_is_refused_with_an_edit_distance_suggestion(): void
    {
        $vocabulary = $this->vocabulary();

        try {
            $vocabulary->resolve('Softwre', 'category');
            self::fail('expected a refusal');
        } catch (VocabularyMiss $e) {
            self::assertSame('Software', $e->suggestions[0]);
        }
    }

    public function test_suggestions_are_bounded(): void
    {
        $vocabulary = new Vocabulary(array_map(static fn (int $i): string => 'Merchant '.$i, range(1, 50)));

        self::assertCount(5, $vocabulary->suggest('Merchant 7'));
    }

    /**
     * A value the ledger genuinely cannot match still has to produce a usable
     * sentence, including when there is nothing at all to suggest.
     */
    public function test_a_refusal_with_no_candidates_still_explains_itself(): void
    {
        $vocabulary = new Vocabulary(['Dining']);

        try {
            $vocabulary->resolve('Zzz', 'category');
            self::fail('expected a refusal');
        } catch (VocabularyMiss $e) {
            self::assertStringContainsString('No existing category is spelled "Zzz"', $e->getMessage());
            self::assertStringContainsString('not created', $e->getMessage());
        }
    }

    public function test_an_empty_vocabulary_is_reported_as_empty(): void
    {
        self::assertTrue($this->vocabulary([])->isEmpty());
        self::assertFalse($this->vocabulary()->isEmpty());
    }

    /**
     * Two stored spellings can fold together, because penny-track allows it and
     * only ever stores what someone typed. The requirement is not that this is
     * impossible — it is that the answer is *stable*, so the same input does not
     * land in two places on two calls.
     */
    public function test_two_stored_spellings_that_fold_together_resolve_stably(): void
    {
        $vocabulary = $this->vocabulary(['Acme Inc.', 'Acme Inc']);

        $first = $vocabulary->resolve('acme inc', 'business');

        self::assertSame($first, $vocabulary->resolve('acme inc', 'business'));
        self::assertContains($first, ['Acme Inc.', 'Acme Inc']);
    }

    public function test_non_latin_names_fold_to_themselves_rather_than_being_mangled(): void
    {
        $vocabulary = new Vocabulary(['Café Réunion', '東京書店']);

        self::assertSame('Café Réunion', $vocabulary->resolve('café réunion', 'business'));
        self::assertSame('東京書店', $vocabulary->resolve('東京書店', 'business'));
    }

    public function test_whitespace_is_trimmed_before_matching(): void
    {
        $vocabulary = $this->vocabulary();

        self::assertSame('Dining', $vocabulary->resolve("\t Dining \n", 'category'));
    }
}
