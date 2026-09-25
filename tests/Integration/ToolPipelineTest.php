<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Full-pipeline integration tests through the Symfony kernel.
 *
 * Uses the echo tool (no network) so the suite runs anywhere.
 *
 * @internal
 *
 * @coversNothing
 */
final class ToolPipelineTest extends WebTestCase
{
    use McpSessionTrait;

    public function test_health_endpoint(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertJson((string) $client->getResponse()->getContent());
        self::assertSame(['status' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function test_ready_endpoint_reports_tool_count(): void
    {
        $client = self::createClient();
        $client->request('GET', '/ready');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('ready', $data['status']);
        self::assertGreaterThanOrEqual(2, $data['tools']);
    }

    public function test_tools_list_includes_yaml_tools(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $names = array_column($data['tools'], 'name');
        self::assertContains('echo', $names);
        self::assertContains('get_weather', $names);
        self::assertContains('get_transactions', $names);
        self::assertContains('get_health_logs', $names);
        self::assertContains('send_alert', $names);

        $echo = $data['tools'][array_search('echo', $names, true)];
        self::assertArrayHasKey('message', $echo['parameters']);
    }

    public function test_mcp_initialize(): void
    {
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: <<<'JSON'
            {"jsonrpc":"2.0","id":1,"method":"initialize","params":{
              "protocolVersion":"2025-03-26","capabilities":{},
              "clientInfo":{"name":"test","version":"1.0"}}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('2.0', $data['jsonrpc']);
        self::assertSame(1, $data['id']);
        self::assertSame('context-shuttle-test', $data['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $data['result']['capabilities']);

        // The handshake is what mints the session every later request needs.
        self::assertNotNull(
            $client->getResponse()->headers->get('Mcp-Session-Id'),
            'initialize must return an Mcp-Session-Id header.',
        );
    }

    public function test_mcp_non_initialize_request_without_session_is_rejected(): void
    {
        // Deliberate behaviour change from the previous library, which minted a
        // throwaway session per request. The SDK requires a real handshake, so a
        // caller that skips it is told so instead of being silently served.
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: '{"jsonrpc":"2.0","id":2,"method":"tools/list"}');

        self::assertResponseStatusCodeSame(400);
        $data = $this->jsonRpcResponse($client);
        self::assertSame(-32600, $data['error']['code']);
        self::assertStringContainsString('session id is REQUIRED', $data['error']['message']);
    }

    public function test_mcp_tools_list(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);
        $this->mcpRequest($client, $sessionId, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}');

        self::assertResponseIsSuccessful();
        $data = $this->jsonRpcResponse($client);

        $names = array_column($data['result']['tools'], 'name');
        self::assertContains('echo', $names);
        self::assertContains('get_weather', $names);
        self::assertContains('get_transactions', $names);
        self::assertContains('get_health_logs', $names);
        self::assertContains('send_alert', $names);
    }

    public function test_mcp_tools_call_echo(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);
        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":3,"method":"tools/call","params":{
              "name":"echo","arguments":{"message":"hello shuttle","style":"upper"}}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = $this->jsonRpcResponse($client);

        self::assertFalse($data['result']['isError']);
        $text = $data['result']['content'][0]['text'];
        $payload = json_decode($text, true);
        self::assertSame('HELLO SHUTTLE', $payload['echo']);
    }

    public function test_mcp_invalid_arguments(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);
        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":4,"method":"tools/call","params":{
              "name":"echo","arguments":{"message":123}}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = $this->jsonRpcResponse($client);

        self::assertSame(-32602, $data['error']['code']);
    }

    public function test_mcp_parse_error(): void
    {
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{not json');

        // The SDK reports a parse error as a JSON-RPC error inside a 200, not
        // as an HTTP 400: the transport succeeded, the *message* did not parse.
        self::assertResponseIsSuccessful();
        $data = $this->jsonRpcResponse($client);
        self::assertSame(-32700, $data['error']['code']);
    }

    public function test_mcp_unknown_tool(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);
        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":5,"method":"tools/call","params":{
              "name":"nope","arguments":{}}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = $this->jsonRpcResponse($client);

        // An unknown tool is invalid *params*: tools/call exists, the name in
        // its params does not.
        self::assertSame(-32602, $data['error']['code']);
        self::assertStringContainsString('nope', $data['error']['message']);
    }

    public function test_mcp_tool_failure_message_reaches_the_client(): void
    {
        // Regression guard for the whole reason
        // ToolFailureTranslatingReferenceHandler exists: an unconfigured
        // upstream must surface its actionable message, not a generic
        // "Error while executing tool".
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);
        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":6,"method":"tools/call","params":{
              "name":"get_transactions","arguments":{"from":"2026-01-01","to":"2026-01-31"}}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = $this->jsonRpcResponse($client);

        self::assertTrue($data['result']['isError']);
        self::assertStringContainsString(
            'PENNYTRACK_URL is not configured',
            $data['result']['content'][0]['text'],
        );
    }

    public function test_rest_invoke_echo(): void
    {
        $client = self::createClient();
        $client->request('POST', '/tools/echo', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"message":"hello rest"}');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('echo', $data['tool']);
        self::assertSame('hello rest', $data['result']['echo']);
    }

    public function test_rest_applies_defaults_and_enum(): void
    {
        $client = self::createClient();
        $client->request('POST', '/tools/echo', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"message":"Shhh","style":"lower"}');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('shhh', $data['result']['echo']);
    }

    public function test_rest_unknown_tool_is404(): void
    {
        $client = self::createClient();
        $client->request('POST', '/tools/nope', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');

        self::assertResponseStatusCodeSame(404);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('available_tools', $data);
    }

    public function test_rest_invalid_arguments_is422(): void
    {
        $client = self::createClient();
        $client->request('POST', '/tools/echo', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"message":"x","style":"bogus"}');

        self::assertResponseStatusCodeSame(422);
    }

    public function test_rest_missing_required_is422(): void
    {
        $client = self::createClient();
        $client->request('POST', '/tools/echo', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');

        self::assertResponseStatusCodeSame(422);
    }

    public function test_unconfigured_pennytrack_reports_clear_error(): void
    {
        // The test environment deliberately has neither PENNYTRACK_URL nor
        // PENNYTRACK_API_KEY set (.env.local is not loaded for APP_ENV=test),
        // which is exactly what a deployment without penny-track looks like.
        // Invoking the tool must produce its friendly "not configured" error —
        // not a TypeError from the container trying to inject null into the
        // tool's string-typed constructor arguments.
        $client = self::createClient();
        $client->request('POST', '/tools/get_transactions', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"from":"2026-01-01","to":"2026-01-31"}');

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertStringContainsString('PENNYTRACK_URL is not configured', $data['error']);
    }

    public function test_open_api_document(): void
    {
        $client = self::createClient();
        $client->request('GET', '/openapi.json');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('3.1.0', $data['openapi']);
        self::assertArrayHasKey('/tools/echo', $data['paths']);
        self::assertArrayHasKey('/tools/get_weather', $data['paths']);
        self::assertArrayHasKey('EchoInput', $data['components']['schemas']);
        self::assertSame('context-shuttle-test', $data['info']['title']);
    }
}
