# MCP SDK migration — `php-mcp/server` (fork) → `mcp/sdk` (official)

**Status:** plan + spike · branch `feat/official-mcp-sdk`
**Target:** `mcp/sdk` pinned to **`0.8.1`** (see "Pin and watch" below)

## Why

Two reasons, in order of weight.

1. **We are maintaining a fork of an abandoned library.** Upstream
   [`php-mcp/server`](https://github.com/php-mcp/server) was last pushed
   **2025-08-09**; `php-mcp/client` last **2025-05-07**. We pin a private fork
   (`public/php-mcp-server`) whose entire divergence from upstream is one
   commit — `ee43737`, coercing `null` ids to `''` so error construction stops
   throwing — plus a parked branch that only loosens two composer constraints
   so the library installs on Symfony 8. That patch-per-release treadmill has
   no terminus.

2. **The official SDK is the successor, not a stranger.** Its own README
   credits PHP-MCP as its starting point, and PHP-MCP's author (Kyrian
   Obikwelu) is listed as an author of `mcp/sdk`. It is maintained by
   Christopher Hertel with the PHP Foundation and the Symfony project, adopts
   Symfony's coding standards and (from 1.0) its BC promise, and is measured
   weekly against the official conformance suite. Converging on it is
   converging on upstream.

Secondary, but real: the SDK is **PSR-7 in, PSR-7 out** rather than a
socket-owning ReactPHP server, which is a better shape for a Symfony app that
already has a request cycle. Its default middleware stack, however, is a
deployment trap — see "Landmines".

## What the official SDK gives us that the fork does not

| Area | Fork (`php-mcp/server` @ 3.3.99) | Official (`mcp/sdk` 0.8.1) |
|---|---|---|
| HTTP integration | Owns a ReactPHP socket; `listen()` blocks | PSR-7 request in → `ResponseInterface` out |
| Protocol eras | Handshake only | Handshake **and** the stateless `2026-07-28` revision, one endpoint, auto-classified |
| Stateless mode | `stateless` constructor flag | First-class `StatelessProtocol` / `StatelessHttpTransport` |
| Argument validation | `opis/json-schema` | `opis/json-schema` (same) |
| Outbound notifications | `SubscriptionManager` | `NotificationBusInterface` + `subscriptions/listen` |
| PSR-14 events | Custom dispatcher decorator | Native `RequestEvent`/`ResponseEvent`/`ErrorEvent` |
| OAuth | — | Serious work, partially complete (client side incomplete) |
| ReactPHP | Hard dependency | **None** |

That last row is the tidiest win: the SDK's only IO is PSR-18/PSR-17, which we
already have (`guzzlehttp/psr7` is already a direct dependency for exactly the
PSR-7 conversion our controller does by hand).

## Scope

Seven files under `src/Mcp` + `src/Rest`, ~500 lines. Business logic
(`ToolRegistry`, `ToolLoader`, every `src/Tool/**` handler, the OpenAPI
generator, health) is untouched.

| File | Now | After |
|---|---|---|
| `src/Mcp/McpController.php` | 160 | ~110 — Symfony↔PSR-7 bridge + `run()` |
| `src/Mcp/McpServerFactory.php` | 81 | ~140 — `Server::builder()` assembly |
| `src/Mcp/YamlToolRegistrar.php` | 60 | ~95 — registers into the Builder |
| `src/Mcp/LoggingDispatcher.php` | 107 | → `InvocationLogListener.php`, ~130 |
| `src/Mcp/CaptureTransport.php` | 71 | **deleted** |
| `src/Mcp/McpStack.php` | 23 | **deleted** |
| `src/Rest/ToolRestController.php` | 190 | ~200 — pipeline call swapped |

Deleting `CaptureTransport` and `McpStack` outright removes the two classes
that exist purely to work around the fork's transport-owns-the-socket design.

## API mapping (verified against 0.8.1, not from memory)

| Fork | Official |
|---|---|
| `new Configuration(serverInfo:…)` | `Server::builder()->setServerInfo($name,$version)` |
| `Tool::make(name:…, inputSchema:…)` | `->addTool(handler:…, name:…, description:…, inputSchema:…)` |
| `Registry::registerTool($tool, $handler)` | same `addTool()` call — handler is a parameter |
| `Dispatcher` / `LoggingDispatcher` | `->setEventDispatcher($psr14)` |
| `ServerCapabilities::make(tools: true)`, `Protocol`, `SessionManager`, `ArraySessionHandler` | internal to `Builder`; only `->setSession()` is ours |
| `Protocol::processMessage()` + `CaptureTransport` | `$server->run(new StreamableHttpTransport($psr7Request))` |

Tool registration maps almost one-to-one because `addTool()` already accepts
an explicit `inputSchema` array — which is exactly what
`ToolDefinition::inputSchema()` produces from YAML.

