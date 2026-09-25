<?php

declare(strict_types=1);

namespace App\ToolRegistry;

/**
 * Decides whether a tool that declares a requirement should be served at all.
 *
 * The design calls for the write tools to be **absent** from `tools/list` when
 * no calendar is designated for writing, rather than present and refusing.
 * That means the decision belongs wherever the list is assembled, which is
 * here — one place, so every surface (MCP, REST, OpenAPI, `/ready`) sees the
 * same set rather than four implementations of "is this tool available".
 *
 * It is an interface with one implementation rather than the registry knowing
 * about calendars directly, because the registry is generic and its job is to
 * hold definitions. What "available" means for a given requirement is a
 * question for whoever owns that requirement.
 *
 * The decision must be made **at runtime**, never at container compile time:
 * an env-backed parameter is still the literal `%env(...)%` placeholder while
 * the container is being built, so a compiler pass cannot tell a configured
 * deployment from an unconfigured one — the same trap documented on
 * `CalendarProvider::isConfigured()`, in a new place.
 */
interface ToolAvailability
{
    /**
     * Whether this definition should be listed and invokable.
     *
     * A definition declaring no requirement is always allowed, so an
     * implementation only has to answer for the requirements it owns.
     */
    public function allows(ToolDefinition $definition): bool;
}
