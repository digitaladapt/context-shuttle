<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The streaming example, end to end through the kernel.
 *
 * The unit tests prove the tool emits the right notifications and waits the
 * right amount; these prove the two transports then do different, correct
 * things with them — which is the part that was broken. A tool that notifies
 * used to take the REST endpoint down with it, so the regression is asserted
 * first and hardest: REST must return the whole answer, promptly, as JSON.
 *
 * @internal
 *
 * @coversNothing
 */
final class CountStreamingTest extends WebTestCase
{
    use McpSessionTrait;

    /**
     * @param array<string, mixed> $payload
     */
    private function callRest(KernelBrowser $client, array $payload): string
    {
        $client->request(
            'POST',
            '/tools/count',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        return (string) $client->getResponse()->getContent();
    }

    /**
     * The bug this change fixes.
     *
     * A notifying tool used to make `POST /tools/{name}` return 500, because the
     * response was an event stream and the controller coerced it to a string —
     * running the callback, echoing frames into output, and then failing to send
     * its own headers. Now the tool is told not to stream and the caller gets one
     * JSON body.
     */
    public function test_rest_returns_the_whole_result_for_a_notifying_tool(): void
    {
        $client = self::createClient();

        $started = microtime(true);
        $body = $this->callRest($client, ['count' => 5, 'notify' => true]);
        $elapsed = microtime(true) - $started;

        self::assertResponseIsSuccessful();

        $data = json_decode($body, true);
        self::assertIsArray($data);
        self::assertSame('count', $data['tool']);
        self::assertSame([1, 2, 3, 4, 5], $data['result']['lines']);

        // The tool reports that it did not stream, which is how a caller can
        // tell the transport chose the buffered path rather than merely
        // happening to finish quickly.
        self::assertFalse($data['result']['streamed']);

        // Nothing to watch, so nothing to wait for: five lines must not cost
        // five seconds here. Generous bound — this is a proof of a difference
        // in order of magnitude, not a benchmark.
        self::assertLessThan(2.0, $elapsed, 'REST must not wait out the streaming cadence.');
    }

    public function test_rest_honours_the_count_argument_and_defaults(): void
    {
        $client = self::createClient();

        $data = json_decode($this->callRest($client, ['count' => 2]), true);
        self::assertResponseIsSuccessful();
        self::assertSame([1, 2], $data['result']['lines']);

        // Omitted entirely: the YAML default of 5 applies, as it does for any
        // tool, because REST fills defaults from the same declaration.
        $data = json_decode($this->callRest($client, []), true);
        self::assertResponseIsSuccessful();
        self::assertSame([1, 2, 3, 4, 5], $data['result']['lines']);
    }

    /**
     * The range is the schema's, not the handler's: an out-of-range count is
     * refused before the tool runs, with the same 422 any bad argument gets.
     */
    public function test_rest_refuses_a_count_outside_the_declared_range(): void
    {
        $client = self::createClient();

        $this->callRest($client, ['count' => 21]);
        self::assertResponseStatusCodeSame(422);

        $this->callRest($client, ['count' => 0]);
        self::assertResponseStatusCodeSame(422);
    }

    public function test_the_tool_is_listed_with_its_schema(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        $names = array_column($data['tools'], 'name');

        self::assertContains('count', $names);

        $count = $data['tools'][array_search('count', $names, true)];
        self::assertSame(5, $count['parameters']['count']['default']);
        self::assertFalse($count['parameters']['notify']['default']);
    }

    /**
     * MCP answers a notifying tool with an event stream rather than a body.
     *
     * This is the observable difference between the two transports, and it is
     * the one this level can honestly assert: the transport picks SSE only when
     * the tool suspended on a notification, so the content type is the proof
     * that streaming happened.
     *
     * The *frames* are not asserted here. A `StreamedResponse` in the test
     * kernel is never sent, so its callback — the SDK's fiber loop — does not
     * run and the body stays empty; draining it from the test would be an
     * assertion about the test harness, not about the server. What goes on the
     * wire, in order, is asserted at the fiber level in `CountToolTest`, and
     * end to end against a live server in
     * `docs/examples/streaming-count-tool.md`.
     */
    public function test_mcp_answers_a_notifying_tool_with_an_event_stream(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":2,"method":"tools/call","params":{
              "name":"count","arguments":{"count":2,"notify":true},
              "_meta":{"progressToken":"tok"}}}
            JSON);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/event-stream', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * Without `notify` and without a progress token, nothing streams at all —
     * the client opted into neither channel, and the transport takes the plain
     * JSON path.
     */
    public function test_mcp_without_notify_or_progress_token_does_not_stream(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":2,"method":"tools/call","params":{
              "name":"count","arguments":{"count":2}}}
            JSON);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('application/json', (string) $client->getResponse()->headers->get('Content-Type'));

        $data = $this->jsonRpcResponse($client);
        self::assertSame([1, 2], $data['result']['structuredContent']['lines']);
        self::assertTrue($data['result']['structuredContent']['streamed']);
    }

    /**
     * The resource is listed whether or not a run has happened.
     */
    public function test_the_resource_is_listed(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->mcpRequest($client, $sessionId, '{"jsonrpc":"2.0","id":2,"method":"resources/list","params":{}}');

        self::assertResponseIsSuccessful();
        $data = $this->jsonRpcResponse($client);

        $uris = array_column($data['result']['resources'], 'uri');
        self::assertContains('count://stream', $uris);
    }

    /**
     * Reading before any run answers with an empty stream rather than failing:
     * "nothing counted yet" is a true answer, and it is a client's first read.
     */
    public function test_reading_the_resource_before_a_run_is_empty_not_an_error(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":2,"method":"resources/read","params":{"uri":"count://stream"}}
            JSON);

        self::assertResponseIsSuccessful();
        $data = $this->jsonRpcResponse($client);

        self::assertSame('', $data['result']['contents'][0]['text']);
        self::assertSame(0, $data['result']['contents'][0]['_meta']['emitted']);
    }

    /**
     * The run is recorded, and a later read — a separate request — reports it.
     *
     * This is the whole point of the doorbell: the notification carries only a
     * URI, and what it was about is fetched afterwards.
     */
    public function test_the_resource_reports_the_lines_and_what_remains(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":2,"method":"tools/call","params":{
              "name":"count","arguments":{"count":3}}}
            JSON);
        self::assertResponseIsSuccessful();

        $this->mcpRequest($client, $sessionId, <<<'JSON'
            {"jsonrpc":"2.0","id":3,"method":"resources/read","params":{"uri":"count://stream"}}
            JSON);
        self::assertResponseIsSuccessful();

        $data = $this->jsonRpcResponse($client);
        $contents = $data['result']['contents'][0];

        self::assertSame("1\n2\n3", $contents['text']);
        self::assertSame(3, $contents['_meta']['emitted']);
        self::assertSame(0, $contents['_meta']['remaining']);
        self::assertSame(3, $contents['_meta']['total']);
    }
}
