# Changelog

All notable changes to context-shuttle are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning: [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **MCP server library: the `php-mcp/server` fork → the official `mcp/sdk`**
  (pinned to `0.8.1`). Upstream `php-mcp/server` has not been pushed to since
  2025-08-09, so the project was maintaining a private fork of an abandoned
  library to keep it installing on Symfony 8. The official SDK is that
  project's successor — same original author, now maintained with the PHP
  Foundation and Symfony — and is PSR-7 in / PSR-7 out instead of owning a
  ReactPHP socket, which suits a Symfony request cycle. Full reasoning and the
  verified API mapping: `docs/design/MCP_SDK_MIGRATION.md`.

  Net effect on this codebase: `CaptureTransport`, `McpStack` and
  `LoggingDispatcher` are **deleted** (−161 lines); `McpController` no longer
  contains any JSON-RPC knowledge (no id handling, no error-code table, no
  media-type negotiation).

- **MCP sessions are now required.** A `tools/call` or `tools/list` sent
  without a prior `initialize` is refused with `400` / `-32600`. v1.0.0 minted
  a throwaway session per request, which meant it served clients that never
  performed the handshake; that was an artifact of the old library. Sessions
  are stored in the new `mcp_sessions` pool so the handshake spans PHP
  requests. Point that pool at a shared backend for multi-worker deployments.

- **Tool failures keep their messages.** The SDK replaces any exception other
  than `ToolCallException` with a generic `-32603 "Error while executing
  tool"`. Since every tool here reports actionable configuration failures by
  throwing a plain `RuntimeException`, a
  `ToolFailureTranslatingReferenceHandler` now performs that translation once,
  centrally, instead of adding a new exception type to nine tools.

- **Parse errors answer `200` with `-32700` in the body**, not `400`. The HTTP
  request was fine; the JSON-RPC message was not. Transport-level failures
  (missing session, bad method) still change the HTTP status.

- **Unknown tool is `-32602` (invalid params)**, not `-32601`. `tools/call`
  exists; the *name in its params* does not. The REST surface still answers
  `404`.

### Added

- `docs/design/MCP_SDK_VERSION_CHECK.md` — the monthly `mcp/sdk` update check,
  and the list of SDK APIs this project depends on.
- `mcp_sessions` cache pool for MCP session storage.
- `tests/Integration/McpSessionTrait.php` — shared handshake helper for tests.
- Test coverage for session enforcement, and a regression test asserting a
  tool's own error message reaches the client.

### Removed

- `src/Mcp/CaptureTransport.php`, `src/Mcp/McpStack.php`,
  `src/Mcp/LoggingDispatcher.php` — all three existed to work around the old
  library's transport model.
- The `php-mcp-server` VCS `repositories` entry from `composer.json`; the
  dependency is now plain Packagist.

### Added

- Email read path, first slice: `list_email_folders`, `list_emails` and
  `read_email` end to end — `directorytree/imapengine` behind an
  `ImapClient`, the per-operation **folder gate**, page-sized listings with
  attachment metadata, and a body fetch bounded on the wire. Verified
  against a live Dovecot 2.4.1 instance (6 messages across 2 folders, plus
  the HTML-only, non-ASCII, attachment and 3.4 MB fixtures).

  Three things the implementation learned that the design had not
  anticipated, all found by driving the real server rather than a mock:

  - **`attachments(fetch: true)` downloads every attachment.** The helper
    wraps each part's content in a `LazyBodyPartStream` whose `getSize()`
    is `strlen($this->getOrFetchContent())` — so reading a *size* fetches
    the whole part. A listing that used it turned one envelope fetch into
    an extra `UID FETCH (BODY.PEEK[2])` per attachment. Attachment metadata
    now comes straight off `BODYSTRUCTURE`, which already carries name,
    type and size.
  - **`%` is not a wildcard inside a quoted string.** The obvious way to
    express the tool's substring search is `SUBJECT "%term%"`, and that
    searches for a subject containing percent signs — returning nothing,
    with no error. Dovecot substring-matches `SUBJECT`/`FROM` natively, so
    the value goes out bare. (This corrected an assumption in the design
    itself, which had proposed the wildcard form.)
  - **A `Mailbox` cannot be reused across `with()` calls.** The fake-server
    work surfaced this: `connect()` re-opens the stream and re-reads the
    greeting, so a connection per invocation is not just the thread-safety
    rule but a practical requirement of how the library is built.

  Search values are also sent as `RawQueryValue` with hand-rolled quoting,
  which is the fix for the design's finding 3 (plain values are converted
  to modified UTF-7, which the server matches literally): a search for
  `Reunión` now returns the message, where before it returned zero results
  and no error. 84 new tests, including a scripted-server fixture
  (`tests/Support/RespondingStream.php`) that drives the real client so the
  wire-level behaviours are pinned rather than mocked away.

