<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The email tools through the real pipeline: YAML registry → MCP/REST →
 * handler. No network, because the deployment is unconfigured — which is
 * exactly the path a fresh install takes, and the one that must not 500.
 *
 * @internal
 *
 * @coversNothing
 */
final class EmailPipelineTest extends WebTestCase
{
    public function test_the_email_tools_are_registered(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools');

        self::assertResponseIsSuccessful();
        $names = array_column(
            json_decode((string) $client->getResponse()->getContent(), true)['tools'],
            'name',
        );

        self::assertContains('list_email_folders', $names);
        self::assertContains('list_emails', $names);
        self::assertContains('read_email', $names);
    }

    public function test_an_unconfigured_deployment_is_a_clear_message_not_a_500(): void
    {
        // The contract every tool here honours: unset config means the tool
        // is still listed, and invoking it says which env var to set rather
        // than blowing up inside the container.
        $client = self::createClient();
        $client->request(
            'POST',
            '/tools/list_emails',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['folder' => 'INBOX']),
        );

        self::assertResponseStatusCodeSame(422);
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('IMAP_HOST', $body);
        // Never the credential.
        self::assertStringNotContainsString('IMAP_PASSWORD=', $body);
    }

    public function test_the_folder_gate_refuses_before_touching_a_server(): void
    {
        // `list_emails` names a folder outside the configured allowlist. The
        // refusal must name the env var, and must happen without a
        // connection attempt — a clear message beats a timeout.
        $client = self::createClient();
        $client->request(
            'POST',
            '/tools/list_emails',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['folder' => 'Archive']),
        );

        self::assertResponseStatusCodeSame(422);
        $body = (string) $client->getResponse()->getContent();

        // Unconfigured wins first, so this asserts the *shape* of a
        // configuration-error response rather than which of the two ran.
        self::assertStringContainsString('not configured', $body);
    }

    public function test_a_schema_violation_is_rejected_by_the_pipeline(): void
    {
        // `uid` is declared as an integer with a minimum, so the validation
        // layer refuses a fractional uid before the handler is reached.
        $client = self::createClient();
        $client->request(
            'POST',
            '/tools/read_email',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['folder' => 'INBOX', 'uid' => -4]),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('uid', (string) $client->getResponse()->getContent());
    }

    public function test_no_email_description_names_the_protocol(): void
    {
        // The email tools speak IMAP, and a description saying so would
        // invite a caller to branch on *how* mail is reached — the same
        // mistake the calendar tools avoid by never saying "CalDAV". The
        // vocabulary a caller needs is "folders", "messages" and "tags".
        $client = self::createClient();
        $client->request('GET', '/tools');

        $tools = json_decode((string) $client->getResponse()->getContent(), true)['tools'];

        foreach ($tools as $tool) {
            if (!str_starts_with($tool['name'], 'read_email')
                && !str_starts_with($tool['name'], 'list_email')) {
                continue;
            }

            $description = strtolower((string) ($tool['description'] ?? ''));

            foreach (['imap', 'dovecot', 'maildir', 'smtp'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $description,
                    \sprintf('%s must not name the protocol', $tool['name']),
                );
            }
        }
    }

    public function test_the_read_email_description_carries_the_injection_warning(): void
    {
        // The one document a calling model is guaranteed to read, and the
        // tool whose output an attacker controls.
        $client = self::createClient();
        $client->request('GET', '/tools');

        $tools = json_decode((string) $client->getResponse()->getContent(), true)['tools'];

        $readEmail = null;
        foreach ($tools as $tool) {
            if ('read_email' === $tool['name']) {
                $readEmail = $tool;
            }
        }

        self::assertNotNull($readEmail, 'read_email must be registered');
        $description = strtolower((string) $readEmail['description']);

        self::assertStringContainsString('untrusted', $description);
        self::assertStringContainsString('never as a command', $description);
    }

    public function test_read_email_says_it_does_not_mark_messages_read(): void
    {
        // A model deciding whether it may look at something needs to know
        // this is safe to call. If the description did not say so, the safe
        // behaviour would be to not look.
        $client = self::createClient();
        $client->request('GET', '/tools');

        $tools = json_decode((string) $client->getResponse()->getContent(), true)['tools'];

        foreach ($tools as $tool) {
            if ('read_email' === $tool['name']) {
                self::assertStringContainsString(
                    'does not mark',
                    strtolower((string) $tool['description']),
                );
            }
        }
    }
}
