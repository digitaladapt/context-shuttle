<?php

declare(strict_types=1);

namespace App\PennyTrack;

use App\ToolRegistry\ToolAvailability;
use App\ToolRegistry\ToolDefinition;
use Override;

/**
 * Keeps the transaction-creation tool out of the tool surface unless the
 * deployment has said it may write to the ledger.
 *
 * The opt-in is `PENNYTRACK_WRITE_API_KEY` being set, and it is a *separate*
 * variable from `PENNYTRACK_API_KEY` for a reason that is specific to
 * penny-track rather than a matter of taste: the two keys are different
 * capabilities behind the same header. A read-only key authenticates every
 * `GET` and is refused for anything that mutates the ledger, and penny-track
 * stores only a hash of a key — so there is no way for this deployment to ask
 * "may this key write?" without attempting a write. Naming a write key is
 * therefore the only statement that can be trusted, because it is the only one
 * that carries the answer rather than implying it.
 *
 * The consequence is the property the calendar write gate and the memory gate
 * both have, and the reason this is a gate rather than a runtime check: on a
 * deployment configured only for reading, `create_transaction` is **not
 * registered**. A model cannot attempt a mutation it cannot see, and an
 * operator can verify that by reading `tools/list` rather than by reading a
 * refusal.
 *
 * It is also, deliberately, divergence from how `get_transactions` behaves with
 * no `PENNYTRACK_URL` — that one stays listed and explains itself, which is
 * right for a read: "list my transactions" has an answer beyond "configure me".
 * A write has no such answer, so an unconfigured write tool is not a tool that
 * reports a problem, it is noise that invites a call which cannot succeed.
 */
final readonly class PennyTrackWriteGate implements ToolAvailability
{
    public const REQUIREMENT = 'pennytrack_writes';

    public function __construct(
        private PennyTrackCredentials $credentials,
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

        return $this->credentials->hasWriteKey();
    }
}
