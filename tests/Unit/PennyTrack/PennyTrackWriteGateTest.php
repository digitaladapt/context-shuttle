<?php

declare(strict_types=1);

namespace App\Tests\Unit\PennyTrack;

use App\PennyTrack\PennyTrackCredentials;
use App\PennyTrack\PennyTrackWriteGate;
use App\ToolRegistry\ToolDefinition;
use App\ToolRegistry\ToolLoader;
use App\ToolRegistry\ToolRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Whether the transaction-creation tool exists at all.
 *
 * This is the enforcement of the family's first rule: unless a write-capable
 * key has been named, `create_transaction` is **absent** from the tool surface
 * rather than present and refusing. That is the property an operator can verify
 * by reading `tools/list`, and it matters more here than anywhere else in the
 * project — this is the only tool that writes to a ledger.
 *
 * The runtime half is load-bearing: an env-backed container parameter is still
 * the literal `%env(...)%` placeholder while the container is being built, so
 * anything decided at compile time would report every read-only deployment as
 * able to write.
 *
 * @internal
 *
 * @covers \App\PennyTrack\PennyTrackWriteGate
 * @covers \App\PennyTrack\PennyTrackCredentials
 * @covers \App\ToolRegistry\ChainedToolAvailability
 * @covers \App\ToolRegistry\ToolRegistry
 */
final class PennyTrackWriteGateTest extends TestCase
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
    private function definitions(): array
    {
        return [
            $this->definition('create_transaction', PennyTrackWriteGate::REQUIREMENT),
            // The read tool takes no requirement: it stays listed on an
            // unconfigured deployment and explains itself.
            $this->definition('get_transactions'),
            $this->definition('echo'),
        ];
    }

    public function test_the_tool_is_absent_when_no_write_key_is_named(): void
    {
        $registry = new ToolRegistry(
            $this->definitions(),
            new PennyTrackWriteGate(new PennyTrackCredentials('read-key', '')),
        );

        self::assertNotContains('create_transaction', $registry->names());
        self::assertFalse($registry->has('create_transaction'));
        self::assertNull($registry->get('create_transaction'));

        // Narrowing, not disabling: the read tool is untouched, and this is the
        // divergence from `get_transactions` that the gate exists to express.
        self::assertContains('get_transactions', $registry->names());
        self::assertContains('echo', $registry->names());
    }

    public function test_the_tool_is_present_once_a_write_key_is_named(): void
    {
        $registry = new ToolRegistry(
            $this->definitions(),
            new PennyTrackWriteGate(new PennyTrackCredentials('read-key', 'write-key')),
        );

        self::assertContains('create_transaction', $registry->names());
        self::assertSame(3, $registry->count());
    }

    /**
     * A write key alone is enough to serve the write tool: the two keys are
     * independent, and requiring the read key too would be inventing a
     * dependency that no request actually has.
     */
    public function test_a_write_key_alone_serves_the_write_tool(): void
    {
        $registry = new ToolRegistry(
            [$this->definition('create_transaction', PennyTrackWriteGate::REQUIREMENT)],
            new PennyTrackWriteGate(new PennyTrackCredentials('', 'write-key')),
        );

        self::assertSame(['create_transaction'], $registry->names());
    }

    /**
     * A whitespace-only value is a plausible typo in an env file, and treating
     * it as configured would serve a tool that cannot authenticate.
     */
    public function test_a_whitespace_only_write_key_is_still_unconfigured(): void
    {
        $credentials = new PennyTrackCredentials('read-key', "  \t ");

        self::assertFalse($credentials->hasWriteKey());

        $registry = new ToolRegistry(
            $this->definitions(),
            new PennyTrackWriteGate($credentials),
        );

        self::assertNotContains('create_transaction', $registry->names());
    }

    public function test_the_gate_allows_tools_that_declare_someone_elses_requirement(): void
    {
        // Composition depends on each gate ignoring requirements it does not
        // own — otherwise adding a third gate would hide the first two's tools.
        $gate = new PennyTrackWriteGate(new PennyTrackCredentials('', ''));

        self::assertTrue($gate->allows($this->definition('calendar_create_event', 'calendar_writes')));
        self::assertTrue($gate->allows($this->definition('memory_recall', 'memory_store')));
        self::assertTrue($gate->allows($this->definition('echo')));
        self::assertFalse($gate->allows($this->definition('create_transaction', PennyTrackWriteGate::REQUIREMENT)));
    }

    public function test_every_shipped_write_tool_declares_the_requirement(): void
    {
        $definitions = (new ToolLoader(__DIR__.'/../../../config/tools'))->load();

        $creates = array_values(array_filter(
            $definitions,
            static fn (ToolDefinition $definition): bool => 'create_transaction' === $definition->name,
        ));

        self::assertCount(1, $creates, 'create_transaction should exist in config/tools');
        self::assertSame(PennyTrackWriteGate::REQUIREMENT, $creates[0]->requires);
    }

    /**
     * And the converse: the read tool must not declare it, or enabling writes
     * would change whether a caller can read anything.
     */
    public function test_the_read_tool_does_not_require_writes(): void
    {
        $definitions = (new ToolLoader(__DIR__.'/../../../config/tools'))->load();

        foreach ($definitions as $definition) {
            if ('get_transactions' === $definition->name) {
                self::assertNull(
                    $definition->requires,
                    'get_transactions only reads, so it must not require writes',
                );
            }
        }
    }

    public function test_the_credentials_report_themselves_honestly(): void
    {
        $both = new PennyTrackCredentials('read', 'write');
        self::assertTrue($both->hasReadKey());
        self::assertTrue($both->hasWriteKey());
        self::assertSame('read', $both->readKeyOrFail());
        self::assertSame('write', $both->writeKeyOrFail());

        $readOnly = new PennyTrackCredentials('read', '');
        self::assertTrue($readOnly->hasReadKey());
        self::assertFalse($readOnly->hasWriteKey());
        self::assertSame('read', $readOnly->readKeyOrFail());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PENNYTRACK_WRITE_API_KEY');

        $readOnly->writeKeyOrFail();
    }
}
