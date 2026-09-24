<?php

declare(strict_types=1);

namespace App\Tool\Alerts;

/**
 * Proof that one provider accepted one alert: the provider's name, its
 * message id (when the provider reports one), and the priority that
 * was sent.
 */
final readonly class DeliveryReceipt
{
    public function __construct(
        public string $provider,
        public ?string $messageId,
        public int $priority,
    ) {
    }
}
