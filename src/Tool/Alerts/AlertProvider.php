<?php

declare(strict_types=1);

namespace App\Tool\Alerts;

/**
 * One implementation per delivery channel.
 *
 * `isEnabled()` extends the name/send pair sketched in
 * docs/design/ALERTS.md: fan-out is presence-based ("a provider is on
 * when its config env vars are present"), so the dispatcher needs one
 * uniform way to ask whether a channel is configured, without
 * special-casing each provider's env vars.
 */
interface AlertProvider
{
    /** Non-display identifier: 'ntfy', 'discord', … */
    public function name(): string;

    /** Whether this provider's required configuration is present. */
    public function isEnabled(): bool;

    /** Delivery receipt, or throws on failure. */
    public function send(OutboundAlert $alert): DeliveryReceipt;
}
