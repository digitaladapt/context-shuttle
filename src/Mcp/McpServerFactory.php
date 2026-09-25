<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Session\SessionManager;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Assembles the official MCP SDK's server for use inside the Symfony request
 * cycle.
 *
 * Unlike the library this replaces, the SDK does not own a socket and does not
 * run an event loop: `Server::run()` takes a PSR-7 request-bearing transport
 * and returns a PSR-7 response. The Symfony controller supplies the request and
 * sends the response, which is why there is no transport code in this class.
 *
 * Sessions are the one piece of state the SDK expects to outlive a request.
 * They are backed by a dedicated cache pool rather than an in-memory store,
 * because the handshake (`initialize` → later `tools/call`) legitimately spans
 * two PHP requests, under FrankenPHP as much as under any other SAPI.
 */
final class McpServerFactory
{
    /** One hour, matching the SDK's documented default. */
    private const SESSION_TTL_SECONDS = 3600;

    public function __construct(
        private readonly YamlToolRegistrar $registrar,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Psr16Cache $sessionCache,
        private readonly string $serverName,
        private readonly string $serverVersion,
    ) {
    }

    public function build(): Server
    {
        $builder = Server::builder()
            ->setServerInfo($this->serverName, $this->serverVersion)
            ->setInstructions(
                'Tools are defined by YAML files in config/tools/. '
                .'Each tool is available via MCP and REST with identical schemas.',
            )
            // The container is what makes handler resolution work for services
            // with constructor dependencies (CalDavClient, HTTP clients, YAML
            // parameters) rather than only for classes with no constructor.
            ->setContainer($this->container)
            ->setLogger($this->logger)
            ->setEventDispatcher($this->eventDispatcher)
            // Keeps tool-authored error messages in front of the caller; see
            // the class docblock for why the SDK needs help with this.
            ->setReferenceHandler(new ToolFailureTranslatingReferenceHandler(
                new ReferenceHandler($this->container),
            ))
            ->setSession($this->sessionStore());

        $this->registrar->declareOn($builder);

        return $builder->build();
    }

    /**
     * Backing store for MCP sessions.
     *
     * A named pool (`cache.mcp_sessions`) rather than `cache.app`, so sessions
     * can be flushed, relocated to Redis, or given their own TTL without
     * disturbing the application cache.
     */
    public function sessionStore(): Psr16SessionStore
    {
        return new Psr16SessionStore(
            cache: $this->sessionCache,
            prefix: 'mcp-session-',
            // Bounds the cache, not the protocol: the SDK's own
            // SessionManager expiry is what decides protocol-level lifetime.
            ttl: self::SESSION_TTL_SECONDS,
        );
    }

    /**
     * Mints and destroys sessions for callers that drive the server
     * in-process — the REST surface, which runs one `tools/call` per HTTP
     * request without a client handshake.
     */
    public function sessionManager(): SessionManager
    {
        return new SessionManager($this->sessionStore(), $this->logger);
    }
}
