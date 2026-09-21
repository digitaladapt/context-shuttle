<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

use function json_decode;
use function preg_match;
use function strtolower;

/**
 * Browser-style CORS tests: preflight (OPTIONS + Origin +
 * Access-Control-Request-Method) and credentialed actual requests.
 *
 * Because "Authorization" is in Access-Control-Allow-Headers, browsers
 * require the credentialed pattern: the request's Origin echoed verbatim
 * (never "*"), plus Access-Control-Allow-Credentials: true, plus
 * Vary: Origin. This is what lets the llama.cpp web UI talk to /mcp.
 *
 * @internal
 *
 * @coversNothing
 */
final class CorsTest extends WebTestCase
{
    private const ORIGIN = 'http://localhost:9670';

    public function test_preflight_to_mcp_is_answered_with_credentialed_cors(): void
    {
        $client = self::createClient();
        $client->request('OPTIONS', '/mcp', server: [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, authorization, mcp-session-id',
        ]);

        $response = $client->getResponse();

        self::assertTrue($response->isSuccessful(), 'Preflight should succeed: '.(string) $response->getContent());
        self::assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));

        $allowHeaders = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        self::assertStringContainsString('authorization', $allowHeaders);
        self::assertStringContainsString('mcp-session-id', $allowHeaders);
        self::assertStringContainsString('content-type', $allowHeaders);
        self::assertVaryOrigin($response);
    }

    public function test_preflight_echoes_any_origin(): void
    {
        $client = self::createClient();
        $client->request('OPTIONS', '/mcp', server: [
            'HTTP_ORIGIN' => 'https://llama.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response = $client->getResponse();

        self::assertTrue($response->isSuccessful());
        self::assertSame('https://llama.example.com', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertVaryOrigin($response);
    }

    public function test_preflight_without_origin_returns_no_cors_headers(): void
    {
        $client = self::createClient();
        $client->request('OPTIONS', '/mcp');

        $response = $client->getResponse();

        self::assertTrue($response->isSuccessful());
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        self::assertVaryOrigin($response);
    }

    public function test_actual_request_to_mcp_receives_echoed_origin_and_credentials(): void
    {
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_AUTHORIZATION' => 'Bearer test-token',
        ], content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertVaryOrigin($response);

        // The JSON-RPC response must still be intact underneath the CORS decoration.
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('2.0', $data['jsonrpc']);
        self::assertArrayHasKey('tools', $data['result']);
    }

    public function test_error_responses_also_carry_cors_headers(): void
    {
        // A preflight-allowed origin making a request that ends in a JSON-RPC
        // error must still get CORS headers, or the browser reports only the
        // CORS failure and hides the real error.
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_ORIGIN' => self::ORIGIN,
        ], content: '{not json');

        $response = $client->getResponse();

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_rest_and_health_endpoints_carry_cors_headers(): void
    {
        $client = self::createClient();

        $client->request('GET', '/health', server: ['HTTP_ORIGIN' => self::ORIGIN]);
        $response = $client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));

        $client->request('GET', '/tools', server: ['HTTP_ORIGIN' => self::ORIGIN]);
        $response = $client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));

        $client->request('GET', '/openapi.json', server: ['HTTP_ORIGIN' => self::ORIGIN]);
        $response = $client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_same_origin_request_gets_no_allow_origin_but_vary_origin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        self::assertVaryOrigin($response);
    }

    public function test_header_injection_attempt_is_ignored(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health', server: [
            'HTTP_ORIGIN' => "http://localhost:9670\r\nX-Injected: yes",
        ]);

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    private static function assertVaryOrigin(Response $response): void
    {
        $vary = (string) $response->headers->get('Vary', '');

        self::assertSame(
            1,
            preg_match('/(^|,\s*)Origin(,|$)/i', $vary),
            "Expected 'Vary: Origin' on response, got: '{$vary}'.",
        );
    }
}
