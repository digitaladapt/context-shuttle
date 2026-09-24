<?php

declare(strict_types=1);

namespace App\Tool\Alerts;

use InvalidArgumentException;
use LogicException;

/**
 * Alert priority, 1–5, modelled on ntfy's five levels (the richer of the
 * two provider scales). Carries each provider's rendering, so the
 * mapping lives in one class instead of scattered per-provider match
 * statements.
 */
final readonly class Priority
{
    public const MIN = 1;

    public const MAX = 5;

    public const DEFAULT = 3;

    /**
     * @throws InvalidArgumentException when the level is outside 1–5
     */
    public function __construct(
        public int $level,
    ) {
        if ($level < self::MIN || $level > self::MAX) {
            throw new InvalidArgumentException(\sprintf('Priority must be between %d and %d (got %d).', self::MIN, self::MAX, $level));
        }
    }

    /**
     * ntfy's native scale is 1=min, 2=low, 3=default, 4=high, 5=max —
     * the same shape as ours, so the mapping is the identity.
     */
    public function ntfyPriority(): int
    {
        return $this->level;
    }

    /**
     * Decimal colour for a Discord embed sidebar, grey → red as urgency
     * climbs.
     */
    public function discordColor(): int
    {
        return match ($this->level) {
            1 => 9807270,  // #95A5A6 grey
            2 => 3066993,  // #2ECC71 green
            3 => 3447003,  // #3498DB blue
            4 => 15105570, // #E67E22 orange
            5 => 15548997, // #ED4245 red
            default => throw new LogicException(\sprintf('Unreachable: priority level %d passed constructor validation but has no Discord colour.', $this->level)),
        };
    }

    /**
     * Whether this priority may ping the boss (a Discord user mention).
     * Only level 5 — "urgent, interrupt me" — is allowed to interrupt.
     */
    public function mentions(): bool
    {
        return self::MAX === $this->level;
    }
}
