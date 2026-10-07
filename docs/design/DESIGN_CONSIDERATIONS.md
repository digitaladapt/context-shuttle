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

**Correction (streaming).** An earlier revision of this document said this
statelessness meant "no `notifications/*` push, no progress streaming". That was
wrong, and had been wrong since the move to the official SDK. A tool that sends
a notification suspends its fiber, and `StreamableHttpTransport` answers a
suspended fiber with `text/event-stream` rather than a buffered body — so
`notifications/progress`, `notifications/resources/updated` and the rest all
reach the client **during** the call, on a session that exists only for that
call. Statelessness and streaming are orthogonal here: the notification does not
need state to outlive the request, only to reach the client before the request
ends. `count` (see below) exercises this end to end.

The trade-off the statelessness *does* carry is narrower: nothing a tool says is
delivered after its call has returned, because there is no session left to
address. Anything a client should still be able to read later has to be fetched
in a later request — which is exactly why the streaming example puts its payload
in a readable resource instead of in the notifications.

Note that statelessness does not remove the client's obligation to send
`MCP-Protocol-Version`. The streamable HTTP spec requires it on every request
after `initialize`, and browser clients cannot send it unless the server lists
it in `Access-Control-Allow-Headers` — a header the server itself ignores still
has to be advertised, or the preflight fails and the request never happens.
See `CorsSubscriber::ALLOW_HEADERS`.

## Why REST never streams

Both surfaces run the same pipeline, deliberately, so that validation, error
mapping and logging cannot drift between them. Streaming is the one place that
shared pipeline has to behave differently, and it is worth being explicit about
why, because the failure mode of getting it wrong is a 500 rather than a wrong
answer.

MCP's streamable HTTP transport answers a notifying tool with an SSE stream. A
REST caller has one JSON body to return and no way to read an event stream, so
for REST the stream is not a message delivered — it is a message that corrupts
the response. In practice it corrupted it badly: `ToolRestController` read the
response body with `(string) $response->getBody()`, and the SDK's
`CallbackStream::__toString()` *runs* the callback — echoing SSE frames straight
into output — and then returns `''`. Headers were therefore sent part-way
through building the response, and Symfony's own attempt to send its headers
then failed, surfacing as an opaque 500 for **any** tool that notified.

The fix is a declaration, not a special case in the tool: calls made on behalf
of REST carry `_meta: {"app/streaming": false}`, and a tool consults it before
notifying (`App\Mcp\StreamingIntent`). Absent means allowed, so MCP — which does
not send the flag — keeps streaming, and only an explicit `false` disables it.
The flag rides on the request rather than in a shared service, so a worker
serving two callers cannot leak one's choice into the other's call, and a tool
that ignores the flag entirely still works: it just streams to a caller that
cannot read it, which the controller now refuses with a named error rather than a
corrupted body.

`count` is the worked example of both paths, and its REST response reports
`"streamed": false` so a caller can see the transport's choice rather than infer
it from a fast reply. The full story, with transcripts, is in
`docs/examples/streaming-count-tool.md`.

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
- **Resources are declared in code, not YAML.** The tool YAML is a *tool*
  contract — a name, a JSON Schema, typed parameters. A resource has a
  different shape (a URI, a MIME type, no arguments), and rather than stretch
  one format across both, the single resource this project has
  (`count://stream`) is registered where it is served, in
  `CountResourceRegistrar`. A second resource would extend that registrar, not
  the YAML loader.