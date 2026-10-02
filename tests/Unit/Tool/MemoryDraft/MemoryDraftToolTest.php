<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\MemoryDraft;

use App\MemoryDraft\MemoryDraftEndpoint;
use App\Tests\Support\RecordingHttpClient;
use App\Tool\MemoryDraft\MemoryDraftTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the memory-draft tool, using a recorded HTTP client so no
 * network is needed. Exercises the tool the way a caller would: the five
 * operations against realistic payloads, the argument validation that
 * happens before a request is made, and the failure modes an unconfigured or
 * unreachable deployment produces.
 *
 * The assertions deliberately check that the right request went out (method,
 * URL, body/query) as well as that the service's own answer came back
 * untouched — the tool is a transport, and drift in either direction is the
 * bug worth catching.
 *
 * @internal
 *
 * @covers \App\Tool\MemoryDraft\MemoryDraftTool
 */
final class MemoryDraftToolTest extends TestCase
{
    private ?RecordingHttpClient $client = null;

    /**
     * The recorder for this test. Lazily built because PHPUnit constructs a
     * fresh test instance per test method, so there is nothing to reset — and
     * because a property PHPStan cannot see initialised is one it reads as a
     * possible null on every use.
     */
    private function client(): RecordingHttpClient
    {
        return $this->client ??= new RecordingHttpClient();
    }

    private function tool(string $baseUrl = 'https://memory.example.com'): MemoryDraftTool
    {
        return new MemoryDraftTool($this->client(), new MemoryDraftEndpoint($baseUrl));
    }

    /**
     * @return array<string, mixed> the decoded body of the request at `$index`
     */
    private function body(int $index = 0): array
    {
        return $this->client()->jsonBody($index);
    }

    // ── recall ──────────────────────────────────────────────────────────

    public function test_recall_empty_batch_is_an_error_about_asking_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('latest: true');

