<?php

declare(strict_types=1);

namespace App\Mcp;

use GuzzleHttp\Psr7\ServerRequest;
use PhpMcp\Schema\JsonRpc\BatchRequest;
use PhpMcp\Schema\JsonRpc\Notification;
use PhpMcp\Schema\JsonRpc\Parser;
use PhpMcp\Schema\JsonRpc\Request as JsonRpcRequest;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * MCP-over-HTTP endpoint (streamable HTTP, JSON response mode).
 *
 * POST /mcp accepts one JSON-RPC request (or batch); the response is the
 * matching JSON-RPC response. Sessions are handled statelessly per request:
 * initialize requests mint a session that lives for the duration of that
 * HTTP request (modern spec allows stateless servers).
 */
final class McpController
{
    public function __construct(
        private McpServerFactory $factory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ('OPTIONS' === $request->getMethod()) {
            // Handled before routing by CorsSubscriber; kept as a fallback.
            return new Response(status: 204);
        }

        if ('POST' !== $request->getMethod()) {
            return new JsonResponse([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32601,
                    'message' => 'Method not allowed. Use POST with JSON-RPC payloads; OPTIONS for CORS.',
                ],
                'id' => null,
            ], Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$this->acceptsJson($request)) {
            return new JsonResponse([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Not Acceptable: client must accept application/json or text/event-stream.',
                ],
                'id' => null,
            ], Response::HTTP_NOT_ACCEPTABLE);
        }

        $content = $request->getContent();
        if ('' === $content) {
            return $this->jsonRpcError(-32600, 'Empty request body.', null, Response::HTTP_BAD_REQUEST);
        }

        try {
            $message = Parser::parse($content);
        } catch (Throwable $e) {
            return $this->jsonRpcError(-32700, 'Parse error: '.$e->getMessage(), null, Response::HTTP_BAD_REQUEST);
        }

        if (!$message instanceof JsonRpcRequest && !$message instanceof Notification && !$message instanceof BatchRequest) {
            return $this->jsonRpcError(-32600, 'Invalid Request: unsupported message type.', null, Response::HTTP_BAD_REQUEST);
        }

        // Statelessness strategy: every request gets a fresh session. An
        // initialize request marks it initialized; every other request is
        // also marked initialized so tools/call works without a handshake.
        $sessionId = bin2hex(random_bytes(16));

        $stack = $this->factory->build();
        $transport = new CaptureTransport();
        $stack->protocol->bindTransport($transport);

        $stack->sessionManager->createSession($sessionId);

        $context = [
            'is_initialize_request' => $message instanceof JsonRpcRequest && 'initialize' === $message->method,
            'stateless' => true,
            'request' => $this->toPsr7($request),
        ];

        try {
            $stack->protocol->processMessage($message, $sessionId, $context);
        } catch (Throwable $e) {
            $id = $message instanceof JsonRpcRequest ? $message->id : null;

            return $this->jsonRpcError(-32603, 'Internal error: '.$e->getMessage(), $id, Response::HTTP_OK);
        } finally {
            $stack->sessionManager->deleteSession($sessionId);
        }

        $response = $transport->lastMessage();

        if (null === $response) {
            // Notification-only payloads correctly have no response.
            return new Response(status: 202);
        }

        return new Response(
            json_encode($response, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n",
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }

    private function acceptsJson(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept', '');

        return '' === $accept
            || str_contains($accept, 'application/json')
            || str_contains($accept, 'text/event-stream')
            || str_contains($accept, '*/*');
    }

    private function toPsr7(Request $request): ServerRequestInterface
    {
        $protocolVersion = '1.1';
        if (preg_match('#^HTTP/(\d\.\d)$#', $request->server->get('SERVER_PROTOCOL', ''), $m)) {
            $protocolVersion = $m[1];
        }

        $headers = array_map(
            static fn (array $values): array => array_values(array_map('strval', array_filter($values, static fn ($v) => null !== $v))),
            $request->headers->all(),
        );

        return new ServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $headers,
            $request->getContent(),
            $protocolVersion,
        );
    }

    private function jsonRpcError(int $code, string $message, int|string|null $id, int $status): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => $id,
        ], $status);
    }
}
