<?php

declare(strict_types=1);

namespace App\Tool\Alerts;

use InvalidArgumentException;
use RuntimeException;

/**
 * send_alert: one-way notification to the boss.
 *
 * Delivers through AlertDispatcher, which fans out best-effort across
 * every enabled provider (ntfy, Discord — presence-based, either or
 * both). The result is a per-channel delivery report; it is only an
 * error when *nothing* was configured (a deployment problem) or when
 * every enabled provider failed (the alert reached nobody) — the
 * distinction matters to the calling LLM.
 */
final class AlertTool
{
    private const MAX_TITLE_CHARS = 200;

    private const MAX_BODY_CHARS = 4000;

    private const MAX_TAGS = 8;

    public function __construct(
        private AlertDispatcher $dispatcher,
    ) {
    }

    /**
     * Send a one-way alert with an optional link.
     *
     * @param string            $title      short headline, ≤ 200 chars
     * @param string|null       $body       markdown-ish detail, ≤ 4000 chars
     * @param int|null          $priority   1–5 (default 3); see Priority
     * @param list<string>|null $tags       up to 8 labels
     * @param string|null       $link       http(s) URL rendered as the notification's click action
     * @param string|null       $link_label display label for the link (default "Open")
     *
     * @return array{delivered: bool, channels: list<array{provider: string, delivered: bool, message_id?: string, error?: string}>}
     */
    public function sendAlert(
        string $title,
        ?string $body = null,
        ?int $priority = null,
        ?array $tags = null,
        ?string $link = null,
        ?string $link_label = null,
    ): array {
        $title = trim($title);
        if ('' === $title) {
            throw new InvalidArgumentException('title must not be empty.');
        }
        if (mb_strlen($title) > self::MAX_TITLE_CHARS) {
            throw new InvalidArgumentException(\sprintf('title must be %d characters or fewer (got %d).', self::MAX_TITLE_CHARS, mb_strlen($title)));
        }

        if (null !== $body && mb_strlen($body) > self::MAX_BODY_CHARS) {
            throw new InvalidArgumentException(\sprintf('body must be %d characters or fewer (got %d).', self::MAX_BODY_CHARS, mb_strlen($body)));
        }

        $alert = new OutboundAlert(
            title: $title,
            body: $body,
            priority: new Priority($priority ?? Priority::DEFAULT),
            tags: $this->normalizeTags($tags),
            actionUrl: $this->normalizeLink($link),
            actionLabel: $this->normalizeLabel($link_label),
        );

        if ([] === $this->dispatcher->enabledProviders()) {
            throw new RuntimeException('No alert provider is configured. Set NTFY_TOPIC (ntfy) and/or DISCORD_WEBHOOK_URL (Discord) in .env.local.');
        }

        $report = $this->dispatcher->dispatch($alert);

        if (!$report->delivered()) {
            throw new RuntimeException(\sprintf('Alert was not delivered on any enabled provider (%s).', $report->failureSummary()));
        }

        return $report->toArray();
    }

    /**
     * @param list<string>|null $tags
     *
     * @return list<string>
     */
    private function normalizeTags(?array $tags): array
    {
        if (null === $tags || [] === $tags) {
            return [];
        }

        if (\count($tags) > self::MAX_TAGS) {
            throw new InvalidArgumentException(\sprintf('tags must contain at most %d entries (got %d).', self::MAX_TAGS, \count($tags)));
        }

        $clean = [];
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ('' !== $tag) {
                $clean[] = $tag;
            }
        }

        return $clean;
    }

    private function normalizeLink(?string $link): ?string
    {
        $link = trim((string) $link);
        if ('' === $link) {
            return null;
        }

        if (!preg_match('#^https?://#i', $link)) {
            throw new InvalidArgumentException('link must be an http:// or https:// URL.');
        }

        return $link;
    }

    private function normalizeLabel(?string $link_label): string
    {
        $link_label = trim((string) $link_label);

        return '' === $link_label ? 'Open' : $link_label;
    }
}