- `docs/design/ROADMAP.md` now records the **decided integration roster**
  and the selection rule behind it: where a service already ships a usable
  MCP server, use it (directly from the harness, or mirrored into the YAML
  registry) — **Blinko** and **SparkyFitness** are configuration rather
  than code, which is why Blinko's bundled web-search tool also removes a
  planned `web_search` from this project's backlog. Native tools are for
  what has no server: **ntfy read-back** (`list_alerts` — the same topics
  the `send_alert` providers publish to, read-only, so "is anything
  wrong?" finally has an answer) and **CardDAV contacts** (Radicale is
  already serving the CalDAV side; one configured address book is the only
  one the agent may edit, because multiple contact lists exist). Also
  queued: **air quality + UV** as extra fields on the existing Open-Meteo
  weather tool, and **penny-track dashboards** (`get_spending_summary` —
  the instance exposes summary / by-category / monthly / over-time /
  top-businesses / insights endpoints that nothing here surfaces yet, and
  "/api/receipts" will feel like the whole instance until they are
  reachable).
- `docs/design/DESIGN_CONSIDERATIONS.md`: a section on **why integration
  work starts by looking for someone else's MCP server**, and on the
  project-wide boundary that follows from it — **read and report, do not
  operate.** No container restarts or docker control, no Wi-Fi router or
  thermostat access: a tool that can restart the router can also end the
  connection needed to fix it, and the same call against a compose project
  takes down the services these tools exist to report on. Recorded as an
  explicit non-goal in the ROADMAP so it is not reopened as a feature
  request later.
- **`send_alert` tool (alerts Phase 0)**: one-way notifications through
  every enabled provider — ntfy and Discord webhooks behind one
  `AlertProvider` interface, each enabled by config presence
  (`NTFY_TOPIC`, `DISCORD_WEBHOOK_URL`; both set means both receive).
  Best-effort fan-out with an isolated attempt per provider and a
  per-channel delivery report in the result; the tool errors only when
  nothing is configured or nothing anywhere delivered. Includes the
  5-level `Priority` model (ntfy priority headers, Discord embed
  colours, `@user` mention only at level 5 with Discord's
  `allowed_mentions` pinned so `@everyone`/`@here` can never fire), the
  shared bounded visible-link formatter (rightmost-50 domain,
  leftmost-50 path, bold domain, query/fragment dropped), and full
  MCP + REST + OpenAPI registration. Self-hosted ntfy (custom
  `NTFY_URL`, optional `NTFY_TOKEN`) is a first-class case.
- `docs/design/ALERTS.md`: design draft for the alerts tool family —
  `send_alert` (one-way, ntfy + Discord webhook providers behind one
  interface, both enabled by config presence — mix and match, incl.
  self-hosted ntfy; 5-level priority model mapped per provider; link
  support from day one with a bounded `**domain**/path` line in the
  visible text) with a path to the interactive `ask_user` /
  `ask_user_confirm` / `get_user_answer` tools (fixed priority 4)
  answered via a single-use web form (Twig), non-blocking by default
  with opt-in blocking on `ask_user`, mint → send → persist ordering
  with a per-provider delivery report, and a harness-facing short-poll
  status endpoint for polling without LLM intervention. Planning only;
  no code yet.
  Phase 0 (`send_alert`) is now implemented (see above); Phases 1–2
  (interactive requests) remain planning only.
