<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Alerts;

use App\Tool\Alerts\Priority;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the five-level priority model and its per-provider renderings
 * (docs/design/ALERTS.md, Priority model).
 *
 * @internal
 *
 * @covers \App\Tool\Alerts\Priority
 */
final class PriorityTest extends TestCase
{
    public function test_accepts_every_level_1_to_5(): void
    {
        foreach ([1, 2, 3, 4, 5] as $level) {
            self::assertSame($level, (new Priority($level))->level);
        }
    }

    public function test_rejects_levels_outside_1_to_5(): void
    {
        foreach ([0, -1, 6, 100] as $level) {
            try {
                new Priority($level);
                self::fail(\sprintf('Priority %d should have been rejected.', $level));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('between 1 and 5', $e->getMessage());
            }
        }
    }

    public function test_ntfy_priority_is_the_identity_mapping(): void
    {
        foreach ([1, 2, 3, 4, 5] as $level) {
            self::assertSame($level, (new Priority($level))->ntfyPriority());
        }
    }

    public function test_discord_colours_go_grey_to_red(): void
    {
        self::assertSame(9807270, (new Priority(1))->discordColor());  // #95A5A6 grey
        self::assertSame(3066993, (new Priority(2))->discordColor());  // #2ECC71 green
        self::assertSame(3447003, (new Priority(3))->discordColor());  // #3498DB blue
        self::assertSame(15105570, (new Priority(4))->discordColor()); // #E67E22 orange
        self::assertSame(15548997, (new Priority(5))->discordColor()); // #ED4245 red
    }

    public function test_only_level_5_mentions(): void
    {
        self::assertFalse((new Priority(1))->mentions());
        self::assertFalse((new Priority(2))->mentions());
        self::assertFalse((new Priority(3))->mentions());
        self::assertFalse((new Priority(4))->mentions());
        self::assertTrue((new Priority(5))->mentions());
    }

    public function test_default_is_three(): void
    {
        self::assertSame(3, Priority::DEFAULT);
    }
}
