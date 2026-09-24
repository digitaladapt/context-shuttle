<?php

declare(strict_types=1);

namespace App\Tool\Alerts;

/**
 * A provider-agnostic outbound alert: everything a provider needs to
 * render one notification, and nothing about how it is delivered.
 *
 * `actionUrl`/`actionLabel` are the single "tap here" mechanism shared
 * by `send_alert`'s link and (later) the interactive `/ask/{id}` link —
 * one code path, two callers. Note that both providers' click
 * affordances are unlabeled (tap/click the notification), so
 * `actionLabel` is accepted and carried for future labeled actions
 * without altering the visible link line.
 */
final readonly class OutboundAlert
{
    /**
     * @param list<string> $tags per-provider labels (ntfy tags/emoji, Discord title prefix)
     */
    public function __construct(
        public string $title,
        public ?string $body,
        public Priority $priority,
        public array $tags = [],
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
    ) {
    }
}
