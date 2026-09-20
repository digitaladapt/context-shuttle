<?php

declare(strict_types=1);

namespace App\Tests\Unit\ToolRegistry;

use App\ToolRegistry\ToolDefinition;
use App\ToolRegistry\ToolDefinitionException;
use App\ToolRegistry\ToolLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function array_map;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @internal
 *
 * @coversNothing
 */
final class ToolLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/shuttle-tools-'.uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmpDir);
    }

    public function test_loads_valid_definition(): void
    {
        $this->writeTool(<<<'YAML'
            name: sample_tool
            description: A sample tool.
            handler: App\Tests\Unit\ToolRegistry\FixtureHandler::run
            parameters:
              city:
                type: string
                description: The city.
                required: true
              days:
                type: integer
                required: false
                default: 3
                minimum: 1
                maximum: 7
            YAML);

        $definitions = (new ToolLoader($this->tmpDir))->load();

        self::assertCount(1, $definitions);
        $definition = $definitions[0];
        self::assertSame('sample_tool', $definition->name);
        self::assertSame('A sample tool.', $definition->description);
        self::assertArrayHasKey('city', $definition->parameters);
    }

    public function test_input_schema_includes_required_and_defaults(): void
    {
        $this->writeTool(<<<'YAML'
            name: sample_tool
            description: A sample tool.
            handler: App\Tests\Unit\ToolRegistry\FixtureHandler::run
            parameters:
              city:
                type: string
                required: true
              days:
                type: integer
                required: false
                default: 3
            YAML);

        $definition = (new ToolLoader($this->tmpDir))->load()[0];
        $schema = $definition->inputSchema();

        self::assertSame('object', $schema['type']);
        self::assertSame(['city'], $schema['required'] ?? null);
        self::assertSame('string', $schema['properties']['city']['type']);
        self::assertSame(3, $schema['properties']['days']['default']);
        self::assertCount(2, $schema['properties']);
    }

    public function test_rejects_invalid_name(): void
    {
        $this->writeTool(<<<'YAML'
            name: BadName
            description: x
            handler: App\Tests\Unit\ToolRegistry\FixtureHandler::run
            YAML);

        $this->expectException(ToolDefinitionException::class);
        $this->expectExceptionMessage('name');

        (new ToolLoader($this->tmpDir))->load();
    }

    public function test_rejects_empty_description(): void
    {
        $this->writeTool(<<<'YAML'
            name: ok_name
            description: ''
            handler: App\Tests\Unit\ToolRegistry\FixtureHandler::run
            YAML);

        $this->expectException(ToolDefinitionException::class);
        $this->expectExceptionMessage('description');

        (new ToolLoader($this->tmpDir))->load();
    }

    public function test_rejects_unknown_handler_class(): void
    {
        $this->writeTool(<<<'YAML'
            name: ok_name
            description: x
            handler: App\Does\Not\Exist::run
            YAML);

        $this->expectException(ToolDefinitionException::class);
        $this->expectExceptionMessage('does not exist');

        (new ToolLoader($this->tmpDir))->load();
    }

    public function test_rejects_unknown_parameter_type(): void
    {
        $this->writeTool(<<<'YAML'
            name: ok_name
            description: x
            handler: App\Tests\Unit\ToolRegistry\FixtureHandler::run
            parameters:
              a:
                type: datetime
            YAML);

        $this->expectException(ToolDefinitionException::class);
        $this->expectExceptionMessage('type');

        (new ToolLoader($this->tmpDir))->load();
    }

    public function test_rejects_unknown_parameter_key(): void
    {
        $this->writeTool(<<<'YAML'
            name: ok_name
            description: x
            handler: App\Tests\Unit\ToolRegistry\FixtureHandler::run
            parameters:
              a:
                type: string
                bogus: 1
            YAML);

        $this->expectException(ToolDefinitionException::class);
        $this->expectExceptionMessage('unknown keys');

        (new ToolLoader($this->tmpDir))->load();
    }

    public function test_rejects_duplicate_names_across_files(): void
    {
        file_put_contents("{$this->tmpDir}/a.yaml", <<<'YAML'
            name: dupe
            description: x
            handler: App\Tests\Unit\ToolRegistry\FixtureHandler::run
            YAML);
        file_put_contents("{$this->tmpDir}/b.yaml", <<<'YAML'
            name: dupe
            description: y
            handler: App\Tests\Unit\ToolRegistry\FixtureHandler::run
            YAML);

        $this->expectException(ToolDefinitionException::class);
        $this->expectExceptionMessage('Duplicate');

        (new ToolLoader($this->tmpDir))->load();
    }

    public function test_rejects_items_on_non_array_type(): void
    {
        $this->writeTool(<<<'YAML'
            name: ok_name
            description: x
            handler: App\Tests\Unit\ToolRegistry\FixtureHandler::run
            parameters:
              a:
                type: string
                items:
                  type: string
            YAML);

        $this->expectException(ToolDefinitionException::class);
        $this->expectExceptionMessage("'items' is only valid");

        (new ToolLoader($this->tmpDir))->load();
    }

    public function test_missing_directory_throws(): void
    {
        $this->expectException(ToolDefinitionException::class);
        $this->expectExceptionMessage('not found');

        (new ToolLoader('/nonexistent/dir/xyz'))->load();
    }

    public function test_real_project_tools_load(): void
    {
        // The project's own config/tools must always be valid.
        $definitions = (new ToolLoader(__DIR__.'/../../../config/tools'))->load();

        $names = array_map(static fn (ToolDefinition $d) => $d->name, $definitions);
        self::assertContains('get_weather', $names);
        self::assertContains('echo', $names);
    }

    private function writeTool(string $yaml): void
    {
        file_put_contents("{$this->tmpDir}/tool.yaml", $yaml);
    }
}
