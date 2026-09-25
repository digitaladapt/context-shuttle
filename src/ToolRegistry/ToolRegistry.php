<?php

declare(strict_types=1);

namespace App\ToolRegistry;

use Psr\Log\LoggerInterface;

/**
 * Runtime registry of all tools defined in config/tools/*.yaml.
 *
 * Definitions are built once at container compile time so that YAML errors
 * fail the boot — but **which of them are served is decided per request**,
 * because a tool can depend on configuration that is only knowable at
 * runtime. An env-backed container parameter is still the literal
 * `%env(...)%` placeholder while the container is being built, so filtering
 * at compile time would conclude that a deployment with no calendar
 * configured has one.
 *
 * Every surface (MCP, REST, OpenAPI, health) reads the same filtered set, so
 * "available" cannot mean four different things.
 */
final class ToolRegistry
{
    /** @var list<ToolDefinition> */
    private array $definitions;

    /**
     * @param list<ToolDefinition> $definitions
     */
    public function __construct(
        array $definitions,
        /**
         * Decides which definitions this deployment serves. `null` serves
         * everything, which is what a hand-built registry in a test wants.
         */
        private readonly ?ToolAvailability $availability = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $seen = [];

        foreach ($definitions as $definition) {
            if (isset($seen[$definition->name])) {
                throw new ToolDefinitionException("Duplicate tool name '{$definition->name}'.");
            }

            $seen[$definition->name] = true;
        }

        $this->definitions = $definitions;

        if (null !== $this->logger) {
            $served = $this->served();
            $gated = array_values(array_diff(
                array_map(static fn (ToolDefinition $definition): string => $definition->name, $this->definitions),
                array_map(static fn (ToolDefinition $definition): string => $definition->name, $served),
            ));

            $this->logger->info('Tool registry initialised.', [
                'tool_count' => \count($served),
                'tool_names' => array_map(static fn (ToolDefinition $definition): string => $definition->name, $served),
                // Named explicitly, because a tool that is absent from the
                // list is otherwise indistinguishable from one that was never
                // defined — and the first question an operator asks when a
                // write tool is missing is which of those happened.
                'unavailable_tools' => $gated,
            ]);
        }
    }

    /**
     * The definitions this deployment serves, in declaration order.
     *
     * Filtered on every call rather than cached, because the answer depends on
     * configuration that can differ between requests in a long-running
     * process, and a cached answer would be a stale one.
     *
     * @return list<ToolDefinition>
     */
    private function served(): array
    {
        if (null === $this->availability) {
            return $this->definitions;
        }

        return array_values(array_filter(
            $this->definitions,
            fn (ToolDefinition $definition): bool => $this->availability->allows($definition),
        ));
    }

    /** @return list<ToolDefinition> */
    public function all(): array
    {
        return $this->served();
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (ToolDefinition $definition): string => $definition->name, $this->served());
    }

    /**
     * Whether a tool is available **and** named.
     *
     * An unavailable tool is indistinguishable from an unknown one on
     * purpose: the REST layer already answers "not found" with the list of
     * available tools, so a caller reaching for a write tool on a read-only
     * deployment is told the same thing as one that invented a name — both
     * are tools this deployment does not have.
     */
    public function has(string $name): bool
    {
        return null !== $this->get($name);
    }

    public function get(string $name): ?ToolDefinition
    {
        foreach ($this->served() as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * How many tools this deployment serves.
     *
     * Reported by `/ready`, so an operator can see the effect of enabling
     * writes as a change in this number.
     */
    public function count(): int
    {
        return \count($this->served());
    }
}
