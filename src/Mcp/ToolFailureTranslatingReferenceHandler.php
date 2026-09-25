<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ToolCallException;
use Override;
use Throwable;

/**
 * Keeps tool-authored error messages in front of the caller.
 *
 * This exists because of how the official SDK classifies a thrown exception,
 * and the difference is worth understanding before changing any of it:
 *
 *  - `ToolCallException` → `CallToolResult(isError: true)` carrying the
 *    message. The model reads it and can correct itself.
 *  - anything else → a generic `-32603 "Error while executing tool"`. The real
 *    message is logged server-side and **discarded**.
 *
 * Every tool in this project reports expected, actionable failures by throwing
 * a plain `RuntimeException` — "PENNYTRACK_URL is not configured. Set it in
 * .env.local…", "Penny-track rejected the API key…". Under the previous library
 * those strings reached the client. Under the SDK's default handling they would
 * be replaced by "Error while executing tool", which is a real regression: the
 * operator's one useful clue about a misconfigured deployment would move to a
 * log file they have to go looking for.
 *
 * Rather than edit nine tools to throw a different class — which would also
 * break their unit tests, and would have to be remembered for every tool added
 * later — the translation happens once, here, at the single point every
 * handler passes through.
 *
 * The wrapper is deliberately conservative:
 *
 *  - `ToolCallException`, `RegistryException` and `InvalidArgumentException`
 *    already carry the right meaning and pass through untouched.
 *  - Only failures raised while a tool is actually being invoked are
 *    translated (`_session` is injected into the argument bag solely for tool
 *    calls), so prompt/resource handlers keep their own error semantics.
 */
final class ToolFailureTranslatingReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ReferenceHandlerInterface $inner,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Override]
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        try {
            return $this->inner->handle($reference, $arguments);
        } catch (RegistryException|InvalidArgumentException $e) {
            // Already the shape the SDK renders, and already carrying a
            // message meant for the client.
            throw $e;
        } catch (Throwable $e) {
            // A tool that already threw `ToolCallException` needs no help — it
            // is the shape `CallToolHandler` renders with `isError: true`. It
            // is checked here rather than in the catch list above because the
            // interface declares only `RegistryException` and
            // `InvalidArgumentException`; a `ToolCallException` reaches us
            // through the tool's own throw, which no signature advertises.
            if ($e instanceof ToolCallException) {
                throw $e;
            }

            if (!isset($arguments['_session'])) {
                // Not a tool call: let it surface as-is rather than
                // mislabelling it a tool failure.
                throw $e;
            }

            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }
}
