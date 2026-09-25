<?php

declare(strict_types=1);

namespace App\Tool\Alerts;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Best-effort fan-out across every enabled provider.
 *
 * Delivery semantics (docs/design/ALERTS.md): each attempt is isolated
 * — one provider failing never blocks the others — and the outcome is
 * a per-provider report. Callers treat "at least one delivered" as
 * success; the report is where a caller (and the LLM) sees exactly
 * which channels worked.
 *
 * This class is also the seam Phase 2 hangs: interactive requests mint
 * an id, dispatch through here, and only persist when the report says
 * something actually delivered.
 */
final class AlertDispatcher
{
    /**
     * @param iterable<AlertProvider> $providers every provider service, tagged 'app.alert_provider';
     *                                           presence-based enabling happens here, not at wiring time
     */
    public function __construct(
        #[AutowireIterator('app.alert_provider')]
        private iterable $providers,
    ) {
    }

    /**
     * Dispatch to every enabled provider, isolating failures.
     *
     * @return DispatchReport always returns; an all-fail report is a
     *                        value, not an exception — the caller decides
     *                        what an empty report means (for `send_alert`:
     *                        an `isError` result; per the design, the
     *                        distinction matters to the calling LLM)
     */
    public function dispatch(OutboundAlert $alert): DispatchReport
    {
        $channels = [];

        foreach ($this->providers as $provider) {
            if (!$provider->isEnabled()) {
                continue;
            }

            try {
                $receipt = $provider->send($alert);

                $entry = [
                    'provider' => $receipt->provider,
                    'delivered' => true,
                ];
                if (null !== $receipt->messageId) {
                    $entry['message_id'] = $receipt->messageId;
                }

                $channels[] = $entry;
            } catch (Throwable $e) {
                $channels[] = [
                    'provider' => $provider->name(),
                    'delivered' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return new DispatchReport($channels);
    }

    /**
     * Names of providers whose configuration is present, in iteration
     * order. Used by callers to distinguish "nothing configured" (a
     * configuration error) from "everything failed" (a delivery
     * report).
     *
     * @return list<string>
     */
    public function enabledProviders(): array
    {
        $names = [];

        foreach ($this->providers as $provider) {
            if ($provider->isEnabled()) {
                $names[] = $provider->name();
            }
        }

        return $names;
    }
}
