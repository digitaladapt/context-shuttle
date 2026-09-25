<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\ToolRegistry\ToolDefinition;
use PhpMcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the `required` key in a generated input schema.
 *
 * `Tool::make()` accepts `required: null`, but the validator rejects it on
 * every call with "required must be an array of strings" — so the key has to
 * be *absent* when a tool declares no required parameters. The bug was
 * invisible until `calendar_list_tasks`, the first tool with none: every
 * other tool happened to require at least one parameter, so `required` was
 * always a non-empty list and the null path was never taken.
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

    public function test_the_key_never_reaches_the_mcp_library_as_null(): void
    {
        $definition = new ToolDefinition(
            name: 'demo',
            description: 'd',
            handler: 'strlen',
            parameters: [
                'one' => ['type' => 'string'],
            ],
        );

        $schema = $definition->inputSchema();

        // Mirrors what YamlToolRegistrar builds for the MCP registry: the
        // `required` key is only carried across when it is actually present.
        $inputSchema = [
            'type' => $schema['type'],
            'properties' => $schema['properties'],
        ];

        if (isset($schema['required'])) {
            $inputSchema['required'] = $schema['required'];
        }

        $tool = Tool::make(name: $definition->name, inputSchema: $inputSchema, description: 'd');

        $encoded = json_encode($tool->inputSchema, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('"required":null', $encoded);
        self::assertArrayNotHasKey('required', (array) json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR));
    }
}
