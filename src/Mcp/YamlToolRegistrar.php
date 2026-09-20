<?php

declare(strict_types=1);

namespace App\Mcp;

use App\ToolRegistry\ToolDefinition;
use App\ToolRegistry\ToolRegistry;
use PhpMcp\Schema\Tool;
use PhpMcp\Server\Registry;
use Psr\Log\LoggerInterface;

use function count;
use function explode;
use function str_contains;

/**
 * Builds and registers YAML tools into the php-mcp/server Registry.
 */
final class YamlToolRegistrar
{
    public function __construct(
        private ToolRegistry $toolRegistry,
        private LoggerInterface $logger,
    ) {}

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

        /** @var array{type: 'object', properties: array<string, mixed>, required: null|array<string>} $inputSchema */
        $inputSchema = [
            'type' => $schema['type'],
            'properties' => $schema['properties'],
            'required' => $schema['required'] ?? null,
        ];

        $tool = Tool::make(
            name: $definition->name,
            inputSchema: $inputSchema,
            description: $definition->description,
        );

        $registry->registerTool($tool, $handler, true);

        $this->logger->info('Registered MCP tool from YAML.', [
            'tool' => $definition->name,
            'handler' => $definition->handler,
            'parameter_count' => count($definition->parameters),
        ]);
    }
}
