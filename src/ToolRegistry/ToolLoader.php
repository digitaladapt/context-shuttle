<?php

declare(strict_types=1);

namespace App\ToolRegistry;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and validates tool definitions from YAML files in a directory.
 *
 * Every YAML file in the configured directory defines exactly one tool.
 * Validation is deliberately strict and fails at boot so that a malformed
 * tool definition can never reach runtime in any environment.
 */
final class ToolLoader
{
    private const ALLOWED_PARAMETER_TYPES = ['string', 'integer', 'number', 'boolean', 'array'];

    private const ALLOWED_PARAMETER_KEYS = [
        'type', 'description', 'required', 'default', 'enum', 'format',
        'items', 'pattern', 'minimum', 'maximum',
    ];

    public function __construct(
        private string $toolsDir,
    ) {
    }

    /**
     * @return list<ToolDefinition>
     *
     * @throws ToolDefinitionException on any invalid or duplicate definition
     */
    public function load(): array
    {
        $realDir = realpath($this->toolsDir);
        if (false === $realDir || !is_dir($realDir)) {
            throw new ToolDefinitionException("Tools directory not found: {$this->toolsDir}");
        }

        $files = glob($realDir.'/*.yaml') ?: [];
        sort($files);

        $definitions = [];
        $seenNames = [];

        foreach ($files as $file) {
            $definition = $this->loadFile($file);
            if (isset($seenNames[$definition->name])) {
                throw new ToolDefinitionException(\sprintf("Duplicate tool name '%s' (defined in %s and %s)", $definition->name, $seenNames[$definition->name], $file));
            }
            $seenNames[$definition->name] = $file;
            $definitions[] = $definition;
        }

        return $definitions;
    }

    private function loadFile(string $file): ToolDefinition
    {
        $relative = basename($file);

        try {
            $raw = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new ToolDefinitionException("Invalid YAML in {$relative}: {$e->getMessage()}", 0, $e);
        }

        if (!\is_array($raw)) {
            throw new ToolDefinitionException("Tool file {$relative} must be a YAML mapping.");
        }

        $name = $raw['name'] ?? null;
        if (!\is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
            throw new ToolDefinitionException("Tool file {$relative}: 'name' must be a lowercase snake_case string (max 64 chars).");
        }

        $description = $raw['description'] ?? null;
        if (!\is_string($description) || '' === $description) {
            throw new ToolDefinitionException("Tool file {$relative}: 'description' must be a non-empty string.");
        }

        $handler = $raw['handler'] ?? null;
        if (!\is_string($handler) || !$this->isValidHandlerString($handler)) {
            throw new ToolDefinitionException("Tool file {$relative}: 'handler' must be an FQCN or FQCN::method string.");
        }
        if (!class_exists($handler) && !str_contains($handler, '::')) {
            throw new ToolDefinitionException("Tool file {$relative}: handler class '{$handler}' does not exist.");
        }
        if (str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);
            if (!class_exists($class)) {
                throw new ToolDefinitionException("Tool file {$relative}: handler class '{$class}' does not exist.");
            }
            if (!method_exists($class, $method)) {
                throw new ToolDefinitionException("Tool file {$relative}: handler method '{$handler}' does not exist.");
            }
        }

        $parameters = $raw['parameters'] ?? [];
        if (!\is_array($parameters)) {
            throw new ToolDefinitionException("Tool file {$relative}: 'parameters' must be a mapping of parameter definitions.");
        }

        /** @var array<string, array{type: string, description?: string, required?: bool, default?: mixed, enum?: list<mixed>, format?: string, items?: array<string, mixed>, pattern?: string, minimum?: float|int, maximum?: float|int}> $validated */
        $validated = [];
        foreach ($parameters as $paramName => $def) {
            if (!\is_string($paramName) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $paramName)) {
                throw new ToolDefinitionException("Tool file {$relative}: parameter name '{$paramName}' must be lowercase snake_case (max 64 chars).");
            }
            if (!\is_array($def)) {
                throw new ToolDefinitionException("Tool file {$relative}: parameter '{$paramName}' must be a mapping.");
            }

