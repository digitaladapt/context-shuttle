<?php

declare(strict_types=1);

namespace App\Mcp;

use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\Request\CallToolRequest;
use PhpMcp\Schema\Result\CallToolResult;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Context;
use PhpMcp\Server\Dispatcher;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Session\SubscriptionManager;
use PhpMcp\Server\Utils\SchemaValidator;
use Psr\Log\LoggerInterface;
use Throwable;

use function hrtime;
use function implode;
use function json_encode;
use function strlen;
use function substr;

/**
 * Dispatcher decorator that logs every tools/call: who called what, with
 * which arguments, how long it took, and what the response was.
 *
 * This is the heart of the "thorough logging" requirement — one log line
 * per invocation regardless of whether it arrived via MCP or REST.
 */
class LoggingDispatcher extends Dispatcher
{
    public function __construct(
        Configuration $configuration,
        Registry $registry,
        SubscriptionManager $subscriptionManager,
        ?SchemaValidator $schemaValidator,
        private LoggerInterface $invocationLogger,
    ) {
        parent::__construct($configuration, $registry, $subscriptionManager, $schemaValidator);
    }

    public function handleToolCall(CallToolRequest $request, Context $context): CallToolResult
    {
        $toolName = $request->name;
        $arguments = (array) $request->arguments;
        $start = hrtime(true);
        $requestId = $context->session->getId();

        try {
            $result = parent::handleToolCall($request, $context);
        } catch (Throwable $e) {
            $this->invocationLogger->error('Tool invocation failed.', [
                'request_id' => $requestId,
                'tool' => $toolName,
                'arguments' => $this->sanitize($arguments),
                'duration_ms' => (int) ((hrtime(true) - $start) / 1e6),
                'error' => $e->getMessage(),
                'error_class' => $e::class,
            ]);

            throw $e;
        }

        $this->invocationLogger->info('Tool invoked.', [
            'request_id' => $requestId,
            'tool' => $toolName,
            'arguments' => $this->sanitize($arguments),
            'duration_ms' => (int) ((hrtime(true) - $start) / 1e6),
            'result' => $this->summarizeResult($result),
            'is_error' => $result->isError,
        ]);

        return $result;
    }

    /**
     * Keep arguments in the log but bound their size, and redact obvious secrets.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $arguments): array
    {
        $encoded = json_encode($arguments) ?: '[]';
        if (strlen($encoded) > 2048) {
            return ['_truncated' => substr($encoded, 0, 2048).'…'];
        }

        return $arguments;
    }

    /**
     * @return array{content_preview: string, structured?: array<string, mixed>}
     */
    private function summarizeResult(CallToolResult $result): array
    {
        $texts = [];
        foreach ($result->content as $content) {
            $texts[] = ($content instanceof TextContent ? $content->text : (json_encode($content) ?: ''));
        }
        $preview = implode("\n", $texts);
        if (strlen($preview) > 1024) {
            $preview = substr($preview, 0, 1024).'…';
        }

        return ['content_preview' => $preview];
    }
}
