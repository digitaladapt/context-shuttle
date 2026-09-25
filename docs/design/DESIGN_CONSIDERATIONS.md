# DESIGN CONSIDERATIONS — context-shuttle

## Why the Symfony request cycle instead of a ReactPHP server

`php-mcp/server` ships ReactPHP-based transports that own a socket and an
event loop. That model fights a Symfony deployment: two HTTP stacks, two
process models, no shared container, no shared logging.

The library explicitly anticipates this: `Server::listen()` documents that
framework integrations should use `getProtocol()` and bind their own
transport. We bind a `CaptureTransport` that records the response the
`Protocol` produces, and the controller returns it as the HTTP response.
ReactPHP promises resolve synchronously in the absence of a running loop,
so no event-loop execution is required per request.

## Why stateless-per-request MCP

The MCP streamable HTTP transport normally mints a session at `initialize`
and requires `Mcp-Session-Id` on subsequent requests. For a stateless
gateway the spec allows every request to stand alone. Our controller marks
each ephemeral session initialized before processing, so `tools/call` works
without a prior `initialize`. Clients that do send `initialize` first get a
correct response — they just don't need it.

Trade-off: no `notifications/*` push, no progress streaming. Acceptable for
a tool registry whose tools are request/response.

Note that statelessness does not remove the client's obligation to send
`MCP-Protocol-Version`. The streamable HTTP spec requires it on every request
after `initialize`, and browser clients cannot send it unless the server lists
it in `Access-Control-Allow-Headers` — a header the server itself ignores still
has to be advertised, or the preflight fails and the request never happens.
See `CorsSubscriber::ALLOW_HEADERS`.

## Why YAML

The registry's value is that **adding a tool is a leaf change**: one YAML
file plus one handler class. No route edits, no schema duplication between
MCP and REST, no OpenAPI annotation. The YAML is the single source of truth
for names, descriptions, parameters, defaults and validation constraints.

Boot-time validation (compiler pass) means a typo is a deploy failure, not
a runtime 500 on some undiscovered code path.

## Why REST shares the MCP pipeline

Two implementations would guarantee drift: different validation, different
error shapes, different logging. Routing REST through the same
`Protocol::processMessage()` costs one object construction per request and
buys identical behaviour. The REST controller is a thin adapter: parse body
→ apply YAML defaults → build `CallToolRequest` → translate the JSON-RPC
response to plain JSON.

## Why integrations start by looking for someone else's MCP server

MCP is now common enough that a growing number of the services worth
connecting to **ship their own server**: Blinko's is part of the
application, and SparkyFitness advertises "MCP server + bring your own
LLM" as a feature. For those, the integration cost is configuration, not
code — an API token and a URL, registered directly against the harness.

A native tool in this project buys four things a foreign server does not:
one namespace with the other tools; one structured invocation log; the
allowlist / folder-gating patterns this project enforces on reads and
writes; and the shared REST + OpenAPI surface. That is a real list — it is
just not free, and it is not always worth paying. **Build a native tool
when nothing else exists, or when those guarantees are the point.** The
decision is recorded per integration in `ROADMAP.md`.

The clearest example of the trade going the other way: Blinko already
bundles a web-search tool, so a `web_search` tool here would be rebuilding
a thing that already exists, merely to own it. Deferred instead.

## Read and report, do not operate

The integrations are deliberately *observational*. Tools may gather state
and raise alerts; they do not restart, stop, or reconfigure running
services. This is not a hedge about model reliability — it is about what a
mistaken call costs and whether it can be undone.

- **No container restarts or docker control.** The services these tools
  report on run in the same compose projects a control tool would have
  access to. A restart loop is how a reporting tool becomes the outage it
  is reporting.
- **No network-infrastructure access.** A Wi-Fi router and a thermostat
  are the class of device where a wrong call removes the connection that
  would be needed to notice and fix it — including, in the router's case,
  the connection to the machine making the call.

Where the same information can be obtained read-only (health lists, disk
and thermal readouts, alert history), that stays on the table. Control
does not. See the non-goal in `ROADMAP.md`.

## Rejected alternatives

- **Symfony MCP bundles** — none were found for Symfony 8 at the time.
- **Standing up the library's own HTTP transport behind Symfony** — two
  sockets, port management, no container access for handlers.
- **Generating PHP route/controller code from YAML** — runtime-generated
  code defeats cache warming and static analysis (PHPStan).
- **psr-7 bridge packages** — we only adapt outgoing requests; a small
  explicit adapter using `guzzlehttp/psr7` (already a dependency of the
  ecosystem) avoids the framework-bundle's PSR-7 wiring complexity.

## Known limitations (v1)

- Per-request `McpServerFactory::build()` rebuilds the Protocol/Registry.
  For a handful of tools this is cheap (reflection + registration), but
  caching the assembled stack per-process is an obvious optimization
  (ROADMAP). Careful: the Registry is stateful (sessions), so caching
  requires per-request session isolation.
- `additionalProperties: false` on input schemas means strict parameter
  checking; clients sending extra keys get a 422. Deliberate.
- Tool handler services must be `public: true` in services.yaml (PSR-11
  resolution from the compiled container). Documented per-service.