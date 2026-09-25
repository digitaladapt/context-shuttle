<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Override;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The write tools' visibility, through the **whole kernel**.
 *
 * The unit tests prove the gate's logic; this proves the wiring, which is
 * where the design's "not registered at all" rule can quietly stop being true.
 * A gate that is correct but not connected to the registry would leave the
 * write tools listed on every deployment, and only a test that boots the
 * application and reads `tools/list` would notice.
 *
 * The variable is set on the *process* environment before the kernel boots,
 * because that is where an env-backed container parameter is resolved — and
 * because the runtime-not-compile-time question the design cares about is only
 * answered by actually booting.
 *
 * @internal
 *
 * @coversNothing
 */
final class CalendarWriteGatingTest extends WebTestCase
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
    private function toolNames(KernelInterface $kernel): array
    {
        $container = self::getContainer();

        // The registry is private by design, so it is reached through the
        // container's test service container rather than made public for a
        // test's convenience.
        /** @var \App\ToolRegistry\ToolRegistry $registry */
        $registry = $container->get(\App\ToolRegistry\ToolRegistry::class);

        return $registry->names();
    }

    public function test_without_a_designate_no_write_tool_is_listed(): void
    {
        $this->withEnv('CALDAV_EDITABLE_CALENDAR', '');

        self::bootKernel();
        $names = $this->toolNames(self::getContainer()->get('kernel'));

        foreach (['calendar_create_event', 'calendar_update_event', 'calendar_delete_event'] as $write) {
            self::assertNotContains($write, $names, 'a deployment with no writable calendar must not list write tools');
        }

        // The read tools are unaffected: this is a narrowing, not a disable.
        self::assertContains('calendar_list_events', $names);
    }

    public function test_with_a_designate_the_write_tools_are_listed(): void
    {
        $this->withEnv('CALDAV_EDITABLE_CALENDAR', 'work');

        self::bootKernel();
        $names = $this->toolNames(self::getContainer()->get('kernel'));

        foreach (['calendar_create_event', 'calendar_update_event', 'calendar_delete_event'] as $write) {
            self::assertContains($write, $names);
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
        $this->withEnv('CALDAV_EDITABLE_CALENDAR', '');

        $client = self::createClient();
        $client->request('GET', '/tools');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $names = array_column($payload['tools'], 'name');

        self::assertNotContains('calendar_create_event', $names);
        self::assertContains('calendar_list_events', $names);
    }

    public function test_ready_reports_the_served_count(): void
    {
        // The count is how an operator sees the effect of enabling writes
        // without reading anything else.
        $this->withEnv('CALDAV_EDITABLE_CALENDAR', '');
        $client = self::createClient();
        $client->request('GET', '/ready');
        $without = json_decode((string) $client->getResponse()->getContent(), true)['tools'];

        $this->withEnv('CALDAV_EDITABLE_CALENDAR', 'work');
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('GET', '/ready');
        $with = json_decode((string) $client->getResponse()->getContent(), true)['tools'];

        self::assertSame(3, $with - $without, 'exactly the three write tools are added');
    }

    public function test_an_unavailable_write_tool_is_not_found_over_rest(): void
    {
        $this->withEnv('CALDAV_EDITABLE_CALENDAR', '');

        $client = self::createClient();
        $client->request(
            'POST',
            '/tools/calendar_create_event',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['summary' => 'x', 'start' => '2026-12-01 09:00']),
        );

        // The same answer an invented name would get — the deployment does not
        // have this tool, and saying anything else would leak its existence.
        self::assertResponseStatusCodeSame(404);
    }
}
