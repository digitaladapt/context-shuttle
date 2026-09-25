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
 * ntfy delivery channel: publishes one message to an ntfy topic.
 *
 * Enabled by presence: the provider is on iff NTFY_TOPIC is set
 * (NTFY_URL defaults to the public ntfy.sh server; NTFY_TOKEN is
 * optional for access-controlled or self-hosted servers).
 *
 * Rendering notes (docs/design/ALERTS.md, Provider notes → ntfy):
 *  - JSON publish (POST to the server root) rather than headers,
 *    because we need the `click` field for the link.
 *  - The click action is invisible until tapped, so the visible body
 *    gets the shared visible-link line (bold domain plus path — see
 *    VisibleLinkFormatter) appended after the human text — kept in
 *    the body, not the title, because titles truncate first.
 *  - Markdown is always on so the bolded domain renders.
 */
#[AutoconfigureTag('app.alert_provider')]
final class NtfyProvider implements AlertProvider
{
    private const DEFAULT_BASE_URL = 'https://ntfy.sh';

    public function __construct(
        private HttpClientInterface $httpClient,
        private VisibleLinkFormatter $linkFormatter,
        private string $baseUrl,
        private string $topic,
        private string $token = '',
    ) {
    }

    #[Override]
    public function name(): string
    {
        return 'ntfy';
    }

    #[Override]
    public function isEnabled(): bool
    {
        return '' !== trim($this->topic);
    }

    #[Override]
    public function send(OutboundAlert $alert): DeliveryReceipt
    {
        $topic = trim($this->topic);
        if ('' === $topic) {
            throw new RuntimeException('NTFY_TOPIC is not configured. Set it in .env.local to the topic alerts should be published to.');
        }

        $baseUrl = rtrim(trim($this->baseUrl), '/');
        if ('' === $baseUrl) {
            $baseUrl = self::DEFAULT_BASE_URL;
        }
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new RuntimeException(\sprintf('NTFY_URL must start with http:// or https:// (got "%s").', $baseUrl));
        }

        $payload = [
            'topic' => $topic,
            'title' => $alert->title,
            'message' => $this->message($alert),
            'priority' => $alert->priority->ntfyPriority(),
            'markdown' => true,
        ];
        if ([] !== $alert->tags) {
            $payload['tags'] = $alert->tags;
        }
        if (null !== $alert->actionUrl) {
            $payload['click'] = $alert->actionUrl;
        }

        $headers = ['Accept' => 'application/json'];
        $token = trim($this->token);
        if ('' !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = $this->httpClient->request('POST', $baseUrl, [
                'json' => $payload,
                'headers' => $headers,
                'timeout' => 10,
            ]);

            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(\sprintf('Could not reach ntfy at %s: %s', $baseUrl, $e->getMessage()), 0, $e);
        }

        if ($status >= 400) {
            throw new RuntimeException(\sprintf('ntfy returned HTTP %d for %s: %s', $status, $baseUrl, $this->responseBody($response)));
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
     * Human body with the visible destination line appended (when a
     * link is present), per the shared VisibleLinkFormatter rules.
     */
    private function message(OutboundAlert $alert): string
    {
        $body = $alert->body ?? '';
        $line = $this->linkFormatter->format($alert->actionUrl);

        if (null === $line) {
            return $body;
        }

        return '' === trim($body) ? $line : $body."\n\n".$line;
    }

    private function responseBody(ResponseInterface $response): string
    {
        try {
            $body = trim($response->getContent(false));
        } catch (Throwable) {
            return '(no response body)';
        }

        return '' === $body ? '(no response body)' : mb_substr($body, 0, 300);
    }
}
