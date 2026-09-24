<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Alerts;

use App\Tool\Alerts\AlertDispatcher;
use App\Tool\Alerts\OutboundAlert;
use App\Tool\Alerts\Priority;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fan-out semantics from docs/design/ALERTS.md (Delivery
 * semantics): every enabled provider receives every alert; a provider
 * failure never blocks the others; the report carries per-channel
 * outcomes; disabled providers are skipped entirely.
 *
 * @internal
 *
 * @covers \App\Tool\Alerts\AlertDispatcher
 */
final class AlertDispatcherTest extends TestCase
{
    private function alert(): OutboundAlert
    {
        return new OutboundAlert('Title', 'Body', new Priority(3));
    }

    public function test_skips_disabled_providers_and_lists_only_enabled(): void
    {
        $ntfy = new FakeAlertProvider('ntfy', enabled: true);
        $discord = new FakeAlertProvider('discord', enabled: false);

        $dispatcher = new AlertDispatcher([$ntfy, $discord]);

        self::assertSame(['ntfy'], $dispatcher->enabledProviders());

        $report = $dispatcher->dispatch($this->alert());

        self::assertTrue($report->delivered());
        self::assertSame(1, $ntfy->sendCount);
        self::assertSame(0, $discord->sendCount);
    }

    public function test_dispatches_to_every_enabled_provider(): void
    {
        $ntfy = new FakeAlertProvider('ntfy', messageId: 'n-1');
        $discord = new FakeAlertProvider('discord', messageId: 'd-1');

        $report = (new AlertDispatcher([$ntfy, $discord]))->dispatch($this->alert());

        self::assertTrue($report->delivered());
        self::assertSame(1, $ntfy->sendCount);
        self::assertSame(1, $discord->sendCount);

        self::assertSame([
            'delivered' => true,
            'channels' => [
                ['provider' => 'ntfy', 'delivered' => true, 'message_id' => 'n-1'],
                ['provider' => 'discord', 'delivered' => true, 'message_id' => 'd-1'],
            ],
        ], $report->toArray());
    }

    public function test_one_failure_does_not_block_the_others(): void
    {
        $ntfy = new FakeAlertProvider('ntfy', failWith: 'connection refused');
        $discord = new FakeAlertProvider('discord', messageId: 'd-1');

        $report = (new AlertDispatcher([$ntfy, $discord]))->dispatch($this->alert());

        self::assertTrue($report->delivered());
        self::assertSame([
            'delivered' => true,
            'channels' => [
                ['provider' => 'ntfy', 'delivered' => false, 'error' => 'connection refused'],
                ['provider' => 'discord', 'delivered' => true, 'message_id' => 'd-1'],
            ],
        ], $report->toArray());
    }

    public function test_all_failures_produce_an_undelivered_report(): void
    {
        $ntfy = new FakeAlertProvider('ntfy', failWith: 'connection refused');
        $discord = new FakeAlertProvider('discord', failWith: 'HTTP 401: Invalid Webhook Token');

        $report = (new AlertDispatcher([$ntfy, $discord]))->dispatch($this->alert());

        self::assertFalse($report->delivered());
        self::assertSame(
            'ntfy: connection refused; discord: HTTP 401: Invalid Webhook Token',
            $report->failureSummary(),
        );
    }

    public function test_no_providers_configured_yields_empty_report(): void
    {
        $dispatcher = new AlertDispatcher([]);

        self::assertSame([], $dispatcher->enabledProviders());

        $report = $dispatcher->dispatch($this->alert());

        self::assertFalse($report->delivered());
        self::assertSame(['delivered' => false, 'channels' => []], $report->toArray());
    }

    public function test_missing_message_id_is_omitted_from_the_report(): void
    {
        $ntfy = new FakeAlertProvider('ntfy', messageId: null);

        $report = (new AlertDispatcher([$ntfy]))->dispatch($this->alert());

        self::assertSame([
            'delivered' => true,
            'channels' => [
                ['provider' => 'ntfy', 'delivered' => true],
            ],
        ], $report->toArray());
    }
}