            $type = $def['type'] ?? null;
            if (!\is_string($type) || !\in_array($type, self::ALLOWED_PARAMETER_TYPES, true)) {
                throw new ToolDefinitionException(\sprintf("Tool file %s: parameter '%s': 'type' must be one of: %s", $relative, $paramName, implode(', ', self::ALLOWED_PARAMETER_TYPES)));
            }

            $unknown = array_diff(array_keys($def), self::ALLOWED_PARAMETER_KEYS);
            if ([] !== $unknown) {
                throw new ToolDefinitionException("Tool file {$relative}: parameter '{$paramName}': unknown keys: ".implode(', ', $unknown));
            }

            if (\array_key_exists('required', $def) && !\is_bool($def['required'])) {
                throw new ToolDefinitionException("Tool file {$relative}: parameter '{$paramName}': 'required' must be a boolean.");
            }

            if (\array_key_exists('enum', $def)) {
                if (!\is_array($def['enum'])) {
                    throw new ToolDefinitionException("Tool file {$relative}: parameter '{$paramName}': 'enum' must be a list.");
                }
                if ([] === $def['enum']) {
                    throw new ToolDefinitionException("Tool file {$relative}: parameter '{$paramName}': 'enum' must not be empty.");
                }
                foreach ($def['enum'] as $value) {
                    if (!\is_scalar($value) && null !== $value) {
                        throw new ToolDefinitionException("Tool file {$relative}: parameter '{$paramName}': enum values must be scalars or null (got ".get_debug_type($value).').');
                    }
                }
            }

            if (\array_key_exists('items', $def)) {
                if ('array' !== $type) {
                    throw new ToolDefinitionException("Tool file {$relative}: parameter '{$paramName}': 'items' is only valid for array-typed parameters.");
                }
                if (!\is_array($def['items']) || !isset($def['items']['type'])) {
                    throw new ToolDefinitionException("Tool file {$relative}: parameter '{$paramName}': 'items' must be a mapping with a 'type' key.");
                }
                if (!\in_array($def['items']['type'], self::ALLOWED_PARAMETER_TYPES, true)) {
                    throw new ToolDefinitionException(\sprintf("Tool file %s: parameter '%s': 'items.type' must be one of: %s", $relative, $paramName, implode(', ', self::ALLOWED_PARAMETER_TYPES)));
                }
            }

            $validated[$paramName] = $def;
        }

        $defaultLocation = null;
        if (\array_key_exists('default_location', $raw)) {
            $loc = $raw['default_location'];
            if (
                !\is_array($loc)
                || !isset($loc['lat'], $loc['lon'])
                || !is_numeric($loc['lat'])
                || !is_numeric($loc['lon'])
            ) {
                throw new ToolDefinitionException("Tool file {$relative}: 'default_location' must be a mapping with numeric 'lat' and 'lon' keys.");
            }
            $defaultLocation = ['lat' => (float) $loc['lat'], 'lon' => (float) $loc['lon']];
        }

        /** @var array<string, array{type: string, description?: string, required?: bool, default?: mixed, enum?: list<mixed>, format?: string, items?: array<string, mixed>, pattern?: string, minimum?: float|int, maximum?: float|int}> $validatedParams */
        $validatedParams = $validated;

        return new ToolDefinition(
            name: $name,
            description: $description,
            handler: $handler,
            parameters: $validatedParams,
            defaultLocation: $defaultLocation,
        );
    }

    /**
     * A handler is either an FQCN of an invokable class, or FQCN::method.
     * Segments must be letters/underscores; namespaces use backslashes.
     */
    private function isValidHandlerString(string $handler): bool
    {
        // Split FQCN and optional ::method, then validate each segment
        // without regex backslash escaping pitfalls.
        $class = $handler;
        if (str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
                return false;
            }
        }

        foreach (explode('\\', $class) as $segment) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                return false;
            }
        }

        return true;
    }
}
