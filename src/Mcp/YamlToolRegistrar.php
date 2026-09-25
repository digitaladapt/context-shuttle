<?php

declare(strict_types=1);

namespace App\Mcp;

use App\ToolRegistry\ToolDefinition;
use App\ToolRegistry\ToolRegistry;
use PhpMcp\Schema\Tool;
use PhpMcp\Server\Registry;
use Psr\Log\LoggerInterface;

/**
 * Builds and registers YAML tools into the php-mcp/server Registry.
 */
final class YamlToolRegistrar
{
    public function __construct(
        private ToolRegistry $toolRegistry,
        private LoggerInterface $logger,
    ) {
    }

    public function register(Registry $registry): void
    {
        foreach ($this->toolRegistry->all() as $definition) {
            $this->registerOne($registry, $definition);
        }
    }

    private function registerOne(Registry $registry, ToolDefinition $definition): void
    {
        $handler = str_contains($definition->handler, '::')
            ? explode('::', $definition->handler, 2)
            : $definition->handler;

        $schema = $definition->inputSchema();

        /** @var array{type: 'object', properties: array<string, mixed>, required?: list<string>} $inputSchema */
        $inputSchema = [
            'type' => $schema['type'],
            'properties' => $schema['properties'],
        ];

        // `required` is omitted rather than set to null when a tool has no
        // required parameters. `Tool::make` accepts null, but the validator
        // rejects it on every call with "required must be an array of
        // strings", so the key has to be *absent*. This went unnoticed while
        // every tool happened to declare at least one required parameter;
        // `calendar_list_tasks` is the first with none.
        if (isset($schema['required'])) {
            $inputSchema['required'] = $schema['required'];
        }

        $tool = Tool::make(
            name: $definition->name,
            inputSchema: $inputSchema,
            description: $definition->description,
        );

        $registry->registerTool($tool, $handler, true);

        $this->logger->info('Registered MCP tool from YAML.', [
            'tool' => $definition->name,
            'handler' => $definition->handler,
            'parameter_count' => \count($definition->parameters),
        ]);
    }
}
