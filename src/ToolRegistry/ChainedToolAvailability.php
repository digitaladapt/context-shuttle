<?php

declare(strict_types=1);

namespace App\ToolRegistry;

use Override;

/**
 * Composes the availability gates: every gate must allow, or the tool is out.
 *
 * There is now more than one thing a tool can require — a calendar designated
 * for writing (`calendar_writes`) and a configured memory store
 * (`memory_store`) — and the answer for each belongs to whoever owns that
 * requirement, not to the registry (see {@see ToolAvailability}). This class
 * is the joining of those answers, so the container binds one
 * `ToolAvailability` and a new family adds a gate rather than a branch here.
 *
 * Each gate answers `true` for every requirement it does not own, which is
 * what makes the composition an AND without any gate needing to know about
 * the others. A requirement that **no** gate owns is also allowed: visibility
 * is only ever narrowed by a gate that actively claims a requirement, so
 * introducing a new token cannot silently hide tools before its gate is
 * wired. The failure mode of a forgotten gate is a listed tool that refuses
 * at call time — the behaviour every tool already has — rather than a tool
 * that disappears without anyone being able to say why.
 */
final readonly class ChainedToolAvailability implements ToolAvailability
{
    /**
     * @param list<ToolAvailability> $gates
     */
    public function __construct(
        private array $gates,
    ) {
    }

    #[Override]
    public function allows(ToolDefinition $definition): bool
    {
        foreach ($this->gates as $gate) {
            if (!$gate->allows($definition)) {
                return false;
            }
        }

        return true;
    }
}
