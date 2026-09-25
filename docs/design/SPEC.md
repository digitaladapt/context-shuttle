# SPEC — context-shuttle

**Status:** v1 shipped · PHP 8.5 · Symfony 8.1 · `mcp/sdk` (official PHP SDK, pinned)

## Goal

A tool registry where **tools are YAML files**, served over two protocols
simultaneously:

- **MCP** (Model Context Protocol) at `POST /mcp` — streamable HTTP transport,
  JSON response mode. Sessions exist for the handshake; see "MCP sessions".
- **REST** at `POST /tools/{name}` with a generated **OpenAPI 3.1** spec at
  `/openapi.json`.

One execution pipeline, one validation layer, one structured invocation log.

## Non-goals (v1)

- No templating, no Twig, no frontend (api-gateway archetype, §3.2.1).
- No authn/authz on tool endpoints (single-tenant deployment behind a
  reverse proxy; see ROADMAP).
- No resources/prompts MCP primitives — tools only (v1).
- No MCP session state beyond the handshake: a client may `initialize` and
  reuse its session, but nothing survives the session cache's TTL.

## Architecture

```
config/tools/*.yaml
        │ (boot-time load + strict validation)
        ▼
   ToolRegistry ──────────────┐
        │                     │
        ▼                     ▼
  McpServerFactory      OpenApiController
  (Mcp\Server Builder)        (OpenAPI 3.1)
        │                     │
        ▼                     │
   ┌────────────┐             │
   │ POST /mcp  │             │
   └────────────┘             │
   ┌────────────────┐         │
   │ POST /tools/x  │─────────┘ (same Server::run pipeline)
   └────────────────┘
        │
        ▼
  InvocationLogListener
  (JSON line per invocation: request_id, tool, args, result)
```

### Key decisions

| Decision | Rationale |
|---|---|
| The official `mcp/sdk`, not the `php-mcp/server` fork | Upstream is unmaintained (last push 2025-08-09); the official SDK is its successor, maintained with the PHP Foundation and Symfony. The SDK is also PSR-7 in / PSR-7 out rather than a socket-owning server, which fits a Symfony request cycle. Full reasoning: `MCP_SDK_MIGRATION.md`. |
| Cache-backed MCP sessions | The SDK requires a session for every non-`initialize` request. `Psr16SessionStore` over a dedicated `cache.mcp_sessions` pool carries the handshake across PHP requests. |
| REST routes through the MCP pipeline | `POST /tools/{name}` runs the same `Server::run()` path on a session minted for the request — validation, argument casting, error mapping and logging are identical across protocols by construction. |
| YAML validated at compile time | A compiler pass (`ToolRegistryPass`) loads `config/tools/*.yaml` during container compilation; bad YAML fails boot, never a request. |
| Manual JSON Schemas | `Builder::addTool()` accepts an explicit `inputSchema` — the YAML `parameters` block becomes the tool's JSON Schema verbatim. |
| Tool handlers are public services | The SDK resolves `[class, method]` handlers through PSR-11; private/inlined services break that. |
| OpenAPI generated from the same YAML | No second schema to drift; the OpenAPI requestBody is the tool's inputSchema. |
| `middleware: []` on the transport | The SDK's default edge stack includes DNS-rebinding protection allowlisted to localhost only, which would reject every request in production. CORS is owned by `CorsSubscriber`, host validation by the reverse proxy. |
| Tool failures translated to `ToolCallException` | The SDK replaces any other exception with a generic `-32603`. A central `ToolFailureTranslatingReferenceHandler` preserves the actionable messages tools already throw. |

## MCP sessions

Sessions are **required**. A `tools/call` or `tools/list` sent without one is
refused with `400` and JSON-RPC `-32600`, per the streamable-HTTP spec. Clients
must `initialize` first and send `Mcp-Session-Id` on every later request.

This is a change from v1.0.0, which minted a throwaway session per request and
therefore served handshake-less callers. That was an artifact of the previous
library, not a design choice worth preserving: the spec requires the handshake,
and a client that skips it cannot be distinguished from one that lost its
session.

Sessions live in `cache.mcp_sessions`, so the handshake spans requests. For a
multi-worker deployment, point that pool at a shared backend (`cache.adapter.redis`);
a per-worker in-memory adapter would break any client whose second request
lands on a different worker.

## Tool definition contract

See README "Tool YAML reference". Constraints enforced at boot:

- `name`: `^[a-z][a-z0-9_]{0,63}$`, unique across files
- `handler`: existing class (+ method) — verified by reflection
- `parameters`: closed key set, known types, scalar enums

## Error mapping

| Condition | MCP (JSON-RPC) | HTTP | REST |
|---|---|---|---|
| Unknown tool | `-32602` invalid params | 200 | 404 |
| Schema violation | `-32602` with validation detail | 200 | 422 |
| Tool threw | `isError: true` + message | 200 | 422 |
| Missing/invalid session | `-32600` | 400 | n/a |
| Parse error | `-32700` | 200 | 400 |

The HTTP column is the transport status: the SDK answers a well-formed HTTP
request with `200` and puts the protocol error in the JSON-RPC body. Only
transport-level failures (missing session, bad method) change the status.

## Dependencies

`mcp/sdk` is pinned to an exact version (`0.8.1`, no `^`/`~`) because it is
pre-1.0 and SemVer permits breaking changes in any digit at this stage. The pin
makes each upgrade a reviewed commit rather than a side effect of
`composer update`. See `MCP_SDK_VERSION_CHECK.md` for the monthly check and the
list of SDK APIs this project depends on.

Relax the pin to `^1.0` once 1.0.0 ships, which is when the SDK adopts
Symfony's backward-compatibility promise.
