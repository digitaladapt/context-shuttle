<?php

declare(strict_types=1);

namespace App\Tests\Unit\ToolRegistry;

use App\ToolRegistry\ToolDefinition;
use App\ToolRegistry\ToolLoader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function count;
use function explode;
use function implode;
use function sprintf;
use function str_contains;

/**
 * The MCP dispatcher (php-mcp RegisteredElement::prepareArguments) maps tool
 * arguments to handler parameters by exact name. A YAML parameter named
 * "forecast_days" only reaches a handler parameter named $forecast_days —
 * $forecastDays would silently fall back to its default. Guard that contract
 * for every tool shipped in config/tools.
 *
 * @internal
 *
 * @coversNothing
 */
final class HandlerParameterNamesTest extends TestCase
{
    public function test_yaml_parameter_names_match_handler_signatures(): void
    {
        $definitions = (new ToolLoader(__DIR__.'/../../../config/tools'))->load();

        self::assertGreaterThan(0, count($definitions), 'No tools found in config/tools.');

        $failures = [];

        foreach ($definitions as $definition) {
            $handlerParams = $this->handlerParameterNames($definition);

            foreach ($definition->parameters as $paramName => $_def) {
                if (!isset($handlerParams[$paramName])) {
                    $failures[] = sprintf(
                        "Tool '%s': YAML parameter '%s' has no matching handler parameter (handler has: %s).",
                        $definition->name,
                        $paramName,
                        implode(', ', array_keys($handlerParams)),
                    );
                }
            }
        }

        self::assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * @return array<string, true> parameter names declared by the handler method
     */
    private function handlerParameterNames(ToolDefinition $definition): array
    {
        if (!str_contains($definition->handler, '::')) {
            self::fail(sprintf("Tool '%s': handler '%s' is not a FQCN::method string.", $definition->name, $definition->handler));
        }

        [$class, $method] = explode('::', $definition->handler, 2);

        $reflection = new ReflectionMethod($class, $method);
        $names = [];
        foreach ($reflection->getParameters() as $parameter) {
            $names[$parameter->getName()] = true;
        }

        return $names;
    }
}
