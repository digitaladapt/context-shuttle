<?php

declare(strict_types=1);

namespace App\Calendar\Domain;

/**
 * Reasons a calendar object could not be turned into rows.
 *
 * A tool result reports a *count* of these, never the detail: an operator
 * finds the specifics in the `mcp_invocation` log, and a model is not asked
 * to parse them. The UID and calendar travel with the reason so that the
 * log line is actionable on its own.
 */
final readonly class CalendarProblem
{
    public function __construct(
        public string $reason,
        public ?string $uid = null,
        public ?string $calendarHref = null,
        public ?string $href = null,
    ) {
    }

    /**
     * The operator-facing detail that goes to the log — never to a tool
     * result.
     *
     * @return array<string, string>
     */
    public function toLogContext(): array
    {
        return array_filter(
            [
                'reason' => $this->reason,
                'uid' => $this->uid,
                'calendar' => $this->calendarHref,
                'href' => $this->href,
            ],
            static fn (?string $value): bool => null !== $value && '' !== $value,
        );
    }
}
