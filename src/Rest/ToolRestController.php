<?php

declare(strict_types=1);

namespace App\Rest;

use App\Mcp\McpServerFactory;
use App\ToolRegistry\ToolRegistry;
use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST surface: POST /tools/{name} invokes a tool; GET /tools lists them.
 *
 * Both endpoints share the exact MCP execution pipeline, so validation,
 * argument casting, error mapping and structured logging are identical across
 * protocols by construction rather than by discipline. That is why invocation
 * goes through the SDK's own server instead of calling the handler directly:
 * a second call path is a second place for behaviour to diverge.
 *
 * REST is pre-authenticated by its caller, so there is no handshake to perform.
 * The call is made against a session minted and destroyed within this request —
 * the SDK requires one for any non-`initialize` request, but a REST caller
 * neither has nor needs a session of its own.
 */
final class ToolRestController
{
    public function __construct(
        private readonly McpServerFactory $factory,
        private readonly ToolRegistry $registry,
        private readonly HttpFactory $httpFactory = new HttpFactory(),
    ) {
    }

    public function list(): JsonResponse
    {
        return new JsonResponse([
            'tools' => array_map(
                static fn ($tool) => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->parameters,
                ],
                $this->registry->all(),
            ),
        ]);
    }

    public function invoke(string $name, Request $request): Response
    {
        $definition = $this->registry->get($name);
        if (null === $definition) {
            return new JsonResponse([
                'error' => \sprintf("Tool '%s' not found.", $name),
                'available_tools' => $this->registry->names(),
            ], Response::HTTP_NOT_FOUND);
        }

        $arguments = $this->applyDefaults(
            $definition->parameters,
            $this->extractArguments($request),
        );

        $response = $this->callThroughPipeline($name, $arguments);
        $payload = json_decode((string) $response->getBody(), true);

        if (!\is_array($payload)) {
            return new JsonResponse(
                ['error' => 'Unexpected response shape from tool pipeline.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        // JSON-RPC error: the call never reached the tool (bad arguments,
        // unknown tool). The JSON-RPC code is mapped to its HTTP equivalent.
        if (isset($payload['error'])) {
            $code = (int) ($payload['error']['code'] ?? 0);

            return new JsonResponse([
                'error' => $payload['error']['message'] ?? 'Tool invocation failed.',
                'code' => $code,
            ], $this->statusForCode($code));
        }

        $result = $payload['result'] ?? null;
        if (!\is_array($result)) {
            return new JsonResponse(
                ['error' => 'Unexpected response shape from tool pipeline.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        // The tool ran and reported failure — a misconfiguration, an upstream
        // error. The message is the tool's own, preserved by the reference
        // handler; surfacing it verbatim is the entire point.
        if (true === ($result['isError'] ?? false)) {
            return new JsonResponse([
                'error' => $this->textFromContent($result['content'] ?? []),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'tool' => $name,
            'result' => $this->extractPayload($result),
        ]);
    }

    /**
     * Run one tools/call through the SDK's server, on a session that exists
     * only for this request.
     */
    private function callThroughPipeline(string $tool, array $arguments): ResponseInterface
    {
        $server = $this->factory->build();
        $sessions = $this->factory->sessionManager();

        $session = $sessions->create();
        $session->save();

        try {
            return $server->run(new StreamableHttpTransport(
                $this->toolCallRequest($tool, $arguments, $session),
                // See McpController: the SDK's default stack is localhost-only.
                middleware: [],
            ));
        } finally {
            // No session outlives the request: REST callers cannot resume one,
            // so leaving them behind would only fill the cache.
            $sessions->destroy($session->getId());
        }
    }

    /**
     * A `tools/call` carrying the session we just minted.
     *
     * @param array<string, mixed> $arguments
     */
    private function toolCallRequest(string $tool, array $arguments, SessionInterface $session): \Psr\Http\Message\ServerRequestInterface
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'rest-'.bin2hex(random_bytes(8)),
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                // An empty argument bag must encode as `{}`, not `[]`, or the
                // JSON-RPC params fail schema validation on the way in.
                'arguments' => [] === $arguments ? new stdClass() : $arguments,
            ],
        ], \JSON_THROW_ON_ERROR);

        return $this->httpFactory->createServerRequest('POST', 'http://localhost/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withHeader('Mcp-Session-Id', $session->getId()->toRfc4122())
            ->withBody($this->httpFactory->createStream($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function extractArguments(Request $request): array
    {
        $payload = $request->request->all();

        if ([] === $payload && 'json' === $request->getContentTypeFormat()) {
            $decoded = json_decode($request->getContent(), true);
            $payload = \is_array($decoded) ? $decoded : [];
        }

        return $request->query->all() + $payload;
    }

    /**
     * Fill omitted optional parameters from their YAML defaults.
     *
     * The SDK's schema validator would not accept a missing optional with no
     * default; applying them here keeps the YAML the single source of truth for
     * what "omitted" means.
     *
     * @param array<string, array<string, mixed>> $parameters
     * @param array<string, mixed>                $payload
     *
     * @return array<string, mixed>
     */
    private function applyDefaults(array $parameters, array $payload): array
    {
        foreach ($parameters as $paramName => $def) {
            if (!\array_key_exists($paramName, $payload) && \array_key_exists('default', $def)) {
                $payload[$paramName] = $def['default'];
            }
        }

        return $payload;
    }

    /**
     * @param array<int, mixed> $content
     */
    private function textFromContent(array $content): string
    {
        $texts = [];
        foreach ($content as $item) {
            if (\is_array($item) && 'text' === ($item['type'] ?? null)) {
                $texts[] = (string) ($item['text'] ?? '');
            }
        }

        return trim(implode("\n", $texts));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractPayload(array $result): mixed
    {
        // Prefer the structured form when the tool produced one: it is already
        // a decoded value rather than a JSON string to re-parse.
        if (\array_key_exists('structuredContent', $result) && null !== $result['structuredContent']) {
            return $result['structuredContent'];
        }

        $texts = [];
        foreach ($result['content'] ?? [] as $item) {
            if (\is_array($item) && 'text' === ($item['type'] ?? null)) {
                $texts[] = (string) ($item['text'] ?? '');
            }
        }

        if (1 === \count($texts)) {
            $decoded = json_decode($texts[0], true);
            if (\JSON_ERROR_NONE === json_last_error() && (\is_array($decoded) || \is_scalar($decoded))) {
                return $decoded;
            }

            return $texts[0];
        }

        return $texts;
    }

    private function statusForCode(int $code): int
    {
        return match ($code) {
            -32602 => Response::HTTP_UNPROCESSABLE_ENTITY, // invalid params
            -32601 => Response::HTTP_NOT_FOUND,            // method/tool not found
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }
}
