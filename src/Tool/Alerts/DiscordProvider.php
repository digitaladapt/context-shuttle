<?php

declare(strict_types=1);

namespace App\Tool\Alerts;

use Override;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Discord delivery channel: executes a channel webhook.
 *
 * Enabled by presence: the provider is on iff DISCORD_WEBHOOK_URL is
 * set (DISCORD_MENTION_USER_ID is optional — see below).
 *
 * Rendering notes (docs/design/ALERTS.md, Provider notes → Discord):
 *  - One embed: title, description, colour by priority, link as the
 *    embed URL; body gets the shared visible-link line (bold domain
 *    plus path — see VisibleLinkFormatter).
 *  - `wait=true` is required for Discord to return the created
 *    message (with its id) instead of a 204.
 *  - Mention policy: only priority 5 ("urgent, interrupt me") can ping,
 *    and only the configured DISCORD_MENTION_USER_ID — never
 *    `@everyone`/`@here`/roles. `allowed_mentions` is always set
 *    explicitly: `{parse: []}` suppresses every mention otherwise, so
 *    an LLM cannot mass-ping a server no matter what it puts in the
 *    body. (Note: `parse` and `users` are mutually exclusive on
 *    Discord's side — we send one or the other, never both.)
 */
#[AutoconfigureTag('app.alert_provider')]
final class DiscordProvider implements AlertProvider
{
    /** Discord embed title limit, in characters. */
    private const MAX_EMBED_TITLE = 256;

    public function __construct(
        private HttpClientInterface $httpClient,
        private VisibleLinkFormatter $linkFormatter,
        private string $webhookUrl,
        private string $mentionUserId = '',
    ) {
    }

    #[Override]
    public function name(): string
    {
        return 'discord';
    }

    #[Override]
    public function isEnabled(): bool
    {
        return '' !== trim($this->webhookUrl);
    }

    #[Override]
    public function send(OutboundAlert $alert): DeliveryReceipt
    {
        $webhookUrl = trim($this->webhookUrl);
        if ('' === $webhookUrl) {
            throw new RuntimeException('DISCORD_WEBHOOK_URL is not configured. Set it in .env.local to the channel webhook URL.');
        }
        if (!preg_match('#^https?://#i', $webhookUrl)) {
            // Deliberately not echoed: the webhook URL is a credential.
            throw new RuntimeException('DISCORD_WEBHOOK_URL must start with http:// or https://.');
        }

        $payload = $this->payload($alert);

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'query' => ['wait' => 'true'],
                'json' => $payload,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 10,
            ]);

            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            // No URL in the message: the webhook URL (with its token)
            // is a credential and must never leak into errors or logs.
            throw new RuntimeException('Could not reach the Discord webhook: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            throw new RuntimeException(\sprintf('Discord webhook returned HTTP %d: %s', $status, $this->errorDetail($response)));
        }

        $messageId = null;
        try {
            $data = $response->toArray(false);
            if (isset($data['id']) && \is_string($data['id'])) {
                $messageId = $data['id'];
            }
        } catch (Throwable) {
            // Delivery succeeded; a missing/unparseable id is not an error.
        }

        return new DeliveryReceipt($this->name(), $messageId, $alert->priority->level);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OutboundAlert $alert): array
    {
        $title = $alert->title;
        if ([] !== $alert->tags) {
            $prefix = '';
            foreach ($alert->tags as $tag) {
                $prefix .= '['.$tag.'] ';
            }
            $title = $prefix.$title;
        }
        // Tags are decorative composition on our side; keep the
        // composed title within Discord's embed limit (256 chars).
        $title = mb_substr($title, 0, self::MAX_EMBED_TITLE);

        $description = $alert->body ?? '';
        $line = $this->linkFormatter->format($alert->actionUrl);
        if (null !== $line) {
            $description = '' === trim($description) ? $line : $description."\n\n".$line;
        }

        $embed = [
            'title' => $title,
            'color' => $alert->priority->discordColor(),
            // Static attribution: deliberately product-level, not
            // tool-specific, because Phase 2 reuses this provider for
            // `ask_user` notifications.
            'footer' => ['text' => 'context-shuttle'],
        ];
        if ('' !== $description) {
            $embed['description'] = $description;
        }
        if (null !== $alert->actionUrl) {
            $embed['url'] = $alert->actionUrl;
        }

        $payload = [
            'embeds' => [$embed],
            'allowed_mentions' => ['parse' => []],
        ];

        $mentionUserId = trim($this->mentionUserId);
        if ($alert->priority->mentions() && '' !== $mentionUserId) {
            // The mention must appear in `content` to actually ping;
            // `allowed_mentions.users` then pins the only user who can
            // be pinged (mutually exclusive with `parse`, so no
            // @everyone/@here/role mention can ride along).
            $payload['content'] = '<@'.$mentionUserId.'>';
            $payload['allowed_mentions'] = ['users' => [$mentionUserId]];
        }

        return $payload;
    }

    private function errorDetail(ResponseInterface $response): string
    {
        try {
            $body = trim($response->getContent(false));
        } catch (Throwable) {
            return '(no response body)';
        }

        if ('' === $body) {
            return '(no response body)';
        }

        $decoded = json_decode($body, true);
        if (\is_array($decoded) && isset($decoded['message']) && \is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return mb_substr($body, 0, 300);
    }
}
