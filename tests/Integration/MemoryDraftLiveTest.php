<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\MemoryDraft\MemoryDraftEndpoint;
use App\Tool\MemoryDraft\MemoryDraftTool;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\NativeHttpClient;
use Throwable;

/**
 * The memory tools against a **real** memory-draft instance.
 *
 * Skipped unless `MEMORY_DRAFT_LIVE_URL` is set, so CI stays hermetic and no
 * instance is needed to run the suite. The unit tests script every response,
 * which is what makes them fast and precise — but they cannot catch a
 * service whose payloads, key resolution or trimming differ from the
 * fixtures. This class is the counterweight.
 *
 * It writes under a single test key derived from the process, and deletes it
 * in tearDown, so running it against a real store leaves that store as it
 * was — including after a failed assertion. `forget` is the cleanup, which
 * also exercises the one destructive operation the tool has.
 *
 * Run it against a live instance with:
 *
 *     MEMORY_DRAFT_LIVE_URL=https://memory.example.com \
 *     php bin/phpunit --group live
 *
 * @internal
 *
 * @group live
 *
 * @covers \App\Tool\MemoryDraft\MemoryDraftTool
 */
#[Group('live')]
final class MemoryDraftLiveTest extends TestCase
{
    private const KEY = 'context-shuttle:live-test';

    private ?MemoryDraftTool $tool = null;

    #[Override]
    protected function tearDown(): void
    {
        // Best-effort cleanup: a failed assertion must not leave an entry
        // behind in a store someone else is using.
        if (null !== $this->tool && null !== $this->url()) {
            try {
                $this->tool->forget(self::KEY);
            } catch (Throwable) {
                // Not there (a test that never wrote) or unreachable; either
                // way there is nothing useful to do in teardown.
            }
        }

        $this->tool = null;

        parent::tearDown();
    }

    private function url(): ?string
    {
        $url = getenv('MEMORY_DRAFT_LIVE_URL');

        return false === $url || '' === $url ? null : $url;
    }

    private function tool(): MemoryDraftTool
    {
        $url = $this->url();

        if (null === $url) {
            self::markTestSkipped('Set MEMORY_DRAFT_LIVE_URL to run the live memory-draft tests.');
        }

        return $this->tool ??= new MemoryDraftTool(
            new NativeHttpClient(),
            new MemoryDraftEndpoint($url),
        );
    }

    public function test_a_write_then_read_round_trips_through_the_real_service(): void
    {
        $written = $this->tool()->remember(self::KEY, 'The live test wrote this sentence.');

        self::assertSame(self::KEY, $written['results'][0]['key']);
        self::assertFalse($written['results'][0]['empty']);
        self::assertGreaterThanOrEqual(1, $written['results'][0]['added']);

        $read = $this->tool()->recall([self::KEY]);

        self::assertCount(1, $read['hits'], 'the key just written must be found');
        self::assertCount(0, $read['misses']);
        self::assertSame(self::KEY, $read['hits'][0]['key']);
        self::assertSame('exact', $read['hits'][0]['match']);
        self::assertSame('The live test wrote this sentence.', $read['hits'][0]['entries'][0]['text']);
    }

    /**
     * The store's own reason for existing: a miss is a 200 with suggestions,
     * and a variant spelling resolves in the same round trip.
     */
    public function test_a_miss_is_an_answer_and_variants_resolve(): void
    {
        $this->tool()->remember(self::KEY, 'Resolution works.');

        // A variant spelling of the same key, plus a key that does not exist.
        $result = $this->tool()->recall(['ContextShuttle:LiveTest', 'definitely-not-a-key-in-this-store']);

        self::assertCount(1, $result['hits'], 'a variant spelling must resolve to the stored key');
        self::assertSame(self::KEY, $result['hits'][0]['key']);
        self::assertSame('ContextShuttle:LiveTest', $result['hits'][0]['resolved_from']);

        self::assertCount(1, $result['misses']);
        self::assertSame('definitely-not-a-key-in-this-store', $result['misses'][0]['key']);
        self::assertArrayHasKey('suggestions', $result['misses'][0]);
    }

    public function test_a_stale_revision_is_kept_and_flagged_rather_than_rejected(): void
    {
        $first = $this->tool()->remember(self::KEY, 'First write.');
        $revision = $first['results'][0]['revision'];

        // Move the key on, then write against the revision read earlier.
        $this->tool()->remember(self::KEY, 'A newer write.');

        $stale = $this->tool()->remember(self::KEY, 'Written against a stale revision.', revision: $revision);

        // Deliberate divergence from a last-write-wins store: the write
        // lands, flagged, rather than being discarded.
        self::assertSame('backfill', $stale['results'][0]['intent']);
    }