**Handler resolution keeps working.** `ReferenceHandler::getClassInstance()`
calls `$container->has($class)` / `->get($class)` before falling back to
`new $class()`, so `'App\Tool\X\XTool::method'` resolvers still receive
container-built services. That is what `YamlToolRegistrar` will pass through.

## Design decisions

### 1. Sessions become real, via the Symfony cache

The fork faked statelessness: `McpController` minted a session per request,
marked it `initialized` by hand, processed, then threw it away. The official
SDK **requires a session for every non-`initialize` request** (verified:
`400` with `"A valid session id is REQUIRED for non-initialize requests."`).

That is the spec behaving correctly, and the fix is small: `Psr16SessionStore`
over Symfony's cache. `psr/simple-cache` 3.0 and `symfony/cache` 8.1 are
**already installed**, so this adds no dependency.

Verified across two independent `Server` instances sharing only the store —
i.e. two separate PHP requests:

```
req1 initialize  → 200, Mcp-Session-Id: 6708bbee…
req2 tools/call  → 200  (fresh Server, session resolved from cache)
```

A dedicated `cache.mcp_sessions` pool keeps MCP sessions out of `cache.app`,
so the pool can be cleared or moved to Redis without collateral.

> **Consequence for tests and clients.** `POST /mcp` with `tools/call` and no
> session now returns `400` unless it is preceded by `initialize`. This is a
> deliberate behaviour change matching the spec: a streamable-HTTP client that
> does not handshake is not a client. `tests/Integration/ToolPipelineTest` and
> `CorsTest` must initialize first and thread `Mcp-Session-Id` through.

### 2. Stateless-per-request is retired, not re-implemented

SPEC.md's "no persistent MCP sessions (stateless per request by design)" was a
constraint the fork imposed. The SDK serves the *sender* stateless model
natively on `2026-07-28` — but that era requires a per-request `_meta`
envelope (`protocolVersion`, `clientCapabilities`) that the clients we care
about (llama.cpp, the MCP Inspector) do not send yet. So we ship the handshake
era now; `2026-07-28` arrives for free behind the same endpoint when clients
catch up, with no code change. SPEC.md is amended to say so.

### 3. Logging moves from a dispatcher decorator to PSR-14

`LoggingDispatcher` wrapped `Dispatcher::handleToolCall()` to log one JSON line
per invocation. The SDK dispatches `ResponseEvent` (success) and `ErrorEvent`
(failure) per request, both carrying the `Request`. The listener reproduces the
same fields — `request_id`, `tool`, `arguments` (sanitized/truncated at the
same 2048 bytes), `duration_ms`, result preview (1024 bytes) — plus a
`Mcp-Session-Id` value in place of the old synthetic request id.

Verified: `ResponseEvent` carries the `CallToolResult` with `isError`, and
`ErrorEvent` fires for schema violations (`-32602`). Note that with the
official SDK a *tool that throws* still comes back as a `ResponseEvent` with
`isError: true` (the SDK catches it), so both handlers are needed and neither
alone is sufficient.

### 4. REST keeps sharing the pipeline

The REST surface's promise is "identical validation, casting, error mapping and
logging by construction". It keeps that promise by calling the same
`Server::run()` path: mint a session in-process, run `tools/call`, discard.
Same pipeline, same log lines, no second code path.

### 5. Tool failures are translated once, centrally

See Landmine 3. `ToolFailureTranslatingReferenceHandler` wraps the SDK's
`ReferenceHandler` and converts tool-raised exceptions into `ToolCallException`
so their messages survive. It is conservative about what it touches:
`ToolCallException`, `RegistryException` and `InvalidArgumentException` pass
through, and anything raised outside a tool call (no `_session` in the argument
bag) is rethrown untouched.

### 6. `middleware: []` — disabling the SDK's edge stack (see Landmines)

### 7. Pin and watch

Pinned exactly: `"mcp/sdk": "0.8.1"` (no `^`, no `~`). Pre-1.0 the SDK treats
every digit as BC-breakable and logs each break with `[BC Break]`; 0.8 → 0.9
is expected to move things. An exact pin makes an SDK bump a deliberate commit
rather than a surprise in someone's `composer update`.

`.env.example` and `docs/design/ROADMAP.md` gain a standing note, and this
document carries the full checklist:

**Watch list — check monthly (`composer outdated mcp/sdk`) and before any intentional bump:**

- `ROADMAP.md` and `CHANGELOG.md` upstream
- Whether **1.0.0 has shipped** — that is the moment Symfony's BC promise
  starts applying and the pin can relax to `^1.0`
- The OAuth client work (unblocks Tier 2; irrelevant to us, relevant to
  whether the project is healthy)
- The conformance table in their README (`2026-07-28` server score)
- When we bump: re-run `php bin/phpunit` and `bin/console lint:container`,
  then re-check the four landmines below, because each is a *default* that the
  SDK is free to change

