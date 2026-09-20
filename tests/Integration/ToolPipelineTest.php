<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function array_column;
use function array_search;
use function json_decode;

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
    }

    public function test_mcp_tools_list(): void
    {
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: '{"jsonrpc":"2.0","id":2,"method":"tools/list"}');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $names = array_column($data['result']['tools'], 'name');
        self::assertContains('echo', $names);
        self::assertContains('get_weather', $names);
    }

    public function test_mcp_tools_call_echo(): void
    {
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: <<<'JSON'
            {"jsonrpc":"2.0","id":3,"method":"tools/call","params":{
              "name":"echo","arguments":{"message":"hello shuttle","style":"upper"}}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertFalse($data['result']['isError']);
        $text = $data['result']['content'][0]['text'];
        $payload = json_decode($text, true);
        self::assertSame('HELLO SHUTTLE', $payload['echo']);
    }

    public function test_mcp_invalid_arguments(): void
    {
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: <<<'JSON'
            {"jsonrpc":"2.0","id":4,"method":"tools/call","params":{
              "name":"echo","arguments":{"message":123}}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(-32602, $data['error']['code']);
    }

    public function test_mcp_parse_error(): void
    {
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{not json');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(-32700, $data['error']['code']);
    }

    public function test_mcp_unknown_tool(): void
    {
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: <<<'JSON'
            {"jsonrpc":"2.0","id":5,"method":"tools/call","params":{
              "name":"nope","arguments":{}}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        // The library maps unknown tool names to JSON-RPC method-not-found.
        self::assertSame(-32601, $data['error']['code']);
        self::assertStringContainsString('nope', $data['error']['message']);
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