- `docs/design/CALENDARS.md`: design draft for calendar read access —
  CalDAV events first (`calendar_list_events`, `calendar_get_event`),
  then tasks, then an ICS provider, then CalDAV writes. Written against a
  live Radicale 3.8.0 instance rather than from documentation, which
  surfaced the things that changed the design: server-side `expand`
  shifts recurring events by an hour across a DST boundary (so expansion
  is client-side); occurrences of one series share a UID (so occurrences
  are addressed by a composite `UID::Occurrence` id, with the occurrence
  in UTC so an id survives a `TZ` change); an unresolvable
  `TZID` silently parses as UTC and `TimeZoneUtil` returns UTC for a
  fixed-offset `TZID` (so offsets are recovered or counted, never
  guessed); a fixed-offset `TZID` like `UTC-04:00` throws in
  `sabre/vobject` (so per-item failures are isolated, reported as one
  human-readable `errors` string with UID+calendar detail in the log);
  converting an all-day `DATE` through a timezone shifts the day, and a
  bare `DATE` also parses as a valid instant (so `DATE` values are never
  converted, and occurrence-id parsing is regex-first with the date case
  first). One `TZ` env var is the only timezone in the system: all output
  is normalized into it, so a caller never reasons about offsets or DST.
  Paging is an opaque `cursor`/`next_cursor` with `limit` defaulting to
  50, `readonly` ships from the start because CalDAV writes are coming
  (per occurrence, flat on the event, since v1 expands every recurring
  event and every row should be self-describing), and ICS is deferred to
  Phase 3 — `http(s)` only, since `symfony/http-client` refuses `file://`,
  and shaped so it is indistinguishable from a read-only CalDAV calendar.
  Planning only; no code yet.
- Calendar read path, first slice: `calendar_list_events` end to end —
  discovery, `calendar-query`, client-side recurrence expansion, the
  timezone rule, composite occurrence ids, opaque cursor paging, and the
  tool/YAML/env wiring. Verified against a live Radicale 3.8.0 instance
  (21 occurrences across two calendars, paging walked to exhaustion with
  no duplicates or gaps). `sabre/vobject` resolved to 5.0.0 where the
  design's findings were established on 4.6.1, so every finding was
  re-verified against 5.0.0 before being relied on. Three things the live
  server taught us that the design had not anticipated: discovery needs the
  standard `current-user-principal` → `calendar-home-set` bootstrap (the
  configured URL is a principal, so a `Depth: 1` listing of it finds no
  calendars at all); a calendar is a *child of* `resourcetype`, not a
  property, so asking the obvious question silently returns none; and
  `sabre/xml`'s `keyValue` deserializer cannot parse a multistatus, since
  it keeps only the last of any repeated element and repeated `<response>`
  elements are how a multistatus carries its payload. Still no
  `calendar_list_tasks`, ICS, or writes.
- `calendar_get_event`, closing CalDAV events: it takes either an id
  from a listing (which fetches exactly that occurrence) or a plain
  series UID (which expands the series). Both behaviours were caught
  wrong by running them: the occurrence lookup was returning the
  neighbours its deliberately-wide server-side window also matched, and
  the plain-UID window was anchored on "now", so a historical series
  expanded to nothing — and the 366-day clamp then truncated a
  two-year window into one that ended in the past, so both anchors
  returned empty. It now anchors on the series' own `DTSTART`.
  `CALDAV_CALENDARS` is also live rather than inert: it is the exposure
  boundary, so it fails closed and logs any entry that matched no
  discovered calendar, alongside the calendars the server did offer.
  Adds `CalDavLiveTest`, which runs the read path against a real server
  when `CALDAV_LIVE_URL` is set and skips otherwise, and documents the
  calendar tools in the README — including that Google Calendar is not
  supported, since its CalDAV endpoint no longer accepts Basic auth.
