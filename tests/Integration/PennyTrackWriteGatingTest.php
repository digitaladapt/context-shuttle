<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Override;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The transaction-creation tool's visibility, through the **whole kernel**.
 *
 * The unit tests prove the gate's logic; this proves the wiring, which is where
 * the design's "not registered at all" rule can quietly stop being true. A gate
 * that is correct but not connected to the registry would leave
 * `create_transaction` listed on every deployment — including one holding
 * nothing but a read-only key — and only a test that boots the application and
 * reads `tools/list` would notice.
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
final class PennyTrackWriteGatingTest extends WebTestCase
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
     * A deployment pointed at a ledger, holding a read-only key.
     */
    private function withReadOnlyLedger(): void
    {
        $this->withEnv('PENNYTRACK_URL', 'https://penny.example.com');
        $this->withEnv('PENNYTRACK_API_KEY', 'read-only-key');
        $this->withEnv('PENNYTRACK_WRITE_API_KEY', '');
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

    public function test_a_read_only_ledger_has_no_create_tool(): void
    {
        $this->withReadOnlyLedger();

        self::bootKernel();

        self::assertNotContains(
            'create_transaction',
            $this->toolNames(),
            'a deployment with no write key must not offer a mutation verb',
        );

        // The read tool is unaffected: this is a narrowing, not a disable. That
        // divergence is the whole point of the two being gated differently.
        self::assertContains('get_transactions', $this->toolNames());
    }

    public function test_naming_a_write_key_registers_the_create_tool(): void
    {
        $this->withReadOnlyLedger();
        $this->withEnv('PENNYTRACK_WRITE_API_KEY', 'full-access-key');

        self::bootKernel();

        self::assertContains('create_transaction', $this->toolNames());
    }

    /**
     * And through the actual HTTP surface a client would use.
     *
     * The registry feeds four surfaces (`/tools`, `/mcp`, `/openapi.json`,
     * `/ready`); this checks the three a caller reads most directly.
     */
    public function test_the_rest_surface_agrees_with_the_registry(): void
    {
        $this->withReadOnlyLedger();

        $client = self::createClient();
        $client->request('GET', '/tools');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $names = array_column($payload['tools'], 'name');

        self::assertNotContains('create_transaction', $names);
        self::assertContains('get_transactions', $names);
    }

    public function test_the_create_tool_is_absent_from_the_openapi_document_without_a_write_key(): void
    {
        $this->withReadOnlyLedger();

        $client = self::createClient();
        $client->request('GET', '/openapi.json');

        self::assertResponseIsSuccessful();
        $paths = json_decode((string) $client->getResponse()->getContent(), true)['paths'];

        self::assertArrayNotHasKey('/tools/create_transaction', $paths);
        self::assertArrayHasKey('/tools/get_transactions', $paths);
    }

    public function test_ready_reports_the_served_count(): void
    {
        // The count is how an operator sees the effect of enabling writes
        // without reading anything else.
        $this->withReadOnlyLedger();

        $client = self::createClient();
        $client->request('GET', '/ready');
        $without = json_decode((string) $client->getResponse()->getContent(), true)['tools'];

        $this->withEnv('PENNYTRACK_WRITE_API_KEY', 'full-access-key');
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('GET', '/ready');
        $with = json_decode((string) $client->getResponse()->getContent(), true)['tools'];

        self::assertSame(1, $with - $without, 'exactly the create tool is added');
    }

    public function test_an_unavailable_create_tool_is_not_found_over_rest(): void
    {
        $this->withReadOnlyLedger();

        $client = self::createClient();
        $client->request(
            'POST',
            '/tools/create_transaction',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['amount' => 2.13, 'date' => '2026-10-02', 'business' => 'Backblaze', 'category' => 'Software']),
        );

        // The same answer an invented name would get — the deployment does not
        // have this tool, and saying anything else would leak its existence.
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * With writes enabled, the tool's own refusals reach the caller in words.
     *
     * This is the contract every tool here honours: an unreachable upstream
     * produces the tool's message rather than a generic failure. The URL is
     * guaranteed not to resolve, so the failure happens inside the tool.
     */
    public function test_a_named_but_unreachable_ledger_reports_a_clear_message(): void
    {
        $this->withReadOnlyLedger();
        $this->withEnv('PENNYTRACK_WRITE_API_KEY', 'full-access-key');
        $this->withEnv('PENNYTRACK_URL', 'https://penny.invalid');

        $client = self::createClient();
        $client->request(
            'POST',
            '/tools/create_transaction',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['amount' => 2.13, 'date' => '2026-10-02', 'business' => 'Backblaze', 'category' => 'Software']),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Could not reach penny-track', (string) $client->getResponse()->getContent());
    }

    /**
     * The schema is published, so a client can see the shape before calling —
     * and the required fields are the four that a receipt always has.
     */
    public function test_the_published_schema_requires_the_four_receipt_fields(): void
    {
        $this->withReadOnlyLedger();
        $this->withEnv('PENNYTRACK_WRITE_API_KEY', 'full-access-key');

        $client = self::createClient();
        $client->request('GET', '/openapi.json');

        $schemas = json_decode((string) $client->getResponse()->getContent(), true)['components']['schemas'];
        $schema = $schemas['CreateTransactionInput'];

        self::assertSame(['amount', 'date', 'business', 'category'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
    }

    public function test_the_pipeline_rejects_an_argument_the_schema_forbids(): void
    {
        $this->withReadOnlyLedger();
        $this->withEnv('PENNYTRACK_WRITE_API_KEY', 'full-access-key');

        $client = self::createClient();
        $client->request(
            'POST',
            '/tools/create_transaction',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['amount' => 2.13, 'date' => '2026-10-02', 'business' => 'Backblaze', 'category' => 'Software', 'vendor' => 'x']),
        );

        self::assertResponseStatusCodeSame(422);
    }
}
