<?php

declare(strict_types=1);

namespace App\Tool\Count;

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Server\RequestContext;

/**
 * The `count://stream` resource: the lines the current `count` run has emitted.
 *
 * This exists because MCP has no streamed result. A `tools/call` returns one
 * `CallToolResult` when it is done, and `resources/read` returns one
 * `ReadResourceResult`; neither can be sent a piece at a time. The only thing
 * that arrives *during* a call is a notification, and a notification is a
 * message about something, not the something itself.
 *
 * `notifications/resources/updated` is the notice; this resource is the
 * something. It carries only a URI — the client is expected to come and read —
 * so the payload has to live somewhere addressable, which is what makes a
 * resource the right shape for "the lines so far" rather than a notification
 * the client would have to be taught to accumulate.
 *
 * Read on its own — before any run, or after a restart — it reports an empty
 * stream rather than failing: "nothing has been counted" is a true answer and a
 * useful one, and it is the state a client's first read will meet.
 */
final class CountResource
{
    /**
     * The resource URI.
     *
     * `count://` is a scheme of this project's own invention, which is allowed:
     * the SDK requires a scheme and a path, not that the scheme be one the world
     * has agreed on. There is no host in the authority position, so nothing
     * suggests this is fetchable over a network.
     */
    public const URI = 'count://stream';

    public const MIME_TYPE = 'text/plain';

    public function __construct(
        private readonly CountStream $stream,
    ) {
    }

    /**
     * The lines emitted so far, one per line, with the run's progress beside
     * them.
     *
     * Returns `TextResourceContents` rather than a plain string because the
     * formatter would put the resource *definition's* `_meta` on the contents
     * and drop anything the read itself wanted to say. Returning the content
     * item directly is the documented way to keep control of the `_meta`
     * member, and `_meta` is where "how many are left" belongs: it is a fact
     * about the read, not part of the text a model should read as content.
     *
     * The `$context` is injected by the SDK for resource reads exactly as it is
     * for tools; the lines are on its session, which is what lets this read see
     * a run that happened in an earlier request.
     */
    public function read(string $uri, RequestContext $context): TextResourceContents
    {
        $run = $this->stream->run($context->getSession());

        $text = implode("\n", array_map(static fn (int $n): string => (string) $n, $run['emitted']));

        return new TextResourceContents(
            uri: $uri,
            mimeType: self::MIME_TYPE,
            text: $text,
            meta: [
                'emitted' => \count($run['emitted']),
                'remaining' => $this->stream->remaining($context->getSession()),
                'total' => $run['total'],
            ],
        );
    }
}
