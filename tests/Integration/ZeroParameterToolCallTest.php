<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression tests for zero-parameter tools (#41 non-taskwarrior note, #42).
 *
 * `taskwarrior_sync_status`, `taskwarrior_next_action`,
 * `taskwarrior_project_list` (and `email_list_folders`, `memory_stats`)
 * declare `parameters: {}` in YAML. Reports from the deployed instance show
 * every such call failing with
 *
 *     Additional object properties are not allowed: {properties}
 *
 * while every tool with at least one parameter works. This exercises the
 * zero-argument call path end-to-end through the real MCP pipeline.
 *
 * @internal
 *
 * @coversNothing
 */
final class ZeroParameterToolCallTest extends WebTestCase
{
    use McpSessionTrait;

    /**
     * `echo` with a full argument object proves the pipeline itself is healthy,
     * so a zero-arg failure below is attributable to the empty-arguments path.
     */
    public function test_tool_with_arguments_still_works(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);
        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":1,"method":"tools/call","params":{
              "name":"echo","arguments":{"message":"hello"}}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = $this->jsonRpcResponse($client);
        self::assertFalse($data['result']['isError']);
    }

    public function test_zero_parameter_tool_call_succeeds_via_mcp(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);
        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":2,"method":"tools/call","params":{
              "name":"taskwarrior_sync_status","arguments":{}}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = $this->jsonRpcResponse($client);

        // The bug under test: JSON-RPC error -32602 "Additional object
        // properties are not allowed: {properties}".
        self::assertArrayNotHasKey(
            'error',
            $data,
            'Zero-parameter tool call must not be rejected by schema validation: '
            .json_encode($data['error'] ?? null),
        );

        self::assertFalse($data['result']['isError']);
        // Unconfigured sync in the test env: configured=false, nulls elsewhere.
        $payload = json_decode($data['result']['content'][0]['text'], true);
        self::assertFalse($payload['configured']);
    }

    public function test_zero_parameter_tool_call_succeeds_via_rest(): void
    {
        $client = self::createClient();
        $client->request('POST', '/tools/taskwarrior_sync_status', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        // REST wraps the tool result: {"tool": ..., "result": {...}}.
        self::assertFalse($data['result']['configured']);
    }
}
