<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Event\ErrorEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

/**
 * One structured log line per tool invocation, regardless of protocol.
 *
 * This is the heart of the "thorough logging" requirement: MCP and REST both
 * funnel through `Server::run()`, so both are seen here and neither needs its
 * own logging code.
 *
 * The official SDK dispatches PSR-14 events per request rather than exposing a
 * dispatcher to decorate, so this listens on two:
 *
 *  - {@see ResponseEvent} — the tool ran. Note that a tool *throwing* still
 *    arrives here with `isError: true`, because the SDK converts it into a
 *    `CallToolResult` so the model can read the message and self-correct.
 *  - {@see ErrorEvent} — the request never reached the tool: unknown name,
 *    schema violation, malformed params. These carry no `CallToolResult`, so
 *    without this listener the most interesting failures would be invisible.
 *
 * Field names and truncation limits are unchanged from the previous
 * implementation so existing log queries keep working.
 */
final class InvocationLogListener
{
    /** Arguments longer than this are replaced by a truncated preview. */
    private const MAX_ARGUMENT_BYTES = 2048;

    /** Result previews longer than this are truncated. */
    private const MAX_RESULT_BYTES = 1024;

    /** Keys whose values are redacted wherever they appear in arguments. */
    private const REDACTED_KEYS = ['password', 'token', 'api_key', 'apikey', 'secret', 'authorization'];

    public function __construct(
        private readonly LoggerInterface $invocationLogger,
    ) {
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        // Only tool calls are invocation-logged; initialize/tools/list are
        // protocol chatter and would drown the tool activity.
        if (!$request instanceof CallToolRequest) {
            return;
        }

        $result = $event->getResponse()->result;

        $this->invocationLogger->info('Tool invoked.', [
            'request_id' => $this->requestId($event),
            'tool' => $request->name,
            'arguments' => $this->sanitize($request->arguments),
            'is_error' => $result instanceof CallToolResult ? $result->isError : false,
            'result' => $result instanceof CallToolResult ? $this->summarize($result) : null,
        ]);
    }

    public function onError(ErrorEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request instanceof CallToolRequest) {
            return;
        }

        $error = $event->getError();
        $throwable = $event->getThrowable();

        $this->invocationLogger->error('Tool invocation failed.', [
            'request_id' => $this->requestId($event),
            'tool' => $request->name,
            'arguments' => $this->sanitize($request->arguments),
            'error' => $error->message,
            'code' => $error->code,
            'error_class' => null !== $throwable ? $throwable::class : null,
        ]);
    }

    /**
     * The session id doubles as the correlation id: unlike the previous
     * implementation's synthetic per-call id, it ties every call in one client
     * session together.
     */
    private function requestId(ResponseEvent|ErrorEvent $event): string
    {
        return $event->getSession()->getId()->toRfc4122();
    }

    /**
     * Keep arguments in the log, bound their size, and redact secrets.
     *
     * A deployment that logs tool arguments is logging everything a model
     * decided to send; redaction here is the difference between a useful
     * invocation log and a credential leak.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $arguments): array
    {
        $encoded = json_encode($arguments);

        if (false === $encoded) {
            return ['_unencodable' => true];
        }

        if (\strlen($encoded) > self::MAX_ARGUMENT_BYTES) {
            return ['_truncated' => substr($encoded, 0, self::MAX_ARGUMENT_BYTES).'…'];
        }

        return $this->redact($arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function redact(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (\is_array($value)) {
                $arguments[$key] = $this->redact($value);

                continue;
            }

            if (\in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $arguments[$key] = '[redacted]';
            }
        }

        return $arguments;
    }

    /**
     * @return array{content_preview: string, structured?: mixed}
     */
    private function summarize(CallToolResult $result): array
    {
        $texts = [];
        foreach ($result->content as $content) {
            $texts[] = $content instanceof TextContent
                ? $content->text
                : (json_encode($content) ?: '');
        }

        $preview = implode("\n", $texts);
        if (\strlen($preview) > self::MAX_RESULT_BYTES) {
            $preview = substr($preview, 0, self::MAX_RESULT_BYTES).'…';
        }

        $summary = ['content_preview' => $preview];

        if (null !== $result->structuredContent) {
            $summary['structured'] = $result->structuredContent;
        }

        return $summary;
    }
}