- `docs/design/EMAIL.md`: design draft for the email tool family — read-
  first IMAP through `directorytree/imapengine` (pure PHP, so no
  `ext-imap`, which is not thread-safe) with **every operation gated by a
  folder allowlist**: reading, tagging, marking, moving in and moving out
  are separate permissions, and every write-ish one is off until an
  operator names folders. `list_emails`, `read_email`,
  `mark_email_read`/`unread`, `tag_email`, `move_email` and
  `list_email_folders`. **There is no delete**, and that is a fact about
  IMAP rather than a policy: `EXPUNGE` clears every `\Deleted` message in
  the mailbox, including flags other clients set, so `destination:`
  `"trash"`/`"archive"`/`"folder"` move a message into a configured
  folder where a human can recover it. Content fetches are `BODY.PEEK`,
  so "show me this email" cannot mark it read; the agent tracks its own
  read state with custom IMAP keywords (`tagged`/`untagged` filters)
  rather than `\Seen`, which is the human's flag. Written against a live
  Dovecot 2.4.1 instance rather than from documentation, which surfaced
  four `imapengine` defects worth working around — the worst being that
  non-ASCII search **silently returns zero results** (plain values are
  run through `Str::toImapUtf7()`, which is a folder-name encoding, not a
  search-term one), and that the friendly header accessor truncates
  `Authentication-Results` to the part *before* the SPF/DKIM/DMARC
  verdicts. Also recorded: `move()` returns `null` on success here,
  `paginate()` reads the global `$_GET['page']` and hard-fails on a stale
  value, `LengthAwarePaginator` is not iterable, and several folder- and
  date-matching semantics that would otherwise be guessed at. One finding
  is a security property rather than a quirk: `flag()` accepts a
  `\`-prefixed value and sets a *system* flag, so `tag_email` rejects those
  or it would be a second, ungated path to `\Deleted`. Planning only; no
  code yet.

### Changed

- CI now calls the shared reusable workflows instead of carrying its own
  copies. `.gitea/workflows/{tests,develop,docker,publish}.yaml` went from
  265 lines of hand-maintained steps to 143 lines of trigger plus `uses:` —
  the pipeline itself lives in the shared standards repo, so a change there
  reaches every project that calls it with no PR here. The inlined
  `tests.yaml` existed because the cross-repo `uses:` form was believed not
  to work on this instance; it does work, and the original failure was the
  source repo being unreachable to the runner rather than the syntax.
- Leaf dev-tool configs re-synced from the shared repo: `phpstan.neon.dist`
  (level 6 + the high-signal checks, plus an empty `phpstan-baseline.neon`),
  `.php-cs-fixer.dist.php`, `.editorconfig`, `LICENSE`, and
  `.ci/conformance.sh` (+ the newly vendored `validate-bake.py`). PHPStan
  stays clean at the shared level, so the baseline ships empty rather than
  recording violations.
- Adopting the shared php-cs-fixer config reformatted 29 of 31 files. This is
  a formatting change, not a behaviour change: the rules are `@Symfony` +
  `@Symfony:risky`, and the only non-whitespace edits are native-function
  calls taking a leading `\` (14 files) and yoda/`self::` idiom. All 61 tests
  pass after the reformat.
- Composer package renamed `devgnome/context-shuttle` →
  `digitaladapt/context-shuttle`, matching the GitHub and Docker Hub repos.
- Public-facing links now use `code.digitaladapt.com/public/context-shuttle`
  (the `code.devgnome.com` name is LAN-only).
- `README.md` dependency note points at
  `code.digitaladapt.com/public/php-mcp-server`; the `php-mcp/server` VCS
  repository moved to the same public host.
- Conform to Guiding Light §5/§6 env & image rules: `.env` and
  `config/reference.php` are no longer committed (`.env` is local-only;
  `reference.php` is a generated IDE-support dump), `.env.dev` removed,
  `.gitignore`/`.dockerignore` aligned to the canonical baselines.
- Dockerfile: non-root runtime user (`app`, uid/gid 1000), wires
  `docker/Caddyfile` and `docker/entrypoint.sh` (previously unreferenced),
  adds HEALTHCHECK against `GET /health`, installs ca-certificates/curl.
- `LOG_LEVEL` env var is now actually read: both monolog handlers use
  `%env(LOG_LEVEL)%` (default `info`), and `.env.example` documents it.
- The `get_health_logs` tool now states its measurement units instead of
  leaving them implicit: the description lists mmHg (systolic/diastolic),
  bpm (heart rate), and lbs (weight), and every response carries the same
  mapping as `meta.units`. vital-pulse's dashboard labels readings with
  these units but its API returns bare numbers, so callers previously had
  to infer them; any units vital-pulse reports itself take precedence
  per field, with the defaults completing the map.

### Added

- `Cross-Origin-Resource-Policy` response header on every response
  (preflight, CORS, same-origin, errors), configurable via
  `CORS_RESOURCE_POLICY` (`same-site` default, `same-origin`, or
  `cross-origin`). CORP is enforced on "no-cors" subresource loads
  (images, scripts, fonts), which never send an Origin header, so it is
  applied independently of the CORS handshake. An unrecognised value
  fails loudly rather than silently falling back.
- `.env.example` now documents `DEFAULT_URI` (required by routing when
  `.env` is absent).
- `.ci/conformance.sh` + `css-control-size.py` vendored from the shared
  standards repo; the CI conformance step now runs instead of silently
  skipping.
- `#[Override]` on every method that overrides a parent or interface method
  (11 sites across `Kernel`, `CorsSubscriber`, `CaptureTransport`,
  `LoggingDispatcher`, `ToolRegistryPass`, and two test classes), so a
  renamed parent method fails static analysis instead of silently no longer
  overriding anything.

