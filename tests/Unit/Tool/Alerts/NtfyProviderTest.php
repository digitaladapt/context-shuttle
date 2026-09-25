<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Alerts;

use App\Tool\Alerts\NtfyProvider;
use App\Tool\Alerts\OutboundAlert;
use App\Tool\Alerts\Priority;
use App\Tool\Alerts\VisibleLinkFormatter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Unit tests for the ntfy provider, using a mocked HTTP client so no
 * network is needed. Exercises the provider the way a caller would: the
 * published JSON shape, auth, link rendering, error paths, and the
 * enabled-by-presence contract.
 *
 * @internal
 *
 * @covers \App\Tool\Alerts\NtfyProvider
 */
final class NtfyProviderTest extends TestCase
{
    private const PUBLISH_RESPONSE = '{"id":"xE73Iyuabi","time":1673542291,"event":"message","topic":"alerts"}';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $calls = [];

    public function test_is_enabled_only_when_topic_present(): void
    {
        self::assertFalse($this->provider(topic: '')->isEnabled());
        self::assertFalse($this->provider(topic: '   ')->isEnabled());
        self::assertTrue($this->provider(topic: 'my-topic')->isEnabled());
    }

    public function test_publishes_json_to_server_root_with_all_fields(): void
    {
        $provider = $this->provider();

        $receipt = $provider->send(new OutboundAlert(
            title: 'Deploy finished',
            body: 'Version 1.2.3 is live.',
            priority: new Priority(4),
            tags: ['rocket', 'done'],
            actionUrl: 'https://example.com/run/42',
            actionLabel: 'Open run',
        ));

        self::assertSame('ntfy', $receipt->provider);
        self::assertSame('xE73Iyuabi', $receipt->messageId);
        self::assertSame(4, $receipt->priority);

        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame('https://ntfy.sh/', $this->calls[0]['url']);

        $payload = json_decode($this->calls[0]['options']['body'], true);
        self::assertSame('my-topic', $payload['topic']);
        self::assertSame('Deploy finished', $payload['title']);
        self::assertSame(4, $payload['priority']);
        self::assertSame(['rocket', 'done'], $payload['tags']);
        self::assertSame('https://example.com/run/42', $payload['click']);
        self::assertTrue($payload['markdown']);
        self::assertSame(
            "Version 1.2.3 is live.\n\n→ **example.com**/run/42",
            $payload['message'],
        );
    }

    public function test_uses_self_hosted_url_and_bearer_token(): void
    {
        $provider = $this->provider(
            baseUrl: 'https://ntfy.internal.example.com/',
            topic: 'deploys',
            token: 'tk_secret123',
        );

        $provider->send(new OutboundAlert('x', null, new Priority(3)));

        self::assertSame('https://ntfy.internal.example.com/', $this->calls[0]['url']);
        self::assertSame('Authorization: Bearer tk_secret123', $this->calls[0]['options']['normalized_headers']['authorization'][0] ?? '');
    }

    public function test_no_authorization_header_without_token(): void
    {
        $provider = $this->provider();

        $provider->send(new OutboundAlert('x', null, new Priority(3)));

        self::assertArrayNotHasKey('authorization', $this->calls[0]['options']['normalized_headers'] ?? []);
    }

    public function test_message_is_link_line_alone_when_body_missing(): void
    {
        $provider = $this->provider();

        $provider->send(new OutboundAlert(
            title: 'x',
            body: null,
            priority: new Priority(3),
            actionUrl: 'https://example.com/run/42',
        ));

        $payload = json_decode($this->calls[0]['options']['body'], true);
        self::assertSame('→ **example.com**/run/42', $payload['message']);
    }

    public function test_no_click_or_tags_keys_when_not_provided(): void
    {
        $provider = $this->provider();

        $provider->send(new OutboundAlert('x', 'body', new Priority(3)));

        $payload = json_decode($this->calls[0]['options']['body'], true);
        self::assertArrayNotHasKey('click', $payload);
        self::assertArrayNotHasKey('tags', $payload);
    }

    public function test_delivery_succeeds_even_when_response_has_no_id(): void
    {
        $client = new MockHttpClient(new MockResponse('not json', ['http_code' => 200]));
        $provider = $this->provider(client: $client);

        $receipt = $provider->send(new OutboundAlert('x', null, new Priority(3)));

        self::assertNull($receipt->messageId);
    }

    public function test_errors_clearly_when_topic_unconfigured_on_send(): void
    {
        $provider = $this->provider(topic: '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NTFY_TOPIC is not configured');

        $provider->send(new OutboundAlert('x', null, new Priority(3)));
    }

    public function test_rejects_non_http_base_url(): void
    {
        $provider = $this->provider(baseUrl: 'ftp://ntfy.example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NTFY_URL must start with http:// or https://');

        $provider->send(new OutboundAlert('x', null, new Priority(3)));
    }

    public function test_reports_transport_failure(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });
        $provider = $this->provider(client: $client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not reach ntfy');

        $provider->send(new OutboundAlert('x', null, new Priority(3)));
    }

    public function test_reports_upstream_error_with_status_and_body(): void
    {
        $client = new MockHttpClient(new MockResponse('{"code":40001,"error":"invalid message"}', ['http_code' => 400]));
        $provider = $this->provider(client: $client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ntfy returned HTTP 400');

        $provider->send(new OutboundAlert('x', null, new Priority(3)));
    }

    private function provider(
        ?HttpClientInterface $client = null,
        string $baseUrl = 'https://ntfy.sh',
        string $topic = 'my-topic',
        string $token = '',
    ): NtfyProvider {
        if (null === $client) {
            $client = new MockHttpClient(function ($method, $url, $options) {
                $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse(self::PUBLISH_RESPONSE, ['http_code' => 200]);
            });
        }

        return new NtfyProvider($client, new VisibleLinkFormatter(), $baseUrl, $topic, $token);
    }
}
