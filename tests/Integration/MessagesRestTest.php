<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Override;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The messages REST surface, through the whole kernel.
 *
 * The unit tests prove the store and the tool; this proves the wiring and
 * the interop the design depends on: a cursor obtained from `POST
 * /messages` pages in `get_messages` and vice versa, because both sit on
 * one store with one id space. A drift there would strand a resident's
 * re-read at a boundary it cannot see past.
 *
 * Each test points MESSAGES_DB at a throwaway file, so nothing touches the
 * deployment's own store and no state crosses tests.
 *
 * @internal
 *
 * @coversNothing
 */
final class MessagesRestTest extends WebTestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];
    private string $storePath = '';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->storePath = tempnam(sys_get_temp_dir(), 'resident-messages-rest-').'.sqlite';
        $this->withEnv('MESSAGES_DB', $this->storePath);
    }

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

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->storePath.$suffix);
        }

        parent::tearDown();
    }

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

    public function test_post_stores_a_message(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body' => 'Hello from REST.', 'sender' => 'andrew']),
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertTrue($payload['stored']);
        self::assertSame('andrew', $payload['sender']);
        self::assertSame('default', $payload['conversation']);
        self::assertFalse($payload['truncated']);
    }

    public function test_post_defaults_the_sender_to_the_operator(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body' => 'hello']),
        );

        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('operator', $payload['sender']);
    }

    public function test_post_without_a_body_is_refused(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['sender' => 'andrew']),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_blank_body_is_refused(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body' => '   ']),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_non_json_body_is_refused(): void
    {
        $client = self::createClient();
        $client->request('POST', '/messages', content: 'not json');

        self::assertResponseStatusCodeSame(422);
    }

    public function test_an_invalid_conversation_name_is_refused(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body' => 'x', 'conversation' => 'Not A Name']),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function test_get_lists_oldest_first_and_reports_the_cursor(): void
    {
        $client = self::createClient();

        foreach (['first', 'second'] as $body) {
            $client->request(
                'POST',
                '/messages',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['body' => $body]),
            );
        }

        $client->request('GET', '/messages');
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(2, $payload['count']);
        self::assertSame('first', $payload['messages'][0]['body']);
        self::assertSame('second', $payload['messages'][1]['body']);
        self::assertSame(2, $payload['newest_id']);
        self::assertFalse($payload['more_available']);
    }

    public function test_get_pages_by_cursor(): void
    {
        $client = self::createClient();

        foreach (['one', 'two', 'three'] as $body) {
            $client->request(
                'POST',
                '/messages',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['body' => $body]),
            );
        }

        $client->request('GET', '/messages?since=1&limit=1');
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(1, $payload['count']);
        self::assertSame('two', $payload['messages'][0]['body']);
        self::assertSame(2, $payload['newest_id']);
        self::assertTrue($payload['more_available']);
    }

    public function test_the_tools_and_rest_share_one_id_space(): void
    {
        // The design note's interop requirement: a cursor from the REST
        // surface pages in the tool, and a tool send is visible to REST.
        $client = self::createClient();
        $client->request(
            'POST',
            '/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body' => 'from rest']),
        );
        $restId = json_decode((string) $client->getResponse()->getContent(), true)['id'];

        $client->request(
            'POST',
            '/tools/send_message',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body' => 'from tool']),
        );
        $toolId = json_decode((string) $client->getResponse()->getContent(), true)['result']['id'];

        self::assertSame($restId + 1, $toolId, 'one store, one id space');

        $client->request('GET', "/messages?since={$restId}");
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('from tool', $payload['messages'][0]['body']);
    }

    public function test_the_message_tools_are_always_listed(): void
    {
        // No gate: the store is the gateway's own, so the family exists on
        // every deployment regardless of configuration. If a future change
        // hides these tools behind a variable, this test is what notices.
        $client = self::createClient();
        $client->request('GET', '/tools');

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $names = array_column($payload['tools'], 'name');

        self::assertContains('send_message', $names);
        self::assertContains('get_messages', $names);
    }
}
