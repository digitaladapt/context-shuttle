<?php

declare(strict_types=1);

namespace App\Tool\Echo;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

use function mb_strlen;
use function mb_strtolower;
use function mb_strtoupper;

/**
 * Example no-network tool: echoes its input back.
 *
 * Useful as a template for new tools and for testing the full
 * MCP/REST pipeline without external dependencies.
 */
final class EchoTool
{
    /**
     * @return array{echo: string, received_at: string, length: int}
     */
    public function echo(string $message, ?string $style = null): array
    {
        $transformed = match ($style) {
            'upper' => mb_strtoupper($message),
            'lower' => mb_strtolower($message),
            default => $message,
        };

        return [
            'echo' => $transformed,
            'received_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'length' => mb_strlen($message),
        ];
    }
}
