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
}