        $this->tool()->recall([]);
    }

    /**
     * The boolean is forwarded as `true`, never as a count.
     *
     * That is the whole point of the shape: `true` means "the count is the
     * store's to choose", so its default can be retuned in memory-draft without
     * a release here, and the model is never asked to pick a number it has no
     * basis to judge. An integer reaching this point would quietly undo both.
     */
    public function test_recall_latest_is_forwarded_as_the_boolean_not_a_count(): void
    {
        $this->client()->queue(['{"hits":[],"misses":[],"latest":{"note":"No keys given","count":8}}']);

        $this->tool()->recall([], null, null, true);

        self::assertSame(['queries' => [['latest' => true]]], $this->body());
    }

    public function test_recall_no_keys_with_latest_is_a_complete_request(): void
    {
        $this->client()->queue(['{"hits":[{"key":"chat","match":"recent","entries":[]}],"misses":[],"latest":{"note":"No keys given"}}']);

        $result = $this->tool()->recall([], null, null, true);

        // Not an error path: this is the ordinary way to open a session, and the
        // response is passed through whole so the `latest` block — the note
        // saying what was shown — reaches the caller.
        self::assertSame('chat', $result['hits'][0]['key']);
        self::assertArrayHasKey('latest', $result);
    }

    public function test_recall_keys_and_latest_compose_into_one_request(): void
    {
        $this->client()->queue(['{"hits":[],"misses":[],"latest":{}}']);

        $this->tool()->recall(['soul'], null, null, true);

        self::assertSame(
            ['queries' => [['key' => 'soul'], ['latest' => true]]],
            $this->body(),
            'named first, then the recency entry — one round trip, not two calls',
        );
    }

    public function test_recall_latest_false_is_not_forwarded(): void
    {
        // `false` is the caller saying "an ordinary recall". Forwarding it would
        // add an entry that asks for nothing, and the store reads any non-false
        // `latest` as the recency request.
        $this->client()->queue(['{"hits":[],"misses":[]}']);

        $this->tool()->recall(['a'], null, null, false);

        self::assertSame(['queries' => [['key' => 'a']]], $this->body());
    }

    public function test_recall_still_rejects_a_blank_key_when_latest_is_also_set(): void
    {
        // `latest` relaxes the "at least one key" rule, not the "every key must
        // be a real key" one. A blank entry beside a legitimate recency request
        // is still a typo.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->tool()->recall(['   '], null, null, true);
    }

    public function test_recall_posts_the_queries_batch_and_returns_hits_and_misses(): void
    {
        $this->client()->queue(['{"hits":[{"key":"context-shuttle","match":"exact","revision":3,"entries":[{"text":"A gateway."}]}],"misses":[{"key":"nope","suggestions":["note"]}]}']);

        $result = $this->tool()->recall(['context-shuttle', 'nope']);

        self::assertSame('POST', $this->client()->calls[0]['method']);
        self::assertSame('https://memory.example.com/api/recall', $this->client()->calls[0]['url']);
        self::assertSame(
            ['queries' => [['key' => 'context-shuttle'], ['key' => 'nope']]],
            $this->body(),
        );

        // A miss is an answer, not an error — both halves reach the caller.
        self::assertSame('context-shuttle', $result['hits'][0]['key']);
        self::assertSame(['note'], $result['misses'][0]['suggestions']);
    }

    public function test_recall_passes_depth_and_include_cold_per_query(): void
    {
        $this->client()->queue(['{"hits":[],"misses":[]}']);

        $this->tool()->recall(['a', 'b'], 7, true);

        self::assertSame(
            [
                'queries' => [
                    ['key' => 'a', 'depth' => 7, 'includeCold' => true],
                    ['key' => 'b', 'depth' => 7, 'includeCold' => true],
                ],
            ],
            $this->body(),
        );
    }

    public function test_recall_omits_optional_fields_when_not_given(): void
    {
        // The store's own defaults (depth 2, hot only) must apply, so the
        // keys have to be absent rather than sent as null.
        $this->client()->queue(['{"hits":[],"misses":[]}']);

        $this->tool()->recall(['a']);

        self::assertSame(['queries' => [['key' => 'a']]], $this->body());
    }

    public function test_recall_trims_keys_but_keeps_their_spelling(): void
    {
        // memory-draft records aliases from the spelling the caller used, so
        // the tool must not canonicalize — only drop surrounding whitespace.
        $this->client()->queue(['{"hits":[],"misses":[]}']);

        $this->tool()->recall(['  ContextShuttle  ']);

        self::assertSame(['queries' => [['key' => 'ContextShuttle']]], $this->body());
    }

    public function test_recall_rejects_a_blank_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->tool()->recall(['   ']);
    }

    public function test_recall_rejects_a_bad_depth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('depth must be at least 1');

        $this->tool()->recall(['a'], 0);
    }

    public function test_recall_rejects_a_response_without_hits(): void
    {
        $this->client()->queue(['{"unexpected":true}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing "hits" array');

        $this->tool()->recall(['a']);
    }

    // ── remember ────────────────────────────────────────────────────────

    public function test_remember_posts_a_single_item_and_returns_the_service_result(): void
    {
        $this->client()->queue(['{"results":[{"key":"deploy","mode":"append","revision":1,"added":2,"empty":false,"retired":[],"evicted":[],"purged":[]}],"purged":[]}']);

        $result = $this->tool()->remember('deploy', 'Uses blue-green. Rollback is one command.');

        self::assertSame('POST', $this->client()->calls[0]['method']);
        self::assertSame('https://memory.example.com/api/remember', $this->client()->calls[0]['url']);
        self::assertSame(
            ['items' => [['key' => 'deploy', 'sentences' => 'Uses blue-green. Rollback is one command.']]],
            $this->body(),
        );

        self::assertSame(2, $result['results'][0]['added']);
        self::assertFalse($result['results'][0]['empty']);
    }

    public function test_remember_passes_mode_pin_and_revision(): void
    {
        $this->client()->queue(['{"results":[{"key":"a"}],"purged":[]}']);

        $this->tool()->remember('a', 'text', 'replace', true, 8);

        self::assertSame(
            ['items' => [['key' => 'a', 'sentences' => 'text', 'mode' => 'replace', 'pin' => true, 'revision' => 8]]],
            $this->body(),
        );
    }

    public function test_remember_omits_optional_fields_when_not_given(): void
    {
        // Append-and-unpinned is the store's default; sending `mode: append`
        // explicitly would be the tool re-deciding something it does not own.
        //
        // Omitting `pin` is load-bearing beyond brevity: memory-draft reads an
        // absent pin as "no instruction", so it leaves an existing pin alone.
        // If this tool ever started sending a default, re-stating a fact would
        // silently un-pin it.
        $this->client()->queue(['{"results":[{"key":"a"}],"purged":[]}']);

        $this->tool()->remember('a', 'text');

        self::assertSame(['items' => [['key' => 'a', 'sentences' => 'text']]], $this->body());
        self::assertArrayNotHasKey('pin', $this->body()['items'][0]);
    }

    public function test_remember_forwards_an_explicit_false_pin_so_it_can_unpin(): void
    {
        // `false` must survive the passthrough. A truthiness check here would
        // drop it and make un-pinning impossible through the tool — the one
        // way to take a pin back without destroying the key with `forget`.
        $this->client()->queue(['{"results":[{"key":"a"}],"purged":[]}']);

        $this->tool()->remember('a', 'text', null, false);

        $item = $this->body()['items'][0];
        self::assertArrayHasKey('pin', $item);
        self::assertFalse($item['pin'], 'an explicit false pin means "un-pin", not "unspecified"');
    }

    public function test_remember_rejects_empty_sentences_before_calling_the_service(): void
    {
        // memory-draft would answer 200 with `empty: true`; a write that
        // stored nothing must not look like success.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sentences must not be empty');

        $this->tool()->remember('a', '   ');
    }

    public function test_remember_rejects_a_blank_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('key must not be empty');

        $this->tool()->remember('', 'text');
    }

    public function test_remember_rejects_an_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mode must be "append" or "replace"');

        $this->tool()->remember('a', 'text', 'upsert');
    }

    public function test_remember_rejects_a_non_positive_revision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('revision must be at least 1');

        $this->tool()->remember('a', 'text', revision: 0);
    }

    public function test_remember_rejects_a_response_without_results(): void
    {
        $this->client()->queue(['{"purged":[]}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing "results" array');

        $this->tool()->remember('a', 'text');
    }

    // ── keys ────────────────────────────────────────────────────────────

    public function test_keys_wraps_the_bare_list_with_a_count(): void
    {
        $this->client()->queue(['[{"key":"a","revision":1,"hot":2,"cold":0,"backfilled":0,"aliases":[],"last_written":"just now"}]']);

        $result = $this->tool()->keys();

        self::assertSame('GET', $this->client()->calls[0]['method']);
        self::assertSame('https://memory.example.com/api/keys', $this->client()->calls[0]['url']);
        self::assertSame('a', $result['keys'][0]['key']);
        self::assertSame(1, $result['count']);
    }

    public function test_keys_sends_pattern_and_limit_as_query_parameters(): void
    {
        $this->client()->queue(['[]']);

        $this->tool()->keys('project: foo', 25);

        self::assertSame(
            ['pattern' => 'project: foo', 'limit' => 25],
            $this->client()->calls[0]['options']['query'],
        );
    }

    public function test_keys_omits_an_empty_pattern(): void
    {
        // A blank pattern means "everything" upstream, so sending one would
        // be noise — and a whitespace-only value must not become a literal
        // space search.
        $this->client()->queue(['[]']);

        $this->tool()->keys('   ');

        self::assertSame([], $this->client()->calls[0]['options']['query'] ?? []);
    }

    public function test_keys_rejects_a_limit_above_the_service_ceiling(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be between 1 and 1000');

        $this->tool()->keys(null, 1001);
    }

    public function test_keys_rejects_a_response_that_is_not_a_list(): void
    {
        $this->client()->queue(['{"keys":[]}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected a list of keys');

        $this->tool()->keys();
    }

    // ── stats ───────────────────────────────────────────────────────────

    public function test_stats_passes_the_payload_through(): void
    {
        $this->client()->queue(['{"total":3,"hot":3,"cold":0,"pinned":0,"backfilled":0,"keys":2,"caps":{"hot_per_key":20,"cold_per_key":200}}']);

        $result = $this->tool()->stats();

        self::assertSame('GET', $this->client()->calls[0]['method']);
        self::assertSame('https://memory.example.com/api/stats', $this->client()->calls[0]['url']);
        self::assertSame(2, $result['keys']);
        self::assertSame(20, $result['caps']['hot_per_key']);
    }

    public function test_stats_rejects_a_response_missing_the_budgets(): void
    {
        // Without `caps` the counts are uninterpretable, so a half-shaped
        // answer is worth refusing rather than passing on.
        $this->client()->queue(['{"total":0,"keys":0}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing "keys" or "caps"');

        $this->tool()->stats();
    }

    // ── forget ──────────────────────────────────────────────────────────

    public function test_forget_deletes_by_url_encoded_key(): void
    {
        $this->client()->queue(['{"key":"project:foo","deleted":3}']);

        $result = $this->tool()->forget('project:foo');

        self::assertSame('DELETE', $this->client()->calls[0]['method']);
        // The key is a path segment, so a colon or a slash must be encoded
        // rather than starting a new path or query.
        self::assertSame('https://memory.example.com/api/keys/project%3Afoo', $this->client()->calls[0]['url']);
        self::assertSame(3, $result['deleted']);
    }

    public function test_forget_reports_nothing_matched_as_a_failure(): void
    {
        // memory-draft answers 404 with an error envelope; that is a normal
        // outcome for a delete, but it must not read as a deletion.
        $this->client()->queueWithStatus('{"error":"No key matched \"gone\".","deleted":0}', 404);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no key matching "gone"');

        $this->tool()->forget('gone');
    }

    public function test_forget_rejects_a_blank_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('key must not be empty');

        $this->tool()->forget('  ');
    }

    // ── transport & configuration ───────────────────────────────────────

    public function test_trailing_slash_on_the_base_url_is_removed(): void
    {
        $this->client()->queue(['{"hits":[],"misses":[]}']);

        $this->tool('https://memory.example.com/')->recall(['a']);

        self::assertSame('https://memory.example.com/api/recall', $this->client()->calls[0]['url']);
    }

    public function test_an_unconfigured_deployment_names_the_variable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEMORY_DRAFT_URL is not configured');

        $this->tool('')->recall(['a']);
    }

    public function test_a_url_without_a_scheme_is_refused_with_the_variable_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEMORY_DRAFT_URL must start with http:// or https://');

        $this->tool('memory.example.com')->keys();
    }

    public function test_a_404_on_an_api_path_names_the_url_variable(): void
    {
        // A URL that answers, but not with this API, is a configuration
        // mistake — not an empty result.
        $this->client()->queueWithStatus('not here', 404);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Check that MEMORY_DRAFT_URL points at a memory-draft instance');

        $this->tool('https://not-memory.example.com')->stats();
    }

    public function test_a_validation_error_reports_the_service_detail(): void
    {
        $this->client()->queueWithStatus(json_encode([
            'type' => 'https://symfony.com/errors/validation',
            'title' => 'Validation Failed',
            'status' => 422,
            'detail' => 'items: This collection should contain 1 element or more.',
        ], \JSON_THROW_ON_ERROR), 422);

        try {
            $this->tool()->remember('a', 'text');
            self::fail('A 422 should raise.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('HTTP 422', $e->getMessage());
            self::assertStringContainsString('This collection should contain 1 element or more.', $e->getMessage());
        }
    }

    public function test_a_non_json_error_body_does_not_leak_markup_into_the_message(): void
    {
        // A reverse proxy's HTML error page must not arrive as tool output:
        // it is unbounded, and a tool result is what a context window is made
        // of.
        $this->client()->queueWithStatus('<html><body>502 Bad Gateway</body></html>', 502);

        try {
            $this->tool()->keys();
            self::fail('A 502 should raise.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('HTTP 502', $e->getMessage());
            self::assertStringContainsString('502 Bad Gateway', $e->getMessage());
            self::assertStringNotContainsString('<html>', $e->getMessage());
        }
    }

    public function test_an_oversized_error_body_is_truncated(): void
    {
        $this->client()->queueWithStatus(str_repeat('A very long upstream complaint. ', 100), 500);

        try {
            $this->tool()->keys();
            self::fail('A 500 should raise.');
        } catch (RuntimeException $e) {
            self::assertLessThan(400, \strlen($e->getMessage()), 'an error page must not flood the result');
        }
    }

    public function test_no_credentials_are_ever_sent(): void
    {
        // memory-draft has no authentication; asserting this keeps a future
        // "let us add a token" from silently passing credentials nowhere.
        $this->client()->queue(['{"hits":[],"misses":[]}']);

        $this->tool()->recall(['a']);

        self::assertSame(['Accept: application/json'], $this->client()->requestHeaders()['accept']);
        self::assertArrayNotHasKey('authorization', $this->client()->requestHeaders());
        self::assertArrayNotHasKey('x-api-key', $this->client()->requestHeaders());
    }
}
