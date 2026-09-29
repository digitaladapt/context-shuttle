<?php

declare(strict_types=1);

namespace App\MemoryDraft;

use App\ToolRegistry\ToolAvailability;
use App\ToolRegistry\ToolDefinition;
use Override;

/**
 * Keeps the memory tools out of the tool surface unless a store is named.
 *
 * Every `memory_*` tool declares `requires: memory_store`. With no
 * `MEMORY_DRAFT_URL` the family is not registered at all: a deployment that
 * has not opted in is one where a model cannot recall from, write to, or
 * forget in a memory store — including the irreversible `memory_forget`,
 * which is the operation that makes the stronger statement worth having.
 * The same verifiability argument as the calendar write gate applies: an
 * operator checks `tools/list` rather than reading a refusal message.
 *
 * The whole family is gated rather than only its writes, and the divergence
 * from the *read* tools elsewhere (penny-track, vital-pulse and the calendar
 * reads stay listed and explain their missing configuration) is deliberate:
 * those tools read services whose address is incidental to the thing being
 * asked — "list my transactions" has an answer beyond "configure me". Here
 * the URL *is* the resource. A recall from a store nobody named is not a
 * configuration problem to report; it is a memory this deployment does not
 * have, and a tool that always answers "not configured" would be furniture.
 */
final readonly class MemoryDraftGate implements ToolAvailability
{
    public const REQUIREMENT = 'memory_store';

    public function __construct(
        private MemoryDraftEndpoint $endpoint,
    ) {
    }

    #[Override]
    public function allows(ToolDefinition $definition): bool
    {
        if (self::REQUIREMENT !== $definition->requires) {
            // Not this gate's requirement — every other tool passes through
            // untouched, which is what lets the gates compose.
            return true;
        }

        return $this->endpoint->isConfigured();
    }
}