## Landmines (all verified by execution)

1. **`DnsRebindingProtectionMiddleware` 403s every non-localhost request.**
   The default stack validates `Origin`, else `Host`, against
   `['localhost','127.0.0.1','[::1]']`. Measured against a real hostname:

   | | default stack | `middleware: []` |
   |---|---|---|
   | `Host: shuttle.example.com` | **403** | 200 |
   | + `Origin: https://ui.example.com` | **403** | 200 |

   Since we are fronted by a reverse proxy that enforces `Host`, and
   `CorsSubscriber` already owns CORS, we pass `middleware: []`. Without this
   the migration would pass every test and fail in production.

2. **Sessions are required** (see decision 1). Not a bug, but it changes test
   and client expectations.

3. **A thrown tool exception loses its message.** `CallToolHandler` renders
   `ToolCallException` as `CallToolResult(isError: true)` carrying the message,
   but renders *anything else* as a bare `-32603 "Error while executing tool"` —
   the real text goes to the log and never reaches the client.

   Every tool in this project throws a plain `RuntimeException` with an
   actionable message ("PENNYTRACK_URL is not configured…"). Without
   intervention the operator's best clue about a misconfigured deployment would
   vanish from the protocol response. Fixed once, centrally, by
   `ToolFailureTranslatingReferenceHandler` — not by editing nine tools, which
   would also have broken their unit tests and had to be remembered for every
   tool added later. A regression test pins the behaviour.

4. **Error HTTP statuses differ from the old implementation.** A parse error is
   now `200` with `-32700` in the body (the HTTP request was fine; the JSON-RPC
   message was not), and an unknown tool is `-32602` rather than `-32601`
   (`tools/call` exists; the name in its params does not). Only transport-level
   failures — missing session, wrong method — change the HTTP status. The
   existing tests were updated, and SPEC.md's error table now has an HTTP column.

5. **`StatelessHttpTransport` requires the `_meta` envelope.** Unlike the
   handshake era, modern requests carry `protocolVersion` and
   `clientCapabilities` per request; absent, the request is refused. Another
   reason not to jump eras yet.

6. **The agent-facing interface changes.** `Dispatcher`/`Registry` become
   `Builder`/`addTool()`. Anything that reached into the fork's internals
   breaks loudly at boot, which is the outcome we want.

## Ordering (as implemented)

1. `composer.json`: dropped the `repositories` VCS block and `php-mcp/server`,
   added `mcp/sdk: 0.8.1` and `psr/simple-cache`; added the `cache.mcp_sessions`
   pool.
2. `YamlToolRegistrar` + `McpServerFactory` — server assembly first, since
   nothing else can be tested until a server builds.
3. `ToolFailureTranslatingReferenceHandler` — before the listener, because the
   listener's `isError` branch depends on failures arriving as results.
4. `InvocationLogListener` — log parity restored.
5. `McpController` — PSR-7 bridge, `middleware: []`.
6. Deleted `CaptureTransport`, `McpStack`, `LoggingDispatcher`; updated
   `services.yaml` and the cache pool.
7. `ToolRestController` — same `Server::run()` path on a per-request session.
8. Tests: `McpSessionTrait` added; `ToolPipelineTest` and `CorsTest`
   handshake first; new coverage for session enforcement and error-message
   preservation.

## Verification

The three gates from AGENTS.md — `php bin/phpunit`,
`vendor/bin/phpstan analyse`, `vendor/bin/php-cs-fixer fix --dry-run --diff` —
run in CI. They could not be run in the sandbox this work was done in (no
PHP 8.5, no Docker daemon), so every SDK interaction was instead **executed**
against `mcp/sdk` 0.8.1 on PHP 8.4 before being written down.

That process is worth keeping, because it caught four things that reading the
docs would not have: the localhost-only middleware, the discarded tool error
messages, the `200`/`-32700` parse-error status, and a duplicated `Host` header
in the Symfony→PSR-7 conversion (from passing both `server->all()` and the
header bag to Guzzle's factory). It also confirmed the design end to end — the
migrated `McpServerFactory` and `YamlToolRegistrar` driving a real tool service
resolved from a container, returning structured content, with both log paths
firing.

Covered by execution: builder assembly, container-backed `[class, method]`
handlers, `inputSchema` round-trip, `initialize` → `tools/call` with
`Mcp-Session-Id`, cross-instance session persistence through `Psr16SessionStore`,
`-32602` on bad arguments, `-32700` on malformed JSON, the `middleware: []` fix,
both event classes, the `ToolCallException` translation, and the
Symfony→PSR-7→Symfony round trip.

## Rollback

Revert the branch. `main` is untouched, the fork still exists at
`public/php-mcp-server`, and no schema, migration or external contract moves.
