# SPEC — context-shuttle

**Status:** v1 shipped · PHP 8.5 · Symfony 8.1 · `php-mcp/server` (pinned fork)

## Goal

A tool registry where **tools are YAML files**, served over two protocols
simultaneously:

- **MCP** (Model Context Protocol) at `POST /mcp` — streamable HTTP transport,
  JSON response mode, stateless per request.
- **REST** at `POST /tools/{name}` with a generated **OpenAPI 3.1** spec at
  `/openapi.json`.

One execution pipeline, one validation layer, one structured invocation log.

## Non-goals (v1)

- No templating, no Twig, no frontend (api-gateway archetype, §3.2.1).
- No authn/authz on tool endpoints (single-tenant deployment behind a
  reverse proxy; see ROADMAP).
- No persistent MCP sessions (stateless per request by design).
- No resources/prompts MCP primitives — tools only (v1).

## Architecture

```
config/tools/*.yaml
        │ (boot-time load + strict validation)
        ▼
   ToolRegistry ──────────────┐
        │                     │
        ▼                     ▼
  McpServerFactory      OpenApiController
  (php-mcp Protocol,          (OpenAPI 3.1)
   Dispatcher, Registry)
        │                     │
        ▼                     │
   ┌────────────┐             │
   │ POST /mcp  │             │
   └────────────┘             │
   ┌────────────────┐         │
   │ POST /tools/x  │─────────┘ (same Dispatcher pipeline)
   └────────────────┘
        │
        ▼
  LoggingDispatcher
  (JSON line per invocation: request_id, tool, args, duration, result)
```

### Key decisions

| Decision | Rationale |
|---|---|
| php-mcp/server over ReactPHP transports | The library's `Protocol`/`Dispatcher` are transport-agnostic; we run them inside the Symfony request cycle instead of a standalone ReactPHP socket server. `Server.php` documents this as the supported integration path. |
| Stateless MCP sessions | Each POST mints a session, marks it initialized, processes, discards. Spec-compliant for stateless servers; zero session storage to manage. |
| REST routes through the MCP pipeline | `POST /tools/{name}` constructs a `CallToolRequest` and feeds the same `Protocol::processMessage()` — validation, argument casting, error mapping and logging are identical across protocols by construction. |
| YAML validated at compile time | A compiler pass (`ToolRegistryPass`) loads `config/tools/*.yaml` during container compilation; bad YAML fails boot, never a request. |
| Manual JSON Schemas | `ServerBuilder::withTool()` accepts explicit input schemas — the YAML `parameters` block becomes the tool's JSON Schema verbatim. |
| Tool handlers are public services | The library resolves handlers through PSR-11 `->get()`; private/inlined services break that. |
| OpenAPI generated from the same YAML | No second schema to drift; the OpenAPI requestBody is the tool's inputSchema. |

## Tool definition contract

See README "Tool YAML reference". Constraints enforced at boot:

- `name`: `^[a-z][a-z0-9_]{0,63}$`, unique across files
- `handler`: existing class (+ method) — verified by reflection
- `parameters`: closed key set, known types, scalar enums

## Error mapping

| Condition | MCP (JSON-RPC) | REST |
|---|---|---|
| Unknown tool | `-32602` invalid params | 404 |
| Schema violation | `-32602` with validation detail | 422 |
| Tool threw | `isError: true` + message | 422 |
| Parse error | `-32700` | 400 |

## Dependencies

`php-mcp/server` is pinned to commit `bce7f16` on the fork's
`feat/symfony-8-support` branch (aliased `3.3.99`) because the released 3.3.0
does not allow `symfony/finder` 8.x. When upstream tags a release allowing
Symfony 8, switch the constraint to that tag.