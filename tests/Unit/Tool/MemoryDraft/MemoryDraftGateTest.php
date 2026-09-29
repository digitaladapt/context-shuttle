<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\MemoryDraft;

use App\MemoryDraft\MemoryDraftEndpoint;
use App\MemoryDraft\MemoryDraftGate;
use App\ToolRegistry\ChainedToolAvailability;
use App\ToolRegistry\ToolDefinition;
use App\ToolRegistry\ToolLoader;
use App\ToolRegistry\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Whether the memory tools exist at all.
 *
 * This is the enforcement of the family's first rule: with no store named,
 * the `memory_*` tools are **absent** from the tool surface rather than
 * present and refusing. That matters most for `memory_forget` — a model
 * cannot attempt an irreversible delete it cannot see — and it is the
 * property an operator can verify by looking at `tools/list` rather than by
 * reading a refusal message.
 *
 * The runtime half is load-bearing: an env-backed container parameter is
 * still the literal `%env(...)%` placeholder while the container is being
 * built, so anything decided at compile time would report every unconfigured
 * deployment as having a store.
 *
 * @internal
 *
 * @covers \App\MemoryDraft\MemoryDraftGate
 * @covers \App\ToolRegistry\ChainedToolAvailability
 * @covers \App\ToolRegistry\ToolRegistry
 */
final class MemoryDraftGateTest extends TestCase
{
    private function definition(string $name, ?string $requires = null): ToolDefinition
    {
        return new ToolDefinition(
            name: $name,
            description: 'd',
            handler: 'strlen',
            parameters: [],
            requires: $requires,
        );
    }

    /**
     * @return list<ToolDefinition>
     */
    private function memoryDefinitions(): array
    {
        return [
            $this->definition('memory_recall', 'memory_store'),
            $this->definition('memory_remember', 'memory_store'),
            $this->definition('memory_keys', 'memory_store'),
            $this->definition('memory_stats', 'memory_store'),
            $this->definition('memory_forget', 'memory_store'),
        ];
    }

    public function test_the_whole_family_is_absent_when_no_store_is_named(): void
    {
        $registry = new ToolRegistry(
            [...$this->memoryDefinitions(), $this->definition('echo')],
            new MemoryDraftGate(new MemoryDraftEndpoint('')),
        );

        foreach ($registry->names() as $name) {
            self::assertStringNotContainsString('memory_', $name);
        }

        // Narrowing, not disabling: everything else is untouched.
        self::assertContains('echo', $registry->names());
    }

    public function test_the_family_is_present_once_a_store_is_named(): void
    {
        $registry = new ToolRegistry(
            $this->memoryDefinitions(),
            new MemoryDraftGate(new MemoryDraftEndpoint('https://memory.example.com')),
        );

        self::assertSame(
            ['memory_recall', 'memory_remember', 'memory_keys', 'memory_stats', 'memory_forget'],
            $registry->names(),
        );
        self::assertSame(5, $registry->count());
    }

    public function test_a_whitespace_only_value_is_still_unconfigured(): void
    {
        // `MEMORY_DRAFT_URL=" "` in an env file is a plausible typo, and
        // treating it as configured would serve five tools that cannot work.
        $registry = new ToolRegistry(
            $this->memoryDefinitions(),
            new MemoryDraftGate(new MemoryDraftEndpoint('   ')),
        );

        self::assertSame([], $registry->names());
    }

    /**
     * An unavailable tool must be indistinguishable from one that does not
     * exist.
     *
     * The REST surface answers "tool not found" with a list of what is
     * available, so a caller reaching for a memory tool on a deployment with
     * no store learns the same thing as one that invented a name. Any other
     * answer would leak the existence of a tool the deployment deliberately
     * does not serve.
     */
    public function test_an_unavailable_tool_is_not_reachable_by_name(): void
    {
        $registry = new ToolRegistry(
            $this->memoryDefinitions(),
            new MemoryDraftGate(new MemoryDraftEndpoint('')),
        );

        self::assertFalse($registry->has('memory_forget'));
        self::assertNull($registry->get('memory_forget'));
        self::assertSame(0, $registry->count());
    }

    public function test_a_gate_allows_tools_that_declare_someone_elses_requirement(): void
    {
        // Composition depends on each gate ignoring requirements it does not
        // own — otherwise adding a second gate would hide the first one's
        // tools.
        $gate = new MemoryDraftGate(new MemoryDraftEndpoint(''));

        self::assertTrue($gate->allows($this->definition('calendar_create_event', 'calendar_writes')));
        self::assertTrue($gate->allows($this->definition('echo')));
        self::assertFalse($gate->allows($this->definition('memory_recall', 'memory_store')));
    }

    /**
     * A requirement no gate claims must not hide a tool.
     *
     * The failure mode of a forgotten gate has to be a tool that is listed
     * and refuses (what every tool already does), not one that vanishes with
     * no gate able to explain why.
     */
    public function test_a_requirement_no_gate_claims_is_allowed(): void
    {
        $chain = new ChainedToolAvailability([new MemoryDraftGate(new MemoryDraftEndpoint(''))]);

        self::assertTrue($chain->allows($this->definition('mystery', 'nobody_owns_this')));
    }

    public function test_the_chain_denies_a_tool_when_any_owning_gate_denies_it(): void
    {
        $chain = new ChainedToolAvailability([
            new MemoryDraftGate(new MemoryDraftEndpoint('https://memory.example.com')),
            new MemoryDraftGate(new MemoryDraftEndpoint('')),
        ]);

        self::assertFalse($chain->allows($this->definition('memory_recall', 'memory_store')));
    }

    public function test_every_shipped_memory_tool_declares_the_requirement(): void
    {
        $definitions = (new ToolLoader(__DIR__.'/../../../../config/tools'))->load();

        $memory = array_values(array_filter(
            $definitions,
            static fn (ToolDefinition $definition): bool => str_starts_with($definition->name, 'memory_'),
        ));

        self::assertCount(5, $memory, 'the memory family should be five tools in config/tools');

        foreach ($memory as $definition) {
            self::assertSame(
                MemoryDraftGate::REQUIREMENT,
                $definition->requires,
                \sprintf('tool "%s" belongs to a store this deployment may not have, so it must declare requires: memory_store', $definition->name),
            );
        }
    }
}
