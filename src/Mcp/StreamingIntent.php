<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Server\RequestContext;

/**
 * Whether the request currently being served may open a stream.
 *
 * A tool that sends a notification suspends its fiber, and the streamable-HTTP
 * transport answers a suspended fiber with `text/event-stream` instead of a
 * buffered JSON body. That is exactly right for an MCP client, which asked for
 * a stream and knows how to read one — and exactly wrong for the REST surface,
 * which has one JSON body to return and cannot express a stream at all (see
 * `ToolRestController`).
 *
 * So the caller declares its intent, per request, on the JSON-RPC `_meta`
 * member, and a tool asks here before it notifies. The declaration travels with
 * the request rather than sitting in shared state, so a worker serving two
 * callers cannot leak one's answer into the other's call.
 *
 * The default is **streaming allowed**: an absent flag is the MCP path, which
 * is where streaming belongs. Only an explicit `false` turns it off, so the
 * silence is always something a caller chose rather than something it failed to
 * ask for.
 */
final class StreamingIntent
{
    /**
     * The `_meta` key carrying the caller's intent.
     *
     * Namespaced with an `app/` prefix because `_meta` is shared with protocol
     * members (`progressToken`, the protocol revision) and a bare name risks a
     * collision with one.
     */
    public const META_KEY = 'app/streaming';

    /**
     * False only when the request said so.
     *
     * Deliberately not a general truthiness test: `0`, `''` and `'false'` are
     * not this protocol's spelling of "no", and guessing at them would make the
     * flag's meaning depend on the caller's JSON serialization.
     */
    public static function allowed(RequestContext $context): bool
    {
        $meta = $context->getRequest()->getMeta() ?? [];

        return false !== ($meta[self::META_KEY] ?? true);
    }
}
