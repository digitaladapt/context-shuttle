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