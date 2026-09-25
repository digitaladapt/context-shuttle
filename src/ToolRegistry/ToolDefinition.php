<?php

declare(strict_types=1);

namespace App\ToolRegistry;

/**
 * A fully-validated tool definition loaded from a YAML file.
 *
 * Each definition describes one callable "tool" that is exposed identically
 * through both the MCP endpoint and the REST/OpenAPI surface. The YAML file
 * is the single source of truth; PHP handler classes implement behaviour.
 */
final readonly class ToolDefinition
{
    /**
     * @param string $name        unique snake_case tool name
     * @param string $description human-readable description (shown to LLMs and in OpenAPI)
     * @param string $handler     FQCN::method or invokable FQCN that implements the tool
     * @param array<string, array{
     *     type: string,
     *     description?: string,
     *     required?: bool,
     *     default?: mixed,
     *     enum?: list<mixed>,
     *     format?: string,
     *     items?: array<string, mixed>,
     *     pattern?: string,
     *     minimum?: float|int,
     *     maximum?: float|int
     * }> $parameters JSON-Schema properties keyed by parameter name
     * @param array{lat: float, lon: float}|null $defaultLocation special-cased default for the Weather tool
     * @param bool                               $requiresWrites  the tool can only exist when writes
     *                                                            are enabled; absent from the tool
     *                                                            list otherwise, rather than listed
     *                                                            and refusing. A requirement rather
     *                                                            than a permission: it says what the
     *                                                            tool needs, and `ToolAvailability`
     *                                                            decides whether the deployment has it.
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $handler,
        public array $parameters,
        public ?array $defaultLocation = null,
        public bool $requiresWrites = false,
    ) {
    }

    /**
     * JSON Schema (draft 2020-12 style object schema) for the tool input.
     *
     * Consumed directly by the MCP SDK's manual tool registration and by the
     * REST layer's validation; also the source for the OpenAPI requestBody.
     *
     * @return array{type: 'object', properties: array<string, mixed>, additionalProperties: false, required?: non-empty-list<string>}
     */
    public function inputSchema(): array
    {
        $required = [];
        $properties = [];

        foreach ($this->parameters as $name => $def) {
            $property = [
                'type' => $def['type'],
            ];
            foreach (['description', 'default', 'enum', 'format', 'items', 'pattern', 'minimum', 'maximum'] as $key) {
                if (\array_key_exists($key, $def)) {
                    $property[$key] = $def[$key];
                }
            }
            $properties[$name] = $property;

            if ($def['required'] ?? false) {
                $required[] = $name;
            }
        }

        if ([] === $required) {
            return [
                'type' => 'object',
                'properties' => $properties,
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
            'required' => $required,
        ];
    }
}