    public function test_keys_lists_the_written_key_with_its_revision(): void
    {
        $this->tool()->remember(self::KEY, 'Listed.');

        $result = $this->tool()->keys(self::KEY);

        self::assertSame(1, $result['count']);
        self::assertSame(self::KEY, $result['keys'][0]['key']);
        self::assertArrayHasKey('revision', $result['keys'][0]);
    }

    /**
     * The recency read, against the real service.
     *
     * This is the one assertion no amount of mocked HTTP can make: that the
     * shape the tool *sends* (`{"latest": true}`) is one memory-draft actually
     * accepts, and that the `latest` block it answers with carries the note the
     * tool's description promises. The two repos are versioned separately, so
     * this is where a drift between them would show up first.
     */
    public function test_the_recency_read_works_against_the_real_service(): void
    {
        $this->tool()->remember(self::KEY, 'The live test wrote this sentence.');

        // No keys at all — the shape a model uses to open a session.
        $result = $this->tool()->recall([], latest: true);

        self::assertNotEmpty($result['hits'], 'a store with a key written a moment ago must answer');
        self::assertContains(self::KEY, array_column($result['hits'], 'key'), 'the key just written is among the recent ones');

        // Nothing was named, so nothing matched: claiming a match kind would
        // tell the caller it had asked for this key.
        self::assertSame('recent', $result['hits'][0]['match']);
        self::assertArrayNotHasKey('resolved_from', $result['hits'][0]);

        // The answer states what it did, in words, so a default count cannot be
        // mistaken for a deliberate recall.
        self::assertArrayHasKey('latest', $result);
        self::assertStringContainsString('most recently written', $result['latest']['note']);
        self::assertArrayHasKey('count', $result['latest']);
        self::assertArrayHasKey('last_written', $result['hits'][0]);
    }

    public function test_keys_and_latest_compose_against_the_real_service(): void
    {
        $this->tool()->remember(self::KEY, 'Named and recent.');

        $result = $this->tool()->recall([self::KEY], latest: true);

        // The named hit leads, and the key is not repeated by the expansion.
        self::assertSame(self::KEY, $result['hits'][0]['key']);
        self::assertSame('exact', $result['hits'][0]['match']);

        $occurrences = array_filter($result['hits'], static fn (array $h): bool => self::KEY === $h['key']);
        self::assertCount(1, $occurrences, 'a key answered by name must not be added back by recency');
    }

    public function test_stats_reports_the_trimming_budgets(): void
    {
        $stats = $this->tool()->stats();
        self::assertArrayHasKey('keys', $stats);
        self::assertArrayHasKey('caps', $stats);
        self::assertArrayHasKey('hot_per_key', $stats['caps']);
        self::assertArrayHasKey('cold_per_key', $stats['caps']);
    }

    public function test_replace_retires_the_current_sentences_to_cold_rather_than_deleting_them(): void
    {
        $this->tool()->remember(self::KEY, 'The old fact.');
        $this->tool()->remember(self::KEY, 'The new fact.', 'replace');

        $hot = $this->tool()->recall([self::KEY]);
        self::assertSame('The new fact.', $hot['hits'][0]['entries'][0]['text']);

        // The retired sentence is not gone: it moved to cold and is
        // retrievable on request. "Nothing is lost silently" is the whole
        // promise, and this is where it is observable.
        $cold = $this->tool()->recall([self::KEY], include_cold: true);
        $texts = array_column($cold['hits'][0]['entries'], 'text');

        self::assertContains('The old fact.', $texts);
        self::assertContains('cold', array_column($cold['hits'][0]['entries'], 'tier'));
    }

