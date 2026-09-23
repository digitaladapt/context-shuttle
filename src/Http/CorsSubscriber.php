<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_filter;
use function array_map;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function str_starts_with;

/**
 * CORS handling for browser clients (e.g. llama.cpp's web UI).
 *
 * Authorization is an allowed request header, which forces the credentialed
 * CORS mode: browsers ignore "Access-Control-Allow-Origin: *" when
 * credentials are involved, so the request's Origin must be echoed back
 * verbatim, together with "Access-Control-Allow-Credentials: true". The
 * "Vary: Origin" header keeps shared caches from serving one origin's
 * allowed response to a different origin.
 *
 * The allowlist is the full set of headers a spec-compliant MCP client
 * sends, not just the ones this server reads: a header the browser
 * considers non-safelisted must be listed even when the server ignores it,
 * or the preflight fails and the browser never issues the request.
 *
 * Preflights (OPTIONS + Origin) are answered centrally here, before the
 * router runs, and every response is decorated on the way out — so all
 * endpoints (/mcp, /tools, /openapi.json, /health, /ready) get identical
 * CORS behavior.
 *
 * Every response also carries a Cross-Origin-Resource-Policy (CORP) header.
 * CORP guards "no-cors" subresource loads — <img>, <script>, fonts,
 * `fetch(..., {mode: 'no-cors'})` — which never carry an Origin header and
 * are therefore invisible to the CORS handshake. The policy is fixed
 * server-side (never negotiated per request) and defaults to "same-site";
 * CORS_RESOURCE_POLICY relaxes or tightens it. See CrossOriginResourcePolicy.
 *
 * @internal
 */
final class CorsSubscriber implements EventSubscriberInterface
{
    private const ALLOW_METHODS = 'GET, POST, OPTIONS, DELETE';

    /**
     * Request headers a browser client may send cross-origin.
     *
     * MCP-Protocol-Version is mandatory for the streamable HTTP transport:
     * the spec (2025-06-18, "Protocol Version Header") requires clients to
     * send it on all requests after initialize. The official TypeScript SDK
     * (used by llama.cpp's web UI, the MCP Inspector, and others) only starts
     * sending it once initialize has negotiated a version, so the first
     * preflight passes and every later one fails with Firefox's "CORS Missing
     * Allow Header" if the header is not listed here.
     */
    private const ALLOW_HEADERS = 'Content-Type, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID, Authorization';
    private const EXPOSE_HEADERS = 'Mcp-Session-Id';
    private const MAX_AGE = '600';

    public function __construct(
        #[Autowire('%env(enum:App\Http\CrossOriginResourcePolicy:default:cors_resource_policy:CORS_RESOURCE_POLICY)%')]
        private CrossOriginResourcePolicy $resourcePolicy = CrossOriginResourcePolicy::SameSite,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority above the router (priority 32) so preflights never
            // reach routing or controllers.
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    /**
     * Answer CORS preflights before routing.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->isMethod('OPTIONS') || null === $this->origin($request)) {
            return;
        }

        $response = new Response(status: 204);
        $response->headers->set('Access-Control-Allow-Origin', (string) $this->origin($request));
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
        $response->headers->set('Access-Control-Max-Age', self::MAX_AGE);
        $this->applyResourcePolicy($response);
        $this->addVaryOrigin($response);

        $event->setResponse($response);
    }

    /**
     * Decorate every response with the credentialed-CORS headers.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $origin = $this->origin($request);
        $response = $event->getResponse();

        // CORP is origin-independent: no-cors embeds send no Origin header at
        // all, so the header is applied before the Origin check below.
        $this->applyResourcePolicy($response);

        if (null === $origin) {
            // Same-origin (or non-browser) request: no CORS headers needed,
            // but keep Vary: Origin so caches treat responses per-origin.
            $this->addVaryOrigin($response);

            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Expose-Headers', self::EXPOSE_HEADERS);
        $this->addVaryOrigin($response);
    }

    /**
     * The Origin of the request, or null when absent. "null" (e.g. from
     * sandboxed frames or some CLI clients) is treated as present: echoing
     * it is still more correct than a wildcard browsers will reject.
     */
    private function origin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');

        if (null === $origin || '' === $origin) {
            return null;
        }

        // No header-injection: drop origins containing control characters.
        if (1 === preg_match('/[\r\n\0]/', $origin)) {
            return null;
        }

        // Scheme is required by the CORS spec; strip opaque junk.
        if (!str_starts_with($origin, 'http://') && !str_starts_with($origin, 'https://') && 'null' !== $origin) {
            return null;
        }

        return $origin;
    }

    private function applyResourcePolicy(Response $response): void
    {
        $response->headers->set('Cross-Origin-Resource-Policy', $this->resourcePolicy->value);
    }

    private function addVaryOrigin(Response $response): void
    {
        $existing = (string) $response->headers->get('Vary', '');
        $parts = array_filter(array_map('trim', explode(',', $existing)));

        if (!in_array('Origin', $parts, true)) {
            $parts[] = 'Origin';
        }

        $response->headers->set('Vary', implode(', ', $parts));
    }
}
