<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tool\Alerts\AlertDispatcher;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage for the alerts pipeline through the real kernel:
 * tool listing on both surfaces, presence-based provider detection, and
 * the failure semantics over HTTP (nothing configured vs everything
 * failed — the distinction the design insists on).
 *
 * Failure cases use a refused TCP port (http://127.0.0.1:1) rather
 * than a mock: no network, no server, deterministic.
 *
 * @internal
 *
 * @coversNothing
 */
final class AlertsPipelineTest extends WebTestCase
{
    /** @var array<string, string|false> */
    private array $previousEnv = [];

    #[Override]
    protected function setUp(): void
    {
        foreach (['NTFY_TOPIC', 'NTFY_URL', 'DISCORD_WEBHOOK_URL'] as $name) {
            $this->previousEnv[$name] = getenv($name);
        }

        self::setEnv('NTFY_TOPIC', null);
        self::setEnv('NTFY_URL', null);
        self::setEnv('DISCORD_WEBHOOK_URL', null);
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            self::setEnv($name, false === $value ? null : $value);
        }

        parent::tearDown();
    }

    public function test_tool_listing_includes_send_alert(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $names = array_column($data['tools'], 'name');
        self::assertContains('send_alert', $names);

        $tool = $data['tools'][array_search('send_alert', $names, true)];
        self::assertArrayHasKey('title', $tool['parameters']);
        self::assertArrayHasKey('link', $tool['parameters']);
        self::assertTrue($tool['parameters']['title']['required']);
    }

    public function test_openapi_document_exposes_send_alert(): void
    {
        $client = self::createClient();
        $client->request('GET', '/openapi.json');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('/tools/send_alert', $data['paths']);
    }

    public function test_mcp_tools_list_includes_send_alert(): void
    {
        $client = self::createClient();
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: '{"jsonrpc":"2.0","id":2,"method":"tools/list"}');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $names = array_column($data['result']['tools'], 'name');
        self::assertContains('send_alert', $names);
    }

    public function test_no_providers_configured_is_the_clear_unconfigured_error(): void
    {
        $client = self::createClient();
        $client->request('POST', '/tools/send_alert', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"title":"hello"}');

        // A throw from the handler surfaces as 422 with the message.
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('No alert provider is configured', $data['error']);
        self::assertStringContainsString('NTFY_TOPIC', $data['error']);
    }

    public function test_all_providers_failing_is_an_error_with_the_failure_report(): void
    {
        // Well-formed URL on a port nothing listens on: connections are
        // refused immediately, deterministically, without network access.
        self::setEnv('NTFY_TOPIC', 'demo-topic');
        self::setEnv('NTFY_URL', 'http://127.0.0.1:1');

        $client = self::createClient();
        $client->request('POST', '/tools/send_alert', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"title":"hello"}');

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('not delivered on any enabled provider', $data['error']);
        self::assertStringContainsString('ntfy:', $data['error']);
    }

    public function test_provider_detection_is_presence_based(): void
    {
        // Nothing configured.
        self::assertSame([], $this->enabledProviders());

        // Topic only -> ntfy.
        self::setEnv('NTFY_TOPIC', 'demo-topic');
        self::assertSame(['ntfy'], $this->enabledProviders());

        // Topic + webhook -> both.
        self::setEnv('DISCORD_WEBHOOK_URL', 'https://discord.com/api/webhooks/1/x');
        $both = $this->enabledProviders();
        sort($both);
        self::assertSame(['discord', 'ntfy'], $both);

        // Webhook only -> discord.
        self::setEnv('NTFY_TOPIC', null);
        self::assertSame(['discord'], $this->enabledProviders());
    }

    /**
     * Boots the test kernel and asks the real dispatcher which providers
     * its presence check considers enabled.
     *
     * @return list<string>
     */
    private function enabledProviders(): array
    {
        self::ensureKernelShutdown();
        $kernel = self::bootKernel();
        $dispatcher = self::getContainer()->get(AlertDispatcher::class);

        return $dispatcher->enabledProviders();
    }

    /**
     * Sets an env var for the next kernel boot. All three channels
     * matter: Symfony checks $_ENV, then $_SERVER, then getenv().
     */
    private static function setEnv(string $name, ?string $value): void
    {
        if (null === $value) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
