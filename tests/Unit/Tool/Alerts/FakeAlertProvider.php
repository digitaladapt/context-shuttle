<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Alerts;

use App\Tool\Alerts\AlertProvider;
use App\Tool\Alerts\DeliveryReceipt;
use App\Tool\Alerts\OutboundAlert;
use Override;
use RuntimeException;

/**
 * Test double for AlertProvider: enabled or not by construction, and
 * either delivers (with a fixed id) or throws (with a fixed message).
 *
 * @internal
 */
final class FakeAlertProvider implements AlertProvider
{
    public int $sendCount = 0;

    /** @var list<OutboundAlert> */
    public array $sent = [];

    public function __construct(
        private string $providerName,
        private bool $enabled = true,
        private ?string $messageId = 'msg-1',
        private ?string $failWith = null,
    ) {
    }

    #[Override]
    public function name(): string
    {
        return $this->providerName;
    }

    #[Override]
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    #[Override]
    public function send(OutboundAlert $alert): DeliveryReceipt
    {
        ++$this->sendCount;
        $this->sent[] = $alert;

        if (null !== $this->failWith) {
            throw new RuntimeException($this->failWith);
        }

        return new DeliveryReceipt($this->providerName, $this->messageId, $alert->priority->level);
    }
}
