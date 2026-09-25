# ROADMAP — context-shuttle

Ordered by value; nothing here is scheduled until it is needed.

## Selecting an integration: existing MCP server first

Where a service already ships a usable MCP server, **use it** — either by
registering it directly in the harness (task-loom's catalog accepts any
streamable-HTTP MCP server) or, when the tool needs to live here, by
mirroring it through the YAML registry. Building a native tool is for when
there is no server, or when this project's own guarantees are the point:
one namespace, one invocation log, folder/allowlist gating, and the shared
REST + OpenAPI surface. **Blinko** and **SparkyFitness** are the worked
examples: their MCP endpoints are part of the application, so the cost is
configuration rather than code.

Decided so far, with the reasoning recorded so it does not get relitigated:

| Integration | Route | Notes |
|---|---|---|
| **Blinko** (notes, memory) | existing MCP server | Ships `searchBlinko` / `upsertBlinko` / `createComment` / … over streamable HTTP at `/mcp` with its own API token. Includes a web search tool, so a separate `web_search` tool is deferred. |
| **SparkyFitness** (food, fitness, water, health) | existing MCP server | "MCP server + bring your own LLM" is a first-class feature of the application. |
| **ntfy read-back** | native tool | See below — reading is wanted, touching production is not. |
| **CardDAV contacts** | native tool | Radicale is already in use for CalDAV. See below. |

The standing boundary: **read and report, do not operate.** Tools here may
gather state and raise alerts; they do not restart, stop, or reconfigure
running services. Two concrete exclusions, both about self-inflicted
outages rather than distrust of the model: no **container restarts or
docker control** anywhere, and no **network-infrastructure access** — the
Wi-Fi router (EERO) and thermostat (Honeywell) are exactly the class of
device where a wrong call takes out the connection everything else depends
on, including the ability to notice and undo it.

## Near term

- **Alerts tool family** (`send_alert` shipped; Phase 1 plumbing — store,
  `/ask` form, `/inputs/{id}` — shipped; `ask_user` / `ask_user_confirm` +
  `get_user_answer` next): provider-backed
  notifications (ntfy, Discord) with a 5-level priority model and
  bounded visible-link formatting; providers enabled by config
  presence (either or both), self-hosted ntfy supported; interactive
  requests (priority 4) answered via a single-use web form (`/ask/{id}`),
  non-blocking by default with opt-in blocking on `ask_user`, plus a
  harness-facing status endpoint (`GET /inputs/{id}`) so a harness can
  poll for answers without LLM intervention. Design:
  `docs/design/ALERTS.md`.
- **ntfy read-back — `list_alerts`** (the one-way alerts tool's missing
  half): poll one or more configured ntfy topics and return recent
  messages, so "is anything wrong?" has an answer and a machine-generated
  alert (the thermal / storage / docker cron scripts all publish to ntfy)
  can be read, triaged and summarized instead of paged out to a human
  unread. Read-only against the same `NTFY_URL` / `NTFY_TOKEN` /
  `NTFY_TOPIC` configuration the `send_alert` providers already use — the
  topic list is the allowlist. **Explicitly not in scope: anything that
  changes a running service.** No restarts, no docker control, no
  infrastructure mutations; see the boundary note above.
- **Calendar read access — CalDAV events** (`calendar_list_events`,
  `calendar_get_event`): one provider abstraction, `symfony/http-client` +
  `sabre/vobject` rather than `sabre/dav`'s client, client-side recurrence
  expansion (server-side `expand` was verified to shift recurring events by
  an hour across a DST boundary), composite `UID::Occurrence` ids with the
  occurrence in **UTC** so an id survives a `TZ` change, an opaque page
  cursor, `readonly` present from the start because writes are coming, and a
  single `TZ` env var that **all** output is normalized into, so a caller
  never reasons about offsets or DST. Design: `docs/design/CALENDARS.md`.
