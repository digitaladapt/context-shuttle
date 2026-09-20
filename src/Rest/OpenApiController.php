<?php

declare(strict_types=1);

namespace App\Rest;

use App\ToolRegistry\ToolRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;

use function array_map;
use function array_merge;
use function explode;
use function implode;

/**
 * Generates an OpenAPI 3.1 document describing every registered tool.
 *
 * Each YAML tool becomes a POST /tools/{name} operation whose requestBody
 * schema is derived from the tool's JSON Schema parameters.
 */
final class OpenApiController
{
    public function __construct(
        private ToolRegistry $registry,
        private string $serverName,
        private string $serverVersion,
    ) {}

    public function __invoke(): JsonResponse
    {
        $paths = [];
        $schemas = [];

        foreach ($this->registry->all() as $tool) {
            $schemaName = $this->schemaNameFor($tool->name);

            $schemas[$schemaName] = array_merge(
                ['description' => $tool->description],
                $tool->inputSchema(),
            );

            $paths['/tools/'.$tool->name] = [
                'post' => [
                    'operationId' => 'invoke_'.$tool->name,
                    'summary' => $tool->description,
                    'description' => "Invokes the '{$tool->name}' tool. The same tool is available via MCP at POST /mcp.",
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/'.$schemaName],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Tool result',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'tool' => ['type' => 'string'],
                                            'result' => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '404' => ['description' => 'Unknown tool'],
                        '422' => ['description' => 'Invalid arguments (schema validation failed)'],
                    ],
                ],
            ];
        }

        $paths['/tools'] = [
            'get' => [
                'operationId' => 'list_tools',
                'summary' => 'List all registered tools',
                'responses' => [
                    '200' => [
                        'description' => 'Tool list',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'tools' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'name' => ['type' => 'string'],
                                                    'description' => ['type' => 'string'],
                                                    'parameters' => ['type' => 'object'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $document = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $this->serverName,
                'version' => $this->serverVersion,
                'description' => 'YAML-driven tool gateway. Every tool defined in config/tools/*.yaml is exposed via REST and MCP with identical schemas.',
            ],
            'servers' => [['url' => '/']],
            'paths' => $paths,
            'components' => ['schemas' => $schemas],
        ];

        return new JsonResponse($document);
    }

    private function schemaNameFor(string $toolName): string
    {
        return implode('', array_map('ucfirst', explode('_', $toolName))).'Input';
    }
}
