<?php

declare(strict_types=1);

namespace App\Mcp;

use App\ToolRegistry\ToolRegistry;
use PhpMcp\Schema\Implementation;
use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Protocol;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Session\ArraySessionHandler;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Session\SubscriptionManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;

/**
 * Assembles the php-mcp/server Protocol + Dispatcher stack for use inside
 * the Symfony request cycle (no ReactPHP socket server, no event loop run).
 *
 * The Protocol is transport-agnostic: our McpController feeds it messages,
 * and a per-request CaptureTransport captures its response.
 */
final class McpServerFactory
{
    public function __construct(
        private ToolRegistry $toolRegistry,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private LoggerInterface $invocationLogger,
        private string $serverName,
        private string $serverVersion,
    ) {}

    public function build(): McpStack
    {
        $capabilities = ServerCapabilities::make(
            tools: true,
            toolsListChanged: false,
            resources: false,
            prompts: false,
            logging: false,
        );

        $loop = Loop::get();

        $configuration = new Configuration(
            serverInfo: Implementation::make($this->serverName, $this->serverVersion),
            capabilities: $capabilities,
            logger: $this->logger,
            loop: $loop,
            cache: null,
            container: $this->container,
            paginationLimit: 100,
            instructions: 'Tools are defined by YAML files in config/tools/. Each tool is available via MCP and REST with identical schemas.',
        );

        $sessionManager = new SessionManager(new ArraySessionHandler(3600), $this->logger, $loop);

        $registry = new Registry($this->logger);

        $dispatcher = new LoggingDispatcher(
            $configuration,
            $registry,
            new SubscriptionManager($this->logger),
            null,
            $this->invocationLogger,
        );

        $protocol = new Protocol($configuration, $registry, $sessionManager, $dispatcher);

        // Register YAML tools into the library registry.
        (new YamlToolRegistrar($this->toolRegistry, $this->logger))->register($registry);

        return new McpStack($protocol, $sessionManager);
    }
}
