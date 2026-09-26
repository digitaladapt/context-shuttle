<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Alerts\PendingRequest;
use App\Alerts\PendingRequestId;
use App\Alerts\PendingRequestStore;
use App\Alerts\RequestType;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The interactive-request plumbing end to end: the /ask form and the
 * /inputs status endpoint, through the real kernel — no providers
 * involved (Phase 1 is exactly the provider-free mechanics, per
 * docs/design/ALERTS.md).
 *
 * Covers the states the design calls out: form → answered, single-use,
 * expiry, not-found, and the pending → answered / pending → expired
 * transitions on the status endpoint.
 *
 * @internal
 *
 * @coversNothing
 */
final class InteractiveRequestsPipelineTest extends WebTestCase
{
    public function test_get_ask_renders_a_text_form(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Text);

        $client->request('GET', '/ask/'.$id);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Which CI should we change?', $body);
        self::assertStringContainsString('<textarea', $body);
        self::assertStringContainsString('name="answer"', $body);
        // No confirm buttons on a text request.
        self::assertStringNotContainsString('value="yes"', $body);
    }

    public function test_get_ask_renders_a_confirm_form_with_custom_labels(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Confirm, confirmLabel: 'Deploy it', dismissLabel: 'Not yet');

        $client->request('GET', '/ask/'.$id);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('value="yes"', $body);
        self::assertStringContainsString('value="no"', $body);
        self::assertStringContainsString('Deploy it', $body);
        self::assertStringContainsString('Not yet', $body);
        // No textarea on a confirm request.
        self::assertStringNotContainsString('<textarea', $body);
    }

    public function test_posting_a_text_answer_redirects_and_records_it(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Text);

        $client->request('POST', '/ask/'.$id, ['answer' => 'Just this one project.']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/ask/'.$id);

        // Follow the redirect: the answered page, not the form.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Answer received', $body);

        // Stored as answered, with the trimmed answer.
        $stored = $this->store()->find($id, new DateTimeImmutable());
        self::assertNotNull($stored);
        self::assertSame('answered', $stored->status->value);
        self::assertSame('Just this one project.', $stored->answer);
    }

    public function test_posting_a_confirm_answer_stores_a_bool(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Confirm);

        $client->request('POST', '/ask/'.$id, ['answer' => 'yes']);

        self::assertResponseStatusCodeSame(303);
        $stored = $this->store()->find($id, new DateTimeImmutable());
        self::assertNotNull($stored);
        self::assertTrue($stored->answer);
    }

    public function test_answering_is_single_use(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Text);

        $client->request('POST', '/ask/'.$id, ['answer' => 'first']);
        self::assertResponseStatusCodeSame(303);

        // A second POST is refused (409) and does not overwrite the answer.
        $client->request('POST', '/ask/'.$id, ['answer' => 'second']);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Answer received', (string) $client->getResponse()->getContent());

        // GET likewise shows the answered state.
        $client->request('GET', '/ask/'.$id);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Answer received', (string) $client->getResponse()->getContent());

        $stored = $this->store()->find($id, new DateTimeImmutable());
        self::assertNotNull($stored);
        self::assertSame('first', $stored->answer);
    }

    public function test_expired_requests_render_expired_and_refuse_answers(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Text, expiresAt: new DateTimeImmutable('-1 minute'));

        $client->request('GET', '/ask/'.$id);
        self::assertResponseStatusCodeSame(410);
        self::assertStringContainsString('expired', (string) $client->getResponse()->getContent());

        // An answer arriving after the deadline is refused, not recorded.
        $client->request('POST', '/ask/'.$id, ['answer' => 'too late']);
        self::assertResponseStatusCodeSame(410);

        $stored = $this->store()->find($id, new DateTimeImmutable());
        self::assertNotNull($stored);
        self::assertNull($stored->answer);
    }

    public function test_unknown_and_malformed_ids_are_not_found(): void
    {
        $client = $this->bootClient();

        foreach (['abcdefghijklmnopqrstuv', 'short', 'has spaces!!!'] as $bad) {
            $client->request('GET', '/ask/'.$bad);
            self::assertSame(404, $client->getResponse()->getStatusCode(), 'GET /ask/'.$bad);
            self::assertStringContainsString('not found', strtolower((string) $client->getResponse()->getContent()));

            $client->request('POST', '/ask/'.$bad, ['answer' => 'x']);
            self::assertSame(404, $client->getResponse()->getStatusCode(), 'POST /ask/'.$bad);
        }
    }

    public function test_form_validation_rejects_missing_and_overlong_text(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Text);

        // Missing answer → 422 with the form again, nothing stored.
        $client->request('POST', '/ask/'.$id, ['answer' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Please type an answer', (string) $client->getResponse()->getContent());

        // Whitespace-only is empty too.
        $client->request('POST', '/ask/'.$id, ['answer' => "  \n "]);
        self::assertResponseStatusCodeSame(422);

        // Overlong answer → 422.
        $client->request('POST', '/ask/'.$id, ['answer' => str_repeat('x', 4001)]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('4000 characters', (string) $client->getResponse()->getContent());

        $stored = $this->store()->find($id, new DateTimeImmutable());
        self::assertNotNull($stored);
        self::assertSame('pending', $stored->status->value);
        self::assertNull($stored->answer);
    }

    public function test_form_validation_rejects_invalid_confirm_values(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Confirm);

        $client->request('POST', '/ask/'.$id, ['answer' => 'maybe']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('choose one of the buttons', (string) $client->getResponse()->getContent());

        $stored = $this->store()->find($id, new DateTimeImmutable());
        self::assertNotNull($stored);
        self::assertSame('pending', $stored->status->value);
    }

    public function test_status_endpoint_reports_pending_then_answered(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Text, question: 'Ship the release?');

        $client->request('GET', '/inputs/'.$id);
        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());

        self::assertSame('pending', $payload['status']);
        self::assertSame('text', $payload['type']);
        self::assertSame('Ship the release?', $payload['question']);
        self::assertArrayHasKey('expires_at', $payload);
        self::assertArrayNotHasKey('answer', $payload);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        // Answer it through the form…
        $client->request('POST', '/ask/'.$id, ['answer' => 'yes, go']);
        self::assertResponseStatusCodeSame(303);

        // …and the harness view flips.
        $client->request('GET', '/inputs/'.$id);
        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame('answered', $payload['status']);
        self::assertSame('yes, go', $payload['answer']);
        self::assertArrayHasKey('answered_at', $payload);
    }

    public function test_status_endpoint_reports_confirm_answers_as_booleans(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Confirm);

        $client->request('POST', '/ask/'.$id, ['answer' => 'no']);
        $client->request('GET', '/inputs/'.$id);

        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame('answered', $payload['status']);
        self::assertFalse($payload['answer']);
        self::assertSame('confirm', $payload['type']);
    }

    public function test_status_endpoint_reports_expired_after_the_deadline(): void
    {
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Text, expiresAt: new DateTimeImmutable('-1 second'));

        $client->request('GET', '/inputs/'.$id);

        self::assertResponseIsSuccessful();
        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame('expired', $payload['status']);
        self::assertArrayNotHasKey('answer', $payload);
    }

    public function test_status_endpoint_is_404_for_unknown_ids(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/inputs/abcdefghijklmnopqrstuv');

        self::assertResponseStatusCodeSame(404);
        $payload = $this->json($client->getResponse()->getContent());
        self::assertSame('not_found', $payload['status']);
    }

    public function test_ask_pages_tell_robots_to_stay_out(): void
    {
        // The id is a capability in a URL; robots must stay out. Asserted
        // against the rendered meta tag, not a response header: the test
        // environment adds `X-Robots-Tag: noindex` to *every* response
        // (DisallowRobotsIndexingListener), so a header assertion here
        // would pass vacuously.
        $client = $this->bootClient();
        $id = $this->seed(RequestType::Text);

        $client->request('GET', '/ask/'.$id);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', (string) $client->getResponse()->getContent());
    }

    /**
     * Boots a fresh client and empties the request store, so tests start
     * from a clean slate regardless of what earlier tests (or earlier
     * suite runs) left in the filesystem-backed pool.
     */
    private function bootClient(): KernelBrowser
    {
        $client = self::createClient();
        self::getContainer()->get('interactive_requests')->clear();

        return $client;
    }

    /**
     * Seeds a request directly into the real store, returning its id.
     */
    private function seed(
        RequestType $type,
        string $question = 'Which CI should we change?',
        ?DateTimeImmutable $expiresAt = null,
        string $confirmLabel = 'Yes',
        string $dismissLabel = 'No',
    ): string {
        $id = PendingRequestId::generate();
        $now = new DateTimeImmutable();

        $this->store()->save(new PendingRequest(
            id: $id,
            type: $type,
            question: $question,
            createdAt: $now,
            expiresAt: $expiresAt ?? $now->modify('+600 seconds'),
            placeholder: RequestType::Text === $type ? 'type here' : null,
            confirmLabel: $confirmLabel,
            dismissLabel: $dismissLabel,
        ));

        return $id;
    }

    private function store(): PendingRequestStore
    {
        return self::getContainer()->get(PendingRequestStore::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string|false $content): array
    {
        self::assertIsString($content);
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
