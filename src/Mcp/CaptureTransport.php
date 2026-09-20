<?php

declare(strict_types=1);

namespace App\Mcp;

use Evenement\EventEmitterTrait;
use PhpMcp\Schema\JsonRpc\Message;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use React\Promise\PromiseInterface;

use function count;
use function React\Promise\resolve;

/**
 * A per-request transport that captures the response Protocol wants to send.
 *
 * Protocol::processMessage() always sends its response through
 * transport->sendMessage(); this transport records it instead of writing to
 * a socket, so the Symfony controller can return it as the HTTP response.
 */
final class CaptureTransport implements ServerTransportInterface
{
    use EventEmitterTrait;

    /** @var list<Message> */
    private array $sent = [];

    public function listen(): void
    {
        // no-op: driven by the Symfony request cycle
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return PromiseInterface<void> promise resolving once "sent"
     */
    public function sendMessage(Message $message, string $sessionId, array $context = []): PromiseInterface
    {
        $this->sent[] = $message;

        // resolve(null) is PromiseInterface<null>; the interface's
        // PromiseInterface<void> is PHPStan-notation for "no value".
        // @phpstan-ignore return.type (null is the void value)
        return resolve(null);
    }

    /** @return list<Message> */
    public function sentMessages(): array
    {
        return $this->sent;
    }

    public function lastMessage(): ?Message
    {
        return $this->sent[count($this->sent) - 1] ?? null;
    }

    public function clear(): void
    {
        $this->sent = [];
    }

    public function close(): void
    {
        $this->removeAllListeners();
    }
}
