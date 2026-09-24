# ROADMAP — context-shuttle

Ordered by value; nothing here is scheduled until it is needed.

## Near term

- **Alerts tool family** (`send_alert` now; `ask_user` /
  `ask_user_confirm` + `get_user_answer` next): provider-backed
  notifications (ntfy, Discord) with a 5-level priority model and
  bounded visible-link formatting; providers enabled by config
  presence (either or both), self-hosted ntfy supported; interactive
  requests (priority 4) answered via a single-use web form (`/ask/{id}`),
  non-blocking by default with opt-in blocking on `ask_user`, plus a
  harness-facing status endpoint (`GET /inputs/{id}`) so a harness can
  poll for answers without LLM intervention. Design:
  `docs/design/ALERTS.md`.

- **Persistent MCP sessions** (opt-in): cache-backed `SessionHandler`
  (`withSession('cache', ...)`) behind a flag, for clients that want
  server-initiated notifications. Default stays stateless.
- **Per-process Protocol caching**: build the Protocol once per worker
  instead of per request, with per-request session isolation.
- **A second example tool** that showcases `enum`, `array` items and
  defaults more thoroughly than weather (e.g. a text transform tool with
  no network dependency — also useful in tests without network).
- **Auth**: token-based guard on `/mcp` and `/tools/*` (env `TOOL_TOKEN`).

## Mid term

- **Resources and prompts**: the YAML registry generalises — a `type:`
  discriminator (`tool` | `resource` | `prompt`) per file. The library's
  `withResource`/`withPrompt` already accept manual definitions.
- **Argument completion**: `CompletionProvider` wiring for enum-typed
  parameters.
- **Metrics**: invocation counters (per-tool count, error rate, p95
  duration) exposed at `/metrics` in Prometheus format.

## Long term / contingent

- **Provider-native interaction** (Discord buttons/components via a bot
  instead of a webhook, ntfy `http` actions) behind the same tool contract
  as the web-form flow, if the form proves insufficient. Phase 3 of
  `docs/design/ALERTS.md`.
- **Upstream the Symfony 8 constraint** of the php-mcp fork so the pin
  can move to a tagged release.
- **Rector** adoption — only after coverage reaches 100% (§2.3 ordering).
- **Multi-file tools** (one YAML defining several tools) if a use case
  demands it; current one-file-one-tool keeps diffs reviewable.