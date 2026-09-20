<?php

declare(strict_types=1);

namespace App\ToolRegistry;

use Psr\Log\LoggerInterface;

use function array_keys;
use function array_values;
use function count;

/**
 * Runtime registry of all tools defined in config/tools/*.yaml.
 *
 * Built once at container compile time so that YAML errors fail the boot,
 * and so every surface (MCP, REST, OpenAPI, health) sees one consistent set.
 */
final class ToolRegistry
{
    /** @var array<string, ToolDefinition> */
    private array $tools = [];

    /** @param list<ToolDefinition> $definitions */
    public function __construct(array $definitions, ?LoggerInterface $logger = null)
    {
        foreach ($definitions as $definition) {
            if (isset($this->tools[$definition->name])) {
                throw new ToolDefinitionException("Duplicate tool name '{$definition->name}'.");
            }
            $this->tools[$definition->name] = $definition;
        }

        $logger?->info('Tool registry initialised.', [
            'tool_count' => count($this->tools),
            'tool_names' => array_keys($this->tools),
        ]);
    }

    /** @return list<ToolDefinition> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?ToolDefinition
    {
        return $this->tools[$name] ?? null;
    }

    public function count(): int
    {
        return count($this->tools);
    }
}
