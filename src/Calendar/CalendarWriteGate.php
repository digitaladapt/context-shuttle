<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Calendar\Domain\EditableCalendar;
use App\ToolRegistry\ToolAvailability;
use App\ToolRegistry\ToolDefinition;
use Override;

/**
 * Keeps the write tools out of the tool surface unless writes are enabled.
 *
 * `CALDAV_EDITABLE_CALENDAR` being empty means no create/update/delete tool is
 * registered at all: a deployment that has not opted in is one where a model
 * cannot see a mutation verb to try. That is a deliberately stronger statement
 * than refusing at call time, and it is the behaviour an operator can verify
 * by looking at `tools/list` rather than by reading a refusal message.
 *
 * It is also deliberate divergence from how the *read* tools behave with no
 * `CALDAV_URL` — those stay listed and explain themselves, which is right for
 * a read tool: answering "not configured" is telling the truth. An
 * unregistered write tool cannot be argued with, which is the point.
 */
final readonly class CalendarWriteGate implements ToolAvailability
{
    public function __construct(
        private EditableCalendar $editableCalendar,
    ) {
    }

    #[Override]
    public function allows(ToolDefinition $definition): bool
    {
        if (!$definition->requiresWrites) {
            return true;
        }

        return $this->editableCalendar->isConfigured();
    }
}
