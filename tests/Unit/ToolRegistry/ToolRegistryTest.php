<?php

declare(strict_types=1);

namespace App\Tests\Unit\ToolRegistry;

use App\ToolRegistry\ToolDefinition;
use App\ToolRegistry\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ToolRegistryTest extends TestCase
{
    public function test_get_names_and_lookup(): void
    {
        $weather = new ToolDefinition('get_weather', 'desc w', 'A::b', []);
        $echo = new ToolDefinition('echo', 'desc e', 'C::d', []);

        $registry = new ToolRegistry([$weather, $echo]);

        self::assertSame(['get_weather', 'echo'], $registry->names());
        self::assertSame($weather, $registry->get('get_weather'));
        self::assertSame($echo, $registry->get('echo'));
        self::assertNull($registry->get('missing'));
        self::assertCount(2, $registry->all());
    }

    public function test_registry_is_immutable_from_outside(): void
    {
        $tool = new ToolDefinition('echo', 'desc', 'A::b', []);
        $registry = new ToolRegistry([$tool]);

        $all = $registry->all();
        $all[] = new ToolDefinition('injected', 'desc', 'X::y', []);

        self::assertCount(1, $registry->all());
    }
}
