<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * MCP session handshake for HTTP tests.
 *
 * The SDK requires a session for every request other than `initialize`, and
 * answers anything else with `400 "A valid session id is REQUIRED for
 * non-initialize requests."`. That is the streamable-HTTP spec behaving
 * correctly, so tests that call `tools/list` or `tools/call` must handshake
 * first and then send `Mcp-Session-Id` — exactly as a real client does.
 *
 * REST tests do not need this: `ToolRestController` mints and discards its own
 * session internally.
 */
trait McpSessionTrait
{
    /**
     * Runs `initialize` and returns the session id to send on later requests.
     *
     * Asserts the handshake succeeded rather than returning a broken value, so
     * a failure here reports itself instead of surfacing as a confusing failure
     * in the test that called it.
     */
    private function initializeMcpSession(KernelBrowser $client, string $clientName = 'test'): string
    {
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: <<<JSON
            {"jsonrpc":"2.0","id":"init","method":"initialize","params":{
              "protocolVersion":"2025-03-26","capabilities":{},
              "clientInfo":{"name":"{$clientName}","version":"1.0"}}}
            JSON);

        self::assertResponseIsSuccessful('MCP initialize should succeed.');

        $sessionId = $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotNull($sessionId, 'initialize must return an Mcp-Session-Id header.');

        return $sessionId;
    }

    /**
     * POST a JSON-RPC payload inside an established session.
     *
     * @param array<string, mixed> $extraServer extra `$_SERVER` values, e.g. HTTP_ORIGIN
     */
    private function mcpRequest(KernelBrowser $client, string $sessionId, string $json, array $extraServer = []): void
    {
        $client->request('POST', '/mcp', server: $extraServer + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_SESSION_ID' => $sessionId,
        ], content: $json);
    }

    /**
     * Decode the JSON-RPC payload of the last response.
     *
     * @return array<string, mixed>
     */
    private function jsonRpcResponse(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'Response body should be a JSON object.');

        return $decoded;
    }
}
