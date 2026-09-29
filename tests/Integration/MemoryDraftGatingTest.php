<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Override;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The memory tools' visibility, through the **whole kernel**.
 *
 * The unit tests prove the gate's logic; this proves the wiring, which is
 * where the design's "not registered at all" rule can quietly stop being
 * true. A gate that is correct but not connected to the registry would leave
 * the memory tools listed on every deployment, and only a test that boots
 * the application and reads `tools/list` would notice.
 *
 * The variable is set on the *process* environment before the kernel boots,
 * because that is where an env-backed container parameter is resolved — and
 * because the runtime-not-compile-time question is only answered by actually
 * booting.
 *
 * @internal
 *
 * @coversNothing
 */
final class MemoryDraftGatingTest extends WebTestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            if (false === $value) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            putenv($name.'='.$value);
            $_ENV[$name] = $value;
        }

        $this->saved = [];

        parent::tearDown();
    }

    /**
     * Set an environment variable and forget the booted kernels, so the next
     * `createClient()` boots with the new value.
     */
    private function withEnv(string $name, string $value): void
    {
        if (!\array_key_exists($name, $this->saved)) {
            $current = getenv($name);
            $this->saved[$name] = false === $current ? false : $current;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;

        self::ensureKernelShutdown();
    }

    /**
     * @return list<string>
     */
    private function toolNames(): array
    {
        $container = self::getContainer();

        // The registry is private by design, so it is reached through the
        // container's test service container rather than made public for a
        // test's convenience.
        /** @var \App\ToolRegistry\ToolRegistry $registry */
        $registry = $container->get(\App\ToolRegistry\ToolRegistry::class);

        return $registry->names();
    }

    public function test_without_a_store_no_memory_tool_is_listed(): void
    {
        $this->withEnv('MEMORY_DRAFT_URL', '');

        self::bootKernel();

        foreach ($this->toolNames() as $name) {
            self::assertStringNotContainsString('memory_', $name);
        }

        // The rest of the surface is unaffected: this is a narrowing, not a
        // disable.
        self::assertContains('echo', $this->toolNames());
    }

    public function test_with_a_store_the_memory_tools_are_listed(): void
    {
        $this->withEnv('MEMORY_DRAFT_URL', 'https://memory.example.com');

        self::bootKernel();
        $names = $this->toolNames();

        foreach (['memory_recall', 'memory_remember', 'memory_keys', 'memory_stats', 'memory_forget'] as $tool) {
            self::assertContains($tool, $names);
        }
    }

    /**
     * And through the actual HTTP surface a client would use.
     *
     * The registry feeds four surfaces (`/tools`, `/mcp`, `/openapi.json`,
     * `/ready`); this checks the two a caller reads most directly.
     */
    public function test_the_rest_surface_agrees_with_the_registry(): void
    {
        $this->withEnv('MEMORY_DRAFT_URL', '');

        $client = self::createClient();
        $client->request('GET', '/tools');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $names = array_column($payload['tools'], 'name');

        self::assertNotContains('memory_recall', $names);
        self::assertContains('echo', $names);
    }

    public function test_ready_reports_the_served_count(): void
    {
        // The count is how an operator sees the effect of naming a store
        // without reading anything else.
        $this->withEnv('MEMORY_DRAFT_URL', '');
        $client = self::createClient();
        $client->request('GET', '/ready');
        $without = json_decode((string) $client->getResponse()->getContent(), true)['tools'];

        $this->withEnv('MEMORY_DRAFT_URL', 'https://memory.example.com');
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('GET', '/ready');
        $with = json_decode((string) $client->getResponse()->getContent(), true)['tools'];

        self::assertSame(5, $with - $without, 'exactly the five memory tools are added');
    }

    public function test_an_unavailable_memory_tool_is_not_found_over_rest(): void
    {
        $this->withEnv('MEMORY_DRAFT_URL', '');

        $client = self::createClient();
        $client->request(
            'POST',
            '/tools/memory_recall',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['keys' => ['x']]),
        );

        // The same answer an invented name would get — the deployment does
        // not have this tool, and saying anything else would leak its
        // existence.
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_memory_tools_are_absent_from_the_openapi_document_without_a_store(): void
    {
        $this->withEnv('MEMORY_DRAFT_URL', '');

        $client = self::createClient();
        $client->request('GET', '/openapi.json');

        self::assertResponseIsSuccessful();
        $paths = json_decode((string) $client->getResponse()->getContent(), true)['paths'];

        self::assertArrayNotHasKey('/tools/memory_recall', $paths);
        self::assertArrayNotHasKey('/tools/memory_forget', $paths);
    }

    public function test_an_unreachable_store_is_a_clear_message_not_a_500(): void
    {
        // The contract every tool here honours: a named-but-unreachable
        // upstream produces the tool's own message. This URL is guaranteed
        // not to resolve — the point is that the failure is reported in
        // words naming the variable, and it happens inside the tool rather
        // than in the container.
        $this->withEnv('MEMORY_DRAFT_URL', 'https://memory.invalid');

        $client = self::createClient();
        $client->request(
            'POST',
            '/tools/memory_recall',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['keys' => ['x']]),
        );

        self::assertResponseStatusCodeSame(422);
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Could not reach memory-draft', $body);
    }

    public function test_a_schema_violation_is_rejected_by_the_pipeline(): void
    {
        $this->withEnv('MEMORY_DRAFT_URL', 'https://memory.invalid');

        $client = self::createClient();
        $client->request(
            'POST',
            '/tools/memory_remember',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['key' => 'x', 'sentences' => 'y', 'mode' => 'upsert']),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('mode', (string) $client->getResponse()->getContent());
    }
}