### Fixed

- CORS: `MCP-Protocol-Version` was missing from
  `Access-Control-Allow-Headers`, so browsers blocked every post-initialize
  request from spec-compliant MCP clients (llama.cpp's web UI, MCP
  Inspector) with "CORS Missing Allow Header", even though the preflight
  itself returned 204. The streamable HTTP spec requires the header on all
  requests after `initialize`; header-less clients (curl, Postman) were
  unaffected, which is why the failure only showed up in the browser.
- `docker-bake.hcl`: `DOCKERHUB_TARGET` default was misspelled
  `digitaladapt/comtext-shuttle`; corrected to
  `digitaladapt/context-shuttle`.
- `ToolLoaderTest`: `$tmpDir` was read by `tearDown()` before `setUp()` had
  assigned it; given a default so a failing `setUp()` cannot surface as an
  unrelated uninitialised-property error.
- `CaptureTransport`: dropped a `@phpstan-ignore` that no longer matched
  anything, and whose comment claimed a `return.type` error the code does not
  produce.

## [1.0.0] - 2026-09-20

### Added

- YAML tool registry (`config/tools/*.yaml`) with strict boot-time
  validation: names, handlers (reflection-verified), parameter types,
  enums, defaults, items.
- MCP endpoint `POST /mcp` (streamable HTTP, JSON response mode,
  stateless per request) bridged into the Symfony request cycle via
  `php-mcp/server`'s Protocol/Dispatcher.
- REST surface: `GET /tools`, `POST /tools/{name}` sharing the exact MCP
  execution pipeline.
- Generated OpenAPI 3.1 document at `GET /openapi.json`.
- Structured JSON invocation logging (channel `mcp_invocation`):
  request id, tool, arguments, duration, result preview, error info.
- Health/readiness split: `GET /health` (liveness) and `GET /ready`
  (registry loaded, tool count).
- Example tool `get_weather` (Open-Meteo; free-text geocoding or
  `lat,lon`; metric/imperial; 1–7 day forecast).
- Toolchain: PHPStan (level 8), php-cs-fixer, PHPUnit; composer scripts
  `lint`, `cs-fix`, `stan`, `test`.
- Docker: FrankenPHP 1 / PHP 8.5 / trixie base, php.ini, Caddyfile,
  entrypoint (prod cache warmup).

[Unreleased]: https://code.digitaladapt.com/public/context-shuttle/compare/v1.0.0...HEAD
[1.0.0]: https://code.digitaladapt.com/public/context-shuttle/releases/tag/v1.0.0