<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The calendar tool through the real HTTP pipeline.
 *
 * Mirrors the get_transactions integration tests: a tool that is listed must
 * also be invocable, and invoking it unconfigured must produce a clear
 * message rather than a container error.
 *
 * @internal
 */
final class CalendarPipelineTest extends WebTestCase
{
    public function test_the_tool_is_listed_with_its_schema(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tools');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $names = array_column($payload['tools'], 'name');

        self::assertContains('calendar_list_events', $names);

        $tool = null;

        foreach ($payload['tools'] as $candidate) {
            if ('calendar_list_events' === $candidate['name']) {
                $tool = $candidate;
            }
        }

        self::assertNotNull($tool);

        // The parameters a caller needs, with the documented default.
        self::assertArrayHasKey('from', $tool['parameters']);
        self::assertArrayHasKey('to', $tool['parameters']);
        self::assertArrayHasKey('calendar', $tool['parameters']);
        self::assertArrayHasKey('search', $tool['parameters']);
        self::assertArrayHasKey('limit', $tool['parameters']);
        self::assertArrayHasKey('cursor', $tool['parameters']);
    }

    public function test_invoking_it_unconfigured_names_the_env_var(): void
    {
        $client = static::createClient();

        // The test environment has no calendar server configured, which is a
        // valid deployment: the tool must simply not be usable.
        $client->request(
            'POST',
            '/tools/calendar_list_events',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['from' => '2026-10-01', 'to' => '2026-10-31'], \JSON_THROW_ON_ERROR),
        );

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('CALDAV_URL', $body);
        // Never a container-level failure.
        self::assertStringNotContainsString('TypeError', $body);
    }

    public function test_rejects_a_malformed_date(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/tools/calendar_list_events',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['from' => '2026-10-01', 'to' => '99/99/2026'], \JSON_THROW_ON_ERROR),
        );

        // The declared `pattern` rejects this before the handler runs, which
        // is the right layer for it: the schema is the first gate, and the
        // handler's own check is the belt to its braces.
        self::assertStringContainsString('pattern', (string) $client->getResponse()->getContent());
    }

    public function test_rejects_a_date_that_does_not_exist(): void
    {
        $client = static::createClient();

        // Well-formed but not a real day: it must be rejected rather than
        // rolled into March.
        $client->request(
            'POST',
            '/tools/calendar_list_events',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['from' => '2026-02-30', 'to' => '2026-03-01'], \JSON_THROW_ON_ERROR),
        );

        self::assertStringContainsString('valid calendar dates', (string) $client->getResponse()->getContent());
    }

    public function test_a_missing_tool_is_a_404(): void
    {
        $client = static::createClient();
        $client->request('POST', '/tools/calendar_reschedule_everything');

        self::assertResponseStatusCodeSame(404);
    }
}
