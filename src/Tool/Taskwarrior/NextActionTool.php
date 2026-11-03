<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

/**
 * `taskwarrior_next_action` — the highest-urgency ready pending task.
 *
 * Equivalent to running `task next` from the CLI. Returns the full task
 * object in taskwarrior's JSON format, or null if there are no pending
 * tasks.
 *
 * @return array<string, mixed>|null
 */
final readonly class NextActionTool
{
    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    public function nextAction(): ?array
    {
        return $this->service->nextAction();
    }
}
