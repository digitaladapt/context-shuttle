<?php

declare(strict_types=1);

namespace App\ToolRegistry;

use RuntimeException;

/**
 * Thrown when a tool YAML definition is invalid.
 *
 * Deliberately a boot-time failure: an invalid tool definition must never
 * silently degrade the MCP or REST surface.
 */
final class ToolDefinitionException extends RuntimeException {}
