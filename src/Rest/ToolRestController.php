<?php

declare(strict_types=1);

namespace App\Rest;

use App\Mcp\CaptureTransport;
use App\Mcp\McpServerFactory;
use App\ToolRegistry\ToolRegistry;
use PhpMcp\Schema\Content\Content;
use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\JsonRpc\Error;
use PhpMcp\Schema\JsonRpc\Response as JsonRpcResponse;
use PhpMcp\Schema\Request\CallToolRequest;
use PhpMcp\Schema\Result\CallToolResult;
use PhpMcp\Server\Protocol;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_key_exists;
use function array_map;
use function assert;
use function bin2hex;
use function count;
use function is_array;
use function is_scalar;
use function json_decode;
use function json_last_error;
use function random_bytes;
use function sprintf;
use function trim;

/**
 * REST surface: POST /tools/{name} invokes a tool; GET /tools lists them.
 *
 * Both endpoints share the exact MCP execution pipeline (Protocol +
 * Dispatcher), so logging and validation are identical across protocols.
 */
final class ToolRestController
{
    public function __construct(
        private McpServerFactory $factory,
        private ToolRegistry $registry,
    ) {}

    public function list(): JsonResponse
    {
        return new JsonResponse([
            'tools' => array_map(
                fn ($tool) => [
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
                'error' => sprintf("Tool '%s' not found.", $name),
                'available_tools' => $this->registry->names(),
            ], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->request->all();
        if ([] === $payload && 'json' === $request->getContentTypeFormat()) {
            $decoded = json_decode($request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $payload = $request->query->all() + $payload;

        // Build the MCP tools/call request and run it through the same
        // Protocol pipeline the /mcp endpoint uses.
        $arguments = $this->applyDefaults($definition->parameters, $payload);

        $jsonRpcId = 'rest-'.bin2hex(random_bytes(8));

        $stack = $this->factory->build();
        $sessionId = 'rest-'.bin2hex(random_bytes(8));
        $stack->sessionManager->createSession($sessionId);

        // Mark the session initialized so tools/call is allowed without a
        // JSON-RPC initialize handshake (REST is inherently stateless).
        $session = $stack->sessionManager->getSession($sessionId);
        assert(null !== $session);
        $session->set('initialized', true);
        $session->set('protocol_version', Protocol::LATEST_PROTOCOL_VERSION);
        $session->set('client_info', ['name' => 'context-shuttle-rest', 'version' => '1.0.0']);
        $session->save();

        $transport = new CaptureTransport();
        $stack->protocol->bindTransport($transport);

        $callRequest = CallToolRequest::make($jsonRpcId, $name, $arguments);

        $context = [
            'is_initialize_request' => false,
            'stateless' => true,
            'request' => null,
        ];

        try {
            $stack->protocol->processMessage($callRequest, $session->getId(), $context);
        } finally {
            $stack->sessionManager->deleteSession($sessionId);
        }

        $response = $transport->lastMessage();

        if ($response instanceof Error) {
            return new JsonResponse([
                'error' => $response->message,
                'code' => $response->code,
            ], $this->statusForCode((int) $response->code));
        }

        if (!$response instanceof JsonRpcResponse) {
            return new JsonResponse(['error' => 'Tool produced no response.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $result = $response->result;
        if (!$result instanceof CallToolResult) {
            return new JsonResponse(['error' => 'Unexpected response shape from tool pipeline.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($result->isError) {
            $text = '';
            foreach ($result->content as $item) {
                if ($item instanceof TextContent) {
                    $text .= $item->text."\n";
                }
            }

            return new JsonResponse([
                'error' => trim($text),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'tool' => $name,
            'result' => $this->extractPayload($result->content),
        ]);
    }

    /**
     * Fill omitted optional parameters from their YAML defaults.
     *
     * @param array<string, array<string, mixed>> $parameters
     * @param array<string, mixed>                $payload
     *
     * @return array<string, mixed>
     */
    private function applyDefaults(array $parameters, array $payload): array
    {
        foreach ($parameters as $paramName => $def) {
            if (!array_key_exists($paramName, $payload) && array_key_exists('default', $def)) {
                $payload[$paramName] = $def['default'];
            }
        }

        return $payload;
    }

    /**
     * @param array<Content> $content
     */
    private function extractPayload(array $content): mixed
    {
        $texts = [];
        foreach ($content as $item) {
            if ($item instanceof TextContent) {
                $texts[] = $item->text;
            }
        }

        if (1 === count($texts)) {
            $decoded = json_decode($texts[0], true);
            if (JSON_ERROR_NONE === json_last_error() && (is_array($decoded) || is_scalar($decoded))) {
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