- **Email tool family** (read-only first): IMAP via
  `directorytree/imapengine` (pure PHP, no `ext-imap` — which is not
  thread-safe, and this runs on FrankenPHP), with **every operation gated
  by a folder allowlist** (read, tag, mark, move-in, move-out are separate
  permissions) and **no delete at all** — IMAP's only delete is a flag plus
  a mailbox-wide `EXPUNGE` that would clear flags other clients set, so the
  destructive verbs are moves into configured folders
  (`IMAP_TRASH_FOLDER`, `IMAP_ARCHIVE_FOLDER`). Content fetches use
  `BODY.PEEK` so "show me this email" never marks it read; tracking the
  agent's own read state uses custom IMAP keywords instead of `\Seen`,
  because `\Seen` is the human's flag, not the agent's. Written against a
  live Dovecot 2.4.1 instance, which caught four `imapengine` defects worth
  working around — including **non-ASCII search silently returning zero
  results** (search values are mUTF-7 converts; we send `RawQueryValue`
  with our own quoting) and the friendly header accessor truncating
  `Authentication-Results` down to the part before the SPF/DKIM/DMARC
  verdicts. Design: `docs/design/EMAIL.md`.
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

- **Weather extension — air quality and UV**: `/air-quality` and the
  `uv_index` daily variable come from the same Open-Meteo provider the
  `get_weather` tool already calls, so this is extra fields on an existing
  integration rather than a new one. Air quality in particular is the
  current-conditions datum a health tool actually wants.
- **CardDAV contacts** (`find_contact`, then a gated write): Radicale is
  already the CalDAV server, so CardDAV is the same host, same Basic
  auth, same `sabre` parsing family — a small marginal addition to
  machinery that already exists. Read first ("is this sender known?",
  "who is X?" — and a cheap phishing signal when a claimed sender is not
  in the address book at all). Writes follow the same shape as the email
  folder gate: **one configured address book is the only one the agent
  may edit**, because multiple contact lists exist and the agent has no
  business in the rest.
- **Penny-track depth**: the `/api/receipts` list is only one of the
  instance's endpoints — the dashboard exposes summary, spending by
  category, monthly breakdown, spending over time, top businesses and
  insights, and none of them are surfaced here yet. A
  `get_spending_summary` tool would let a budget question be answered with
  an aggregate instead of a caller-side sum over a transaction page.
  (Recurring-charge detection belongs in penny-track itself; if it lands
  there as an endpoint, this tool reads it.)
- **Calendar tasks** (VTODO) through the same provider contract and
  mapper: `calendar_list_tasks`, `calendar_get_task`. Then the **ICS
  provider** — a plain `http(s)` `GET` (no `file://`), read-only, same
  contract, dedupe by `(uid, start)` when both are configured, and shaped so
  it is indistinguishable from a read-only CalDAV calendar. Phases 2–3 of
  `docs/design/CALENDARS.md`; confirms the abstraction before it is under
  write pressure.
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
- **CalDAV writes** (create/update/delete events, then tasks) on a single
  configured editable calendar, with `etag`/`If-Match` optimistic
  concurrency from day one. "Update the meeting I just showed you" is the
  real use case, and last-write-wins corrupts a shared calendar. Phase 4
  of `docs/design/CALENDARS.md`.
- **Sending email (SMTP)** as a separate tool family with its own design,
  credentials and outbound risk profile — the payload half of an injection
  attack, so it does not belong in the read-first email tools. Phase 4 of
  `docs/design/EMAIL.md`.
- **IMAP IDLE as an event source** for the email tool family — the same
  worker-shaped idea as `context-loom`'s `ImapIdleListener`. Needs a
  long-lived connection, which the stateless request model does not have.
  Phase 4 of `docs/design/EMAIL.md`.
- **Upstream the Symfony 8 constraint** of the php-mcp fork so the pin
  can move to a tagged release.
- **Rector** adoption — only after coverage reaches 100% (§2.3 ordering).
- **Multi-file tools** (one YAML defining several tools) if a use case
  demands it; current one-file-one-tool keeps diffs reviewable.
- **Server and network infrastructure** (docker control, container
  restarts, Wi-Fi router, thermostat): out of scope by decision, not by
  difficulty. A tool that can restart the router can end the connection
  that would be used to fix it, and the same call applied to a compose
  project takes down the services these tools report on. Observability
  may be added where it is genuinely read-only; control is not on the
  table.