    public function test_forget_deletes_the_key_and_a_second_attempt_reports_nothing_matched(): void
    {
        $this->tool()->remember(self::KEY, 'About to go.');

        $deleted = $this->tool()->forget(self::KEY);

        self::assertSame(self::KEY, $deleted['key']);
        self::assertGreaterThanOrEqual(1, $deleted['deleted']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no key matching');

        $this->tool()->forget(self::KEY);
    }

    /**
     * A sentence is demoted by its text, and the sentences beside it are
     * untouched — which is the entire reason this tool exists. `replace` would
     * have retired all three.
     */
    public function test_revise_demotes_one_sentence_and_leaves_the_others_hot(): void
    {
        $this->tool()->remember(self::KEY, 'Rollback is one command. Rollback is verified in staging. Deploys are blue-green.');

        $result = $this->tool()->revise(self::KEY, 'Rollback is verified in staging.');

        $report = $result['results'][0];
        self::assertSame(self::KEY, $report['key']);
        self::assertSame('demote', $report['disposition']);
        self::assertSame(1, $report['changed']);
        self::assertSame(1, $report['matched']);
        self::assertCount(1, $report['entries']);

        $hot = $this->tool()->recall([self::KEY]);
        $texts = array_column($hot['hits'][0]['entries'], 'text');

        self::assertNotContains('Rollback is verified in staging.', $texts, 'a demoted sentence stops being what recall shows');
        self::assertContains('Rollback is one command.', $texts);
        self::assertContains('Deploys are blue-green.', $texts);

        // Demotion is not deletion: the sentence is still there, one tier down.
        $cold = $this->tool()->recall([self::KEY], include_cold: true);
        self::assertContains('Rollback is verified in staging.', array_column($cold['hits'][0]['entries'], 'text'));
    }

    /**
     * Re-stating a demoted sentence promotes it back, which is why `demote`
     * needs no undo command: the write path already is one.
     */
    public function test_a_demoted_sentence_returns_to_hot_when_restated(): void
    {
        $this->tool()->remember(self::KEY, 'Kept. To be demoted.');
        $this->tool()->revise(self::KEY, 'To be demoted.');

        $this->tool()->remember(self::KEY, 'To be demoted.');

        $hot = $this->tool()->recall([self::KEY]);
        $entries = $hot['hits'][0]['entries'];
        $restated = array_values(array_filter($entries, static fn (array $e): bool => 'To be demoted.' === $e['text']));

        self::assertCount(1, $restated, 're-stating must not leave a duplicate row behind');
        self::assertSame('hot', $restated[0]['tier']);
    }

    /**
     * `dry_run` has to match exactly what a real call would: it is the only
     * thing standing between a `glob` pattern and an irreversible purge, so a
     * rehearsal that under-reported would defeat its purpose.
     */
    public function test_dry_run_reports_the_match_without_changing_anything(): void
    {
        $this->tool()->remember(self::KEY, 'Alpha fact. Beta fact.');

        $dry = $this->tool()->revise(self::KEY, '*fact*', match: 'glob', dry_run: true);

        $report = $dry['results'][0];
        self::assertSame(0, $report['changed']);
        self::assertSame(2, $report['matched'], 'the rehearsal must report both sentences a real glob call would take');
        self::assertCount(2, $report['entries'], 'and list them, so the pattern can be judged before it is trusted');

        $hot = $this->tool()->recall([self::KEY]);
        self::assertCount(2, $hot['hits'][0]['entries'], 'nothing may move during a dry run');
    }

    /**
     * The safety rule that only exists because there are no sentence ids: an
     * ambiguous purge is refused, so a too-broad pattern cannot destroy facts
     * the caller never saw. The service answers 422 and the tool surfaces it.
     */
    public function test_a_multi_sentence_purge_is_refused_rather_than_guessed_at(): void
    {
        $this->tool()->remember(self::KEY, 'First sentence. Second sentence.');

        try {
            $this->tool()->revise(self::KEY, '*sentence*', disposition: 'purge', match: 'glob');
            self::fail('a purge matching two sentences must be refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('more than one', $e->getMessage());
        }

        $hot = $this->tool()->recall([self::KEY]);
        self::assertCount(2, $hot['hits'][0]['entries'], 'a refused purge must change nothing');
    }

    /**
     * A purge that matches one sentence is allowed and is the real thing: the
     * row is gone from both tiers, not demoted.
     */
    public function test_a_single_sentence_purge_destroys_the_row(): void
    {
        $this->tool()->remember(self::KEY, 'This one goes. This one stays.');

        $result = $this->tool()->revise(self::KEY, 'This one goes.', disposition: 'purge');
        self::assertSame(1, $result['results'][0]['changed']);

        $cold = $this->tool()->recall([self::KEY], include_cold: true);
        $texts = array_column($cold['hits'][0]['entries'], 'text');

        self::assertNotContains('This one goes.', $texts, 'a purged sentence is in neither tier');
        self::assertContains('This one stays.', $texts);
    }

    /**
     * `remaining` answers "did I just empty the key?" without a second read,
     * and the revision moves so a caller holding the old one is flagged rather
     * than silently overwriting.
     */
    public function test_revise_reports_remaining_counts_and_bumps_the_revision(): void
    {
        $before = $this->tool()->remember(self::KEY, 'One. Two.');
        $revision = $before['results'][0]['revision'];

        $result = $this->tool()->revise(self::KEY, 'One.');
        $report = $result['results'][0];

        self::assertGreaterThan($revision, $report['revision'], 'a change must bump the revision');
        self::assertSame(1, $report['remaining']['hot']);
        self::assertSame(1, $report['remaining']['cold']);
    }

    /**
     * A pattern that misses in the requested tier but hits in the other one
     * says so, instead of reading as "no such sentence".
     */
    public function test_a_tier_miss_explains_where_the_sentence_actually_is(): void
    {
        $this->tool()->remember(self::KEY, 'Lives in the cold tier.');
        $this->tool()->revise(self::KEY, 'Lives in the cold tier.');

        $result = $this->tool()->revise(self::KEY, 'Lives in the cold tier.');
        $report = $result['results'][0];

        self::assertSame(0, $report['changed']);
        self::assertArrayHasKey('hint', $report);
        self::assertStringContainsString('cold', $report['hint']);
    }
}
