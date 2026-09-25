<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Alerts;

use App\Tool\Alerts\AlertDispatcher;
use App\Tool\Alerts\AlertTool;
use App\Tool\Alerts\Priority;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the send_alert tool: validation rules, the
 * configured/undelivered failure distinction (the one the design
 * insists on for the calling LLM), and the delivery report passthrough.
 *
 * @internal
 *
 * @covers \App\Tool\Alerts\AlertTool
 */
final class AlertToolTest extends TestCase
{
    public function test_sends_with_defaults(): void
    {
        $ntfy = new FakeAlertProvider('ntfy', messageId: 'n-1');
        $tool = new AlertTool(new AlertDispatcher([$ntfy]));

        $result = $tool->sendAlert('Deploy finished');

        self::assertSame([
            'delivered' => true,
            'channels' => [
                ['provider' => 'ntfy', 'delivered' => true, 'message_id' => 'n-1'],
            ],
        ], $result);

        $sent = $ntfy->sent[0];
        self::assertSame('Deploy finished', $sent->title);
        self::assertNull($sent->body);
        self::assertSame(Priority::DEFAULT, $sent->priority->level);
        self::assertSame([], $sent->tags);
        self::assertNull($sent->actionUrl);
    }

    public function test_passes_full_alert_through_to_providers(): void
    {
        $discord = new FakeAlertProvider('discord');
        $tool = new AlertTool(new AlertDispatcher([$discord]));

        $tool->sendAlert(
            title: 'Backup done',
            body: 'The nightly backup completed.',
            priority: 5,
            tags: ['backup', 'ok'],
            link: 'https://example.com/backups/42',
            link_label: 'Open backup',
        );

        $sent = $discord->sent[0];
        self::assertSame('Backup done', $sent->title);
        self::assertSame('The nightly backup completed.', $sent->body);
        self::assertSame(5, $sent->priority->level);
        self::assertSame(['backup', 'ok'], $sent->tags);
        self::assertSame('https://example.com/backups/42', $sent->actionUrl);
        self::assertSame('Open backup', $sent->actionLabel);
    }

    public function test_default_link_label_is_open(): void
    {
        $ntfy = new FakeAlertProvider('ntfy');
        $tool = new AlertTool(new AlertDispatcher([$ntfy]));

        $tool->sendAlert('x', link: 'https://example.com/run/42');

        self::assertSame('Open', $ntfy->sent[0]->actionLabel);
    }

    public function test_rejects_empty_title(): void
    {
        $tool = new AlertTool(new AlertDispatcher([new FakeAlertProvider('ntfy')]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('title must not be empty');

        $tool->sendAlert('   ');
    }

    public function test_rejects_overlong_title(): void
    {
        $tool = new AlertTool(new AlertDispatcher([new FakeAlertProvider('ntfy')]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('title must be 200 characters or fewer');

        $tool->sendAlert(str_repeat('x', 201));
    }

    public function test_rejects_overlong_body(): void
    {
        $tool = new AlertTool(new AlertDispatcher([new FakeAlertProvider('ntfy')]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('body must be 4000 characters or fewer');

        $tool->sendAlert('x', body: str_repeat('x', 4001));
    }

    public function test_rejects_too_many_tags(): void
    {
        $tool = new AlertTool(new AlertDispatcher([new FakeAlertProvider('ntfy')]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 8 entries');

        $tool->sendAlert('x', tags: ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']);
    }

    public function test_trims_and_drops_empty_tags(): void
    {
        $ntfy = new FakeAlertProvider('ntfy');
        $tool = new AlertTool(new AlertDispatcher([$ntfy]));

        $tool->sendAlert('x', tags: ['  ci ', '', 'done ']);

        self::assertSame(['ci', 'done'], $ntfy->sent[0]->tags);
    }

    public function test_rejects_out_of_range_priority(): void
    {
        $tool = new AlertTool(new AlertDispatcher([new FakeAlertProvider('ntfy')]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 5');

        $tool->sendAlert('x', priority: 6);
    }

    public function test_rejects_non_http_link(): void
    {
        $tool = new AlertTool(new AlertDispatcher([new FakeAlertProvider('ntfy')]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('link must be an http:// or https:// URL');

        $tool->sendAlert('x', link: 'ftp://example.com/file');
    }

    public function test_errors_clearly_when_no_provider_configured(): void
    {
        $tool = new AlertTool(new AlertDispatcher([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No alert provider is configured');

        $tool->sendAlert('x');
    }

    public function test_errors_when_every_enabled_provider_fails(): void
    {
        $ntfy = new FakeAlertProvider('ntfy', failWith: 'connection refused');
        $discord = new FakeAlertProvider('discord', failWith: 'HTTP 401: bad token');
        $tool = new AlertTool(new AlertDispatcher([$ntfy, $discord]));

        try {
            $tool->sendAlert('x');
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not delivered on any enabled provider', $e->getMessage());
            self::assertStringContainsString('ntfy: connection refused', $e->getMessage());
            self::assertStringContainsString('discord: HTTP 401: bad token', $e->getMessage());
        }
    }

    public function test_partial_failure_is_still_a_success_result(): void
    {
        $ntfy = new FakeAlertProvider('ntfy', failWith: 'connection refused');
        $discord = new FakeAlertProvider('discord', messageId: 'd-1');
        $tool = new AlertTool(new AlertDispatcher([$ntfy, $discord]));

        $result = $tool->sendAlert('x');

        self::assertTrue($result['delivered']);
        // The report still names the failed channel — the LLM gets to
        // see exactly which channels worked.
        self::assertSame([
            ['provider' => 'ntfy', 'delivered' => false, 'error' => 'connection refused'],
            ['provider' => 'discord', 'delivered' => true, 'message_id' => 'd-1'],
        ], $result['channels']);
    }
}
