<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\HttpFoundation\Response;

use function getenv;
use function json_decode;
use function preg_match;
use function putenv;
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
 * Every response additionally carries a Cross-Origin-Resource-Policy:
 * "no-cors" subresource loads (images, scripts, fonts) never send an
 * Origin header, so the policy is applied even when the credentialed-CORS
 * branch is skipped. CORS_RESOURCE_POLICY selects the value; unset or
 * empty means "same-site".
 *
 * @internal
 *
 * @coversNothing
 */
final class CorsTest extends WebTestCase
{
    private const ORIGIN = 'http://localhost:9670';

    private ?string $previousResourcePolicy = null;

    protected function setUp(): void
    {
        $this->previousResourcePolicy = false === ($policy = getenv('CORS_RESOURCE_POLICY')) ? null : $policy;
    }

    protected function tearDown(): void
    {
        self::setResourcePolicy($this->previousResourcePolicy);

        parent::tearDown();
    }

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

    /**
     * The MCP TypeScript SDK (llama.cpp's web UI, MCP Inspector) sends
     * mcp-protocol-version on every request after initialize, as the spec's
     * "Protocol Version Header" section requires. A header that is missing
     * from Access-Control-Allow-Headers fails the preflight in the browser
     * with "CORS Missing Allow Header" even though the server answers 204.
     *
     * @see https://modelcontextprotocol.io/specification/2025-06-18/basic/transports#protocol-version-header
     */
    public function test_preflight_allows_every_header_a_spec_compliant_mcp_client_sends(): void
    {
        $client = self::createClient();
        $client->request('OPTIONS', '/mcp', server: [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            // Exactly what @modelcontextprotocol/sdk sends: content-type,
            // accept, authorization (when configured), mcp-session-id (once
            // initialized) and mcp-protocol-version (always, post-initialize).
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, accept, authorization, mcp-session-id, mcp-protocol-version',
        ]);

        $response = $client->getResponse();
        $allowHeaders = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));

        self::assertTrue($response->isSuccessful());
        // Accept and Content-Type are CORS-safelisted; the rest must be
        // advertised or the browser blocks the actual request.
        foreach (['authorization', 'mcp-session-id', 'mcp-protocol-version'] as $header) {
            self::assertStringContainsString(
                $header,
                $allowHeaders,
                "Access-Control-Allow-Headers must list '{$header}', got: '{$allowHeaders}'.",
            );
        }
    }

    /**
     * Guards against the SDK's version negotiation changing shape: the
     * transport starts sending mcp-protocol-version only after initialize, so
     * both the first preflight and every later one must be allowed.
     */
    public function test_later_preflight_carries_mcp_protocol_version(): void
    {
        $client = self::createClient();
        $client->request('OPTIONS', '/mcp', server: [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, mcp-protocol-version',
        ]);

        $response = $client->getResponse();

        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString(
            'mcp-protocol-version',
            strtolower((string) $response->headers->get('Access-Control-Allow-Headers')),
        );
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
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-03-26',
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

    public function test_cross_origin_resource_policy_defaults_to_same_site(): void
    {
        self::setResourcePolicy(null);

        $client = self::createClient();
        $client->request('GET', '/health', server: ['HTTP_ORIGIN' => self::ORIGIN]);

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertSame('same-site', $response->headers->get('Cross-Origin-Resource-Policy'));
    }

    public function test_cross_origin_resource_policy_is_set_without_origin_header(): void
    {
        // "no-cors" subresource loads (<img>, <script>, <link rel=preload>)
        // never send an Origin header, so the policy must be applied even
        // though the credentialed-CORS branch is skipped.
        self::setResourcePolicy(null);

        $client = self::createClient();
        $client->request('GET', '/tools');

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('same-site', $response->headers->get('Cross-Origin-Resource-Policy'));
        self::assertVaryOrigin($response);
    }

    public function test_empty_cors_resource_policy_falls_back_to_same_site(): void
    {
        self::setResourcePolicy('');

        $client = self::createClient();
        $client->request('GET', '/health', server: ['HTTP_ORIGIN' => self::ORIGIN]);

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertSame('same-site', $response->headers->get('Cross-Origin-Resource-Policy'));
    }

    public function test_cors_resource_policy_can_be_set_to_same_origin(): void
    {
        self::setResourcePolicy('same-origin');

        $client = self::createClient();
        $client->request('GET', '/health', server: ['HTTP_ORIGIN' => self::ORIGIN]);

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
    }

    public function test_cors_resource_policy_can_be_set_to_cross_origin(): void
    {
        self::setResourcePolicy('cross-origin');

        $client = self::createClient();
        $client->request('GET', '/health', server: ['HTTP_ORIGIN' => self::ORIGIN]);

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertSame('cross-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
    }

    public function test_invalid_cors_resource_policy_fails_loudly(): void
    {
        self::setResourcePolicy('cross-site');

        self::expectException(RuntimeException::class);
        self::expectExceptionMessageMatches('/CrossOriginResourcePolicy/');

        $client = self::createClient();
        $client->request('GET', '/health');
    }

    public function test_preflight_carries_cross_origin_resource_policy(): void
    {
        self::setResourcePolicy(null);

        $client = self::createClient();
        $client->request('OPTIONS', '/mcp', server: [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response = $client->getResponse();

        self::assertTrue($response->isSuccessful());
        self::assertSame('same-site', $response->headers->get('Cross-Origin-Resource-Policy'));
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

    /**
     * Sets CORS_RESOURCE_POLICY for the next kernel boot. All three channels
     * matter: Symfony checks $_ENV, then $_SERVER, then getenv().
     */
    private static function setResourcePolicy(?string $value): void
    {
        if (null === $value) {
            putenv('CORS_RESOURCE_POLICY');
            unset($_ENV['CORS_RESOURCE_POLICY'], $_SERVER['CORS_RESOURCE_POLICY']);

            return;
        }

        putenv('CORS_RESOURCE_POLICY='.$value);
        $_ENV['CORS_RESOURCE_POLICY'] = $value;
        $_SERVER['CORS_RESOURCE_POLICY'] = $value;
    }
}
