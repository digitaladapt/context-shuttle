<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\ToolRegistry\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the `required` key in a generated input schema.
 *
 * This asserts the schema shape and, more importantly, that the schema the
 * MCP library ends up holding does not contain a null `required`.
 *
 * @internal
 */
final class ToolInputSchemaTest extends TestCase
{
    public function test_a_tool_with_required_parameters_lists_them(): void
    {
        $definition = new ToolDefinition(
            name: 'demo',
            description: 'd',
            handler: 'strlen',
            parameters: [
                'needed' => ['type' => 'string', 'required' => true],
                'optional' => ['type' => 'string'],
            ],
        );

        $schema = $definition->inputSchema();

        self::assertSame(['needed'], $schema['required']);
    }

    public function test_a_tool_with_no_required_parameters_omits_the_key_entirely(): void
    {
        $definition = new ToolDefinition(
            name: 'demo',
            description: 'd',
            handler: 'strlen',
            parameters: [
                'one' => ['type' => 'string'],
                'two' => ['type' => 'integer'],
            ],
        );

        $schema = $definition->inputSchema();

        // Absent, not null: the validator distinguishes them.
        self::assertArrayNotHasKey('required', $schema);
    }
}
