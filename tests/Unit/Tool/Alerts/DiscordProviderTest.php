<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Alerts;

use App\Tool\Alerts\DiscordProvider;
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
 * Unit tests for the Discord webhook provider, using a mocked HTTP
 * client so no network is needed. The `allowed_mentions` guard is a
 * security-ish property (an LLM must never be able to mass-ping a
 * server): it is pinned with explicit tests here.
 *
 * @internal
 *
 * @covers \App\Tool\Alerts\DiscordProvider
 */
final class DiscordProviderTest extends TestCase
{
    private const WEBHOOK_URL = 'https://discord.com/api/webhooks/123456789/secret-token';

    private const MESSAGE_RESPONSE = '{"id":"987654321","channel_id":"111","content":""}';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $calls = [];

    public function test_is_enabled_only_when_webhook_url_present(): void
    {
        self::assertFalse($this->provider(webhookUrl: '')->isEnabled());
        self::assertFalse($this->provider(webhookUrl: '  ')->isEnabled());
        self::assertTrue($this->provider(webhookUrl: self::WEBHOOK_URL)->isEnabled());
    }

    public function test_posts_embed_with_wait_query(): void
    {
        $provider = $this->provider();

        $receipt = $provider->send(new OutboundAlert(
            title: 'Deploy finished',
            body: 'Version 1.2.3 is live.',
            priority: new Priority(4),
            tags: ['rocket', 'done'],
            actionUrl: 'https://example.com/run/42',
        ));

        self::assertSame('discord', $receipt->provider);
        self::assertSame('987654321', $receipt->messageId);

        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame(self::WEBHOOK_URL.'?wait=true', $this->calls[0]['url']);
        self::assertSame('true', $this->calls[0]['options']['query']['wait']);

        $payload = json_decode($this->calls[0]['options']['body'], true);
        $embed = $payload['embeds'][0];
        self::assertSame('[rocket] [done] Deploy finished', $embed['title']);
        self::assertSame(15105570, $embed['color']); // level 4 orange
        self::assertSame('https://example.com/run/42', $embed['url']);
        self::assertSame('context-shuttle', $embed['footer']['text']);
        self::assertSame(
            "Version 1.2.3 is live.\n\n→ **example.com**/run/42",
            $embed['description'],
        );
    }

    public function test_suppresses_all_mentions_by_default(): void
    {
        $provider = $this->provider();

        $provider->send(new OutboundAlert(
            title: '@everyone <@1234> <@&200> pwn',
            body: '@here look',
            priority: new Priority(4),
        ));

        $payload = json_decode($this->calls[0]['options']['body'], true);

        // Parse-all is the dangerous default; we always pin it shut.
        self::assertSame(['parse' => []], $payload['allowed_mentions']);
        self::assertArrayNotHasKey('content', $payload);
    }

    public function test_level_5_mentions_configured_user_only(): void
    {
        $provider = $this->provider(mentionUserId: '424242');

        $provider->send(new OutboundAlert('Urgent', null, new Priority(5)));

        $payload = json_decode($this->calls[0]['options']['body'], true);

        self::assertSame('<@424242>', $payload['content']);
        // `users` is mutually exclusive with `parse` on Discord's side —
        // the whitelist replaces the suppression rather than joining it.
        self::assertSame(['users' => ['424242']], $payload['allowed_mentions']);
    }

    public function test_level_5_without_configured_user_does_not_mention(): void
    {
        $provider = $this->provider(mentionUserId: '');

        $provider->send(new OutboundAlert('Urgent', null, new Priority(5)));

        $payload = json_decode($this->calls[0]['options']['body'], true);

        self::assertArrayNotHasKey('content', $payload);
        self::assertSame(['parse' => []], $payload['allowed_mentions']);
    }

    public function test_lower_priorities_never_mention_even_with_user_configured(): void
    {
        $provider = $this->provider(mentionUserId: '424242');

        $levels = [1, 2, 3, 4];
        foreach ($levels as $level) {
            $provider->send(new OutboundAlert('x', null, new Priority($level)));
        }

        self::assertCount(\count($levels), $this->calls);

        foreach ($levels as $index => $level) {
            $payload = json_decode($this->calls[$index]['options']['body'], true);
            self::assertArrayNotHasKey('content', $payload, "Level {$level} must not mention.");
            self::assertSame(['parse' => []], $payload['allowed_mentions']);
        }
    }

    public function test_embedded_title_is_truncated_to_discord_limit(): void
    {
        $provider = $this->provider();

        $provider->send(new OutboundAlert(str_repeat('x', 300), null, new Priority(3)));

        $payload = json_decode($this->calls[0]['options']['body'], true);
        self::assertSame(256, mb_strlen($payload['embeds'][0]['title']));
    }

    public function test_omits_description_when_body_and_link_absent(): void
    {
        $provider = $this->provider();

        $provider->send(new OutboundAlert('x', null, new Priority(3)));

        $payload = json_decode($this->calls[0]['options']['body'], true);
        self::assertArrayNotHasKey('description', $payload['embeds'][0]);
        self::assertArrayNotHasKey('url', $payload['embeds'][0]);
    }

    public function test_errors_clearly_when_webhook_unconfigured_on_send(): void
    {
        $provider = $this->provider(webhookUrl: '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DISCORD_WEBHOOK_URL is not configured');

        $provider->send(new OutboundAlert('x', null, new Priority(3)));
    }

    public function test_transport_failure_does_not_leak_webhook_url(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });
        $provider = $this->provider(client: $client);

        try {
            $provider->send(new OutboundAlert('x', null, new Priority(3)));
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Could not reach the Discord webhook', $e->getMessage());
            // The webhook URL embeds a token; it must never appear in errors.
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function test_reports_upstream_error_message(): void
    {
        $client = new MockHttpClient(new MockResponse('{"message":"Invalid Webhook Token","code":50027}', ['http_code' => 401]));
        $provider = $this->provider(client: $client);

        try {
            $provider->send(new OutboundAlert('x', null, new Priority(3)));
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringContainsString('Invalid Webhook Token', $e->getMessage());
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function test_delivery_succeeds_even_when_response_has_no_id(): void
    {
        $client = new MockHttpClient(new MockResponse('not json', ['http_code' => 204]));
        $provider = $this->provider(client: $client);

        $receipt = $provider->send(new OutboundAlert('x', null, new Priority(3)));

        self::assertNull($receipt->messageId);
    }

    private function provider(
        ?HttpClientInterface $client = null,
        string $webhookUrl = self::WEBHOOK_URL,
        string $mentionUserId = '',
    ): DiscordProvider {
        if (null === $client) {
            $client = new MockHttpClient(function ($method, $url, $options) {
                $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse(self::MESSAGE_RESPONSE, ['http_code' => 200]);
            });
        }

        return new DiscordProvider($client, new VisibleLinkFormatter(), $webhookUrl, $mentionUserId);
    }
}
