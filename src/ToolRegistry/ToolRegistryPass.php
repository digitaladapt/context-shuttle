<?php

declare(strict_types=1);

namespace App\ToolRegistry;

use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers ToolRegistry as a container service built from YAML definitions.
 *
 * Each ToolDefinition becomes its own service (the dumper cannot inline
 * objects as literal arguments), and ToolRegistry receives them as an
 * array of references. Loading happens at compile time, so bad YAML fails
 * cache warmup/boot instead of the first request.
 */
final class ToolRegistryPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $toolsDir = $container->getParameterBag()->resolveValue('%kernel.project_dir%/config/tools');

        $definitions = (new ToolLoader($toolsDir))->load();

        $references = [];
        foreach ($definitions as $definition) {
            $serviceId = 'app.tool_definition.'.$definition->name;

            $container->register($serviceId, ToolDefinition::class)
                ->setPublic(false)
                ->setArguments([
                    $definition->name,
                    $definition->description,
                    $definition->handler,
                    $definition->parameters,
                    $definition->defaultLocation,
                    $definition->requiresWrites,
                ])
            ;

            $references[] = new Reference($serviceId);
        }

        $container->register(ToolRegistry::class, ToolRegistry::class)
            ->setPublic(false)
            ->setAutowired(true)
            ->setArguments([$references])
        ;
    }
}
