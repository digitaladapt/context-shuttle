<?php

declare(strict_types=1);

namespace App\Mcp;

use App\ToolRegistry\ToolDefinition;
use App\ToolRegistry\ToolRegistry;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;

/**
 * Registers every YAML-defined tool with the official MCP SDK.
 *
 * The YAML file stays the single source of truth: its `parameters` block
 * becomes the tool's JSON Schema verbatim (so the wire contract cannot drift
 * from the file that documents it), and its `handler` string becomes the
 * callable the SDK resolves through the PSR-11 container at call time.
 *
 * Two entry points, because two callers need a different one:
 *
 *  - {@see declareOn()} — up front on the Server builder. This is the shape the
 *    `POST /mcp` endpoint uses.
 *  - {@see registerInto()} — onto an already-built registry, for a caller that
 *    did not configure that server itself.
 */
final class YamlToolRegistrar
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Declare every YAML tool on a server builder.
     *
     * `addTool()` takes an explicit `inputSchema`, so what goes on the wire is
     * exactly the validated YAML rather than a schema derived from PHP types.
     * The handler array is resolved through the container by the SDK, which is
     * what lets tool services receive their constructor dependencies.
     */
    public function declareOn(Builder $builder): void
    {
        foreach ($this->toolRegistry->all() as $definition) {
            $builder->addTool(
                handler: $this->handlerFor($definition),
                name: $definition->name,
                description: $definition->description,
                annotations: $this->annotationsFor($definition),
                inputSchema: $definition->inputSchema(),
            );

            $this->logger->info('Registered MCP tool from YAML.', [
                'tool' => $definition->name,
                'handler' => $definition->handler,
                'parameter_count' => \count($definition->parameters),
            ]);
        }
    }

    /**
     * Register every YAML tool onto an existing registry.
     */
    public function registerInto(RegistryInterface $registry): void
    {
        foreach ($this->toolRegistry->all() as $definition) {
            $registry->registerTool(
                $this->toolFor($definition),
                $this->handlerFor($definition),
            );
        }
    }

    /**
     * `ToolDefinition::$handler` is `FQCN::method`, or a bare invokable FQCN.
     *
     * Both collapse to the `[class, method]` form. That form matters: the SDK's
     * ReferenceHandler treats a *string* handler as a global function name, and
     * only the array form goes through the container.
     *
     * Typed as `array{string, string}` rather than `array{class-string, string}`
     * because the SDK accepts `callable|array|string`, so either shape is
     * accepted — and a class-string annotation here would only have to be
     * restated for the fixer.
     *
     * @return array{string, string}
     */
    private function handlerFor(ToolDefinition $definition): array
    {
        if (str_contains($definition->handler, '::')) {
            [$class, $method] = explode('::', $definition->handler, 2);

            return [$class, $method];
        }

        return [$definition->handler, '__invoke'];
    }

    /**
     * A tool as the SDK models it: a name, a JSON Schema, and hints.
     */
    private function toolFor(ToolDefinition $definition): Tool
    {
        return new Tool(
            name: $definition->name,
            title: null,
            inputSchema: $definition->inputSchema(),
            description: $definition->description,
            annotations: $this->annotationsFor($definition),
        );
    }

    /**
     * Read-only tools are marked as such so a client UI — and a model deciding
     * whether to ask for confirmation — can tell "list events" from
     * "send alert" without parsing prose.
     *
     * Derived from the tool name rather than its YAML so that a new tool
     * definition cannot forget to declare its write behaviour: an unlisted tool
     * gets no hints at all, which reads as "unknown", never as "safe".
     */
    private function annotationsFor(ToolDefinition $definition): ?ToolAnnotations
    {
        return match ($definition->name) {
            'echo' => new ToolAnnotations(
                title: 'Echo',
                readOnlyHint: true,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: false,
            ),
            'get_weather' => new ToolAnnotations(
                title: 'Get weather',
                readOnlyHint: true,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: true,
            ),
            'get_transactions' => new ToolAnnotations(
                title: 'Get transactions',
                readOnlyHint: true,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: true,
            ),
            'get_health_logs' => new ToolAnnotations(
                title: 'Get health logs',
                readOnlyHint: true,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: true,
            ),
            'list_events' => new ToolAnnotations(
                title: 'List calendar events',
                readOnlyHint: true,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: true,
            ),
            // send_alert talks to the outside world and is not idempotent: the
            // one tool whose hints should make a confirmation prompt look right.
            'send_alert' => new ToolAnnotations(
                title: 'Send alert',
                readOnlyHint: false,
                destructiveHint: false,
                idempotentHint: false,
                openWorldHint: true,
            ),
            default => null,
        };
    }
}
