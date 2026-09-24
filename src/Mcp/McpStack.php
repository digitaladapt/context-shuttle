<?php

declare(strict_types=1);

namespace App\Mcp;

use PhpMcp\Server\Protocol;
use PhpMcp\Server\Session\SessionManager;

/**
 * The per-request MCP stack: a Protocol wired to its own SessionManager.
 *
 * Returned by McpServerFactory::build() so the controller can create and
 * destroy sessions through the same SessionManager the Protocol uses.
 */
final class McpStack
{
    public function __construct(
        public readonly Protocol $protocol,
        public readonly SessionManager $sessionManager,
    ) {
    }
}
