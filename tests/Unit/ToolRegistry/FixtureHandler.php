<?php

declare(strict_types=1);

namespace App\Tests\Unit\ToolRegistry;

/**
 * Fixture handler referenced by ToolLoaderTest YAML.
 */
final class FixtureHandler
{
    /**
     * @return array<string, mixed>
     */
    public function run(string $input = ''): array
    {
        return ['input' => $input];
    }
}
