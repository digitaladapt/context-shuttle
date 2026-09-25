<?php

declare(strict_types=1);

namespace App\Tool\Alerts;

/**
 * Outcome of one fan-out: one channel entry per enabled provider, in
 * dispatch order, each noting delivered-or-not with its message id or
 * failure reason. A report with deliveries *and* failures is still a
 * success — the alert reached the boss on at least one channel.
 */
final readonly class DispatchReport
{
    /**
     * @param list<array{provider: string, delivered: bool, message_id?: string, error?: string}> $channels
     */
    public function __construct(
        private array $channels,
    ) {
    }

    public function delivered(): bool
    {
        foreach ($this->channels as $channel) {
            if ($channel['delivered']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tool-facing report shape.
     *
     * @return array{delivered: bool, channels: list<array{provider: string, delivered: bool, message_id?: string, error?: string}>}
     */
    public function toArray(): array
    {
        return [
            'delivered' => $this->delivered(),
            'channels' => $this->channels,
        ];
    }

    /**
     * "ntfy: connection refused; discord: HTTP 401: ..." — for error
     * messages when nothing delivered.
     */
    public function failureSummary(): string
    {
        $parts = [];

        foreach ($this->channels as $channel) {
            if (!$channel['delivered']) {
                $parts[] = $channel['provider'].': '.($channel['error'] ?? 'unknown error');
            }
        }

        return implode('; ', $parts);
    }
}
