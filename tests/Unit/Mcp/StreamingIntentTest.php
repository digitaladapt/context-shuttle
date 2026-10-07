<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\StreamingIntent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * The rule that stops a notification corrupting a REST response.
 *
 * The default is the part worth testing hardest: MCP does not send the flag, so
 * "absent means allowed" is what keeps streaming on for the protocol that wants
 * it, and every other outcome has to be an explicit choice.
 *
 * @internal
 *
 * @covers \App\Mcp\StreamingIntent
 */
final class StreamingIntentTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $meta
     */
    private function contextWithMeta(?array $meta): RequestContext
    {
        $request = new CallToolRequest('count', []);

        if (null !== $meta) {
            $request = $request->withMeta($meta);
        }

        return new RequestContext(new Session(new InMemorySessionStore()), $request);
    }

    public function test_absent_flag_allows_streaming(): void
    {
        // The MCP path: no `_meta` at all, which must not be read as "no".
        self::assertTrue(StreamingIntent::allowed($this->contextWithMeta(null)));
    }

    public function test_empty_meta_allows_streaming(): void
    {
        self::assertTrue(StreamingIntent::allowed($this->contextWithMeta([])));
    }

    public function test_unrelated_meta_allows_streaming(): void
    {
        self::assertTrue(StreamingIntent::allowed($this->contextWithMeta(['progressToken' => 'tok'])));
    }

    public function test_explicit_false_disallows_streaming(): void
    {
        self::assertFalse(StreamingIntent::allowed($this->contextWithMeta([StreamingIntent::META_KEY => false])));
    }

    /**
     * Only the boolean `false` turns streaming off.
     *
     * `0` and `'false'` are how other languages spell "no", and treating them as
     * refusal here would make the flag mean different things to different
     * callers — a JSON serialization detail deciding whether a notification
     * reaches a client.
     */
    public function test_other_falsy_values_are_not_refusals(): void
    {
        self::assertTrue(StreamingIntent::allowed($this->contextWithMeta([StreamingIntent::META_KEY => 0])));
        self::assertTrue(StreamingIntent::allowed($this->contextWithMeta([StreamingIntent::META_KEY => 'false'])));
        self::assertTrue(StreamingIntent::allowed($this->contextWithMeta([StreamingIntent::META_KEY => null])));
    }

    public function test_the_key_is_namespaced(): void
    {
        // `_meta` is shared with protocol members; a bare name invites a
        // collision with one of them.
        self::assertSame('app/streaming', StreamingIntent::META_KEY);
    }
}
