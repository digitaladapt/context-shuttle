# CALENDARS — context-shuttle

**Status:** planning (v0.2 draft, revised after review) · sibling docs:
`SPEC.md`, `DESIGN_CONSIDERATIONS.md`, `ROADMAP.md` · supersedes the
v0.1 draft

## Goal

Calendar read access as context-shuttle tools. One provider abstraction,
two sources (CalDAV, ICS), two component types (events, tasks), and one
**rule about time**: every timestamp that leaves this server is already in
the deployment's timezone, so a caller never has to know what an offset is.

Scope of **this document**: the read path — `calendar_list_events`,
`calendar_get_event`, `calendar_list_tasks`, `calendar_get_task`. CalDAV
writes come later, behind the same contracts.

Non-goals for v1:

- **No writes.** CalDAV create/update/delete is a later phase. ICS is
  read-only by nature and stays that way.
- **No alarms/VALARM** in the tool surface. Parsed and ignored; exposing
  them is a later, purely additive change.
- **No background polling / subscriptions.** Every call fetches.
- **No OAuth.** Basic auth only (review decision). Google's CalDAV
  endpoint dropped basic auth, so Google is out of scope until an OAuth
  path exists — say so in the README rather than half-supporting it.
- **No multi-account.** One CalDAV server and one ICS source per
  deployment, like the other tools here.

## What drove this design

The findings below were **verified against a live Radicale 3.8.0 instance**
(seeded with recurring, all-day, VTODO, TZID, override and DST-crossing
fixtures) driven with `sabre/vobject` 4.6.1 — not inferred from
documentation. The ones that actually changed the design are marked ⚠️.

| # | Finding | Consequence |
|---|---|---|
| 1 | A recurring series returns **one UID per occurrence**, and expanded output still repeats that UID (verified: 4 occurrences, 1 distinct UID). | Occurrences need a distinct address — see Composite ids. |
| 2 | ⚠️ **Server-side `expand` produced wrong instants across a DST boundary.** For a `09:00 Europe/London` weekly series, Radicale applied the interval to the UTC instant of the first occurrence and never re-resolved the offset, so after London left DST the occurrences stayed at `08:00Z` — 08:00 GMT, an hour earlier than the 09:00 the event specifies. | **Never use server-side expansion.** Expand client-side. |
| 3 | Client-side `sabre/vobject` `expand()` is DST-correct: `08:00Z`→`09:00Z` shift, 09:00 London wall clock preserved. | The read path expands locally. |
| 4 | Returned `calendar-data` carries its `VTIMEZONE`, and `sabre` resolves it. | Trust the payload, not only the local tz database. |
| 5 | ⚠️ `TZID=UTC-04:00` (fixed-offset pseudo-TZID) makes `sabre` **throw** `InvalidDataException` at every `Reader` option level. Radicale itself rejects the same payload with `400`. | Fixed-offset TZIDs are parsed out of the raw value by us. One bad event must not fail a listing. |
| 6 | ⚠️ An unresolvable `TZID` (`Custom/Zone`) silently yields **UTC** from `sabre`. | Naive pass-through silently shifts events. Must be detected. |
| 7 | `TimeZoneUtil::getTimeZone('UTC-04:00')` returns **UTC** with no error. | Silently wrong is worse than loud failure; do not rely on `TimeZoneUtil`. |
| 8 | A DST-ambiguous local time (01:30 America/New_York on fall-back day) resolves to the **first** (DST) pass. | Ambiguity is inherent in the data; we pick the same pass every time and do not pretend otherwise. |
| 9 | All-day events are bare `DATE` values. | Must be flagged and emitted date-only — see All-day. |
| 10 | Calendars are discoverable only via `PROPFIND` + `resourcetype` containing `<C:calendar/>`; `displayname` returned the *path* on this server. | Discover by property, not name. `href` is the stable identifier. |
| 11 | `calendar-multiget` (batch fetch by href) works: 2 hrefs → 2 responses. | `calendar_get_event` uses it rather than listing and filtering. |
| 12 | VTODO filtering behaves identically with and without `<time-range>`. | Cannot assume server-side VTODO date filtering; filter client-side. |
| 13 | ⚠️ `symfony/http-client` **refuses `file://`**: `Unsupported scheme in "file:///…": "http" or "https" expected`. | ICS sources need a fetch abstraction with a plain-filesystem path, not just a different URL. |
| 14 | ⚠️ Converting an all-day `DATE` through a timezone **shifts the day**: `2026-11-01` renders as `2026-10-31` in `America/New_York`. | `DATE` values are never timezone-converted, anywhere. |

## Authentication

`CALDAV_URL`, `CALDAV_USERNAME`, `CALDAV_PASSWORD` — the same shape as the
existing `PENNYTRACK_*` / `VITALPULSE_*` pattern, so the operator's mental
model carries over: unset means the tool is not usable, and invoking it
yields a clear "not configured" error rather than a container `TypeError`.

Two deliberate differences from the existing tools:

- The password **must** be allowed to live in the secrets vault
  (`bin/console secrets:set CALDAV_PASSWORD`) as well as `.env.local`. It is
  the first genuinely sensitive credential this project holds.
- `CALDAV_URL` is **not** probed at boot. A down calendar server must not
  prevent context-shuttle from booting, and every other tool here sets that
  precedent — the failure belongs at invocation time, with a message naming
  the env var.

**HTTP Basic only** (review decision). That is a real if small cost of
building on `symfony/http-client` rather than `sabre/dav`'s client, which
would negotiate `CURLAUTH_BASIC | CURLAUTH_DIGEST`. Basic is what Radicale,
Nextcloud, Baïkal, Fastmail and Apple's app-password flow all accept, so
digest-only servers are deferred rather than designed around; if one appears
it is contained inside `CalDavClient`.

## The timezone rule ⚠️

**One env var, one direction: everything in — `TZ`; everything out — `TZ`.**

`TZ` is the deployment's timezone, and it is the only timezone in the
system's vocabulary. (It is also already used elsewhere in this ecosystem,
so the name needs no defending. PHP does not read `TZ` by itself —
verified: `TZ=Australia/Sydney php -r 'echo date_default_timezone_get();'`
prints `UTC` — so it must be read explicitly. `TZ` is the only env var; there
is no `TIMEZONE` alias.)

The reason for the rule is a capability boundary: **a caller should not have
to know what an offset is, or when DST starts.** CalDAV and ICS can express
the same instant a dozen ways — `Z`, a named `TZID` with or without a
`VTIMEZONE`, a fixed-offset pseudo-`TZID`, a floating local time — and it is
the *server's* job to absorb that variety, not the model's. So:

- **Timestamps in and out are `TZ`.** A response contains ISO 8601 instants
  with offsets in `TZ` (`2026-10-29T05:00:00-04:00`) and nothing else. There
  is no second "local" view and no `tz` field per event, because there is
  nothing for the caller to reconcile. `from`/`to` are also dates in `TZ`,
  so the input side and the output side agree by construction.
- **Normalization is the implementation's problem, and it is real work.**
  Internally the stored definition is authoritative: read the raw value
  *and* its `TZID` parameter *and* the payload's `VTIMEZONE`, compute the
  instant, then render it in `TZ`. That is exactly the "craziness" being
  absorbed.
- **A normalized value is correct even when it looks inconsistent.** For a
  weekly `09:00 Europe/London` series rendered in `TZ=America/New_York`, the
  local times are `04:00 04:00 04:00 04:00 05:00 04:00` — the two zones
  change DST on different dates, so a viewer genuinely sees the meeting move
  for a week and move back. Rendered in `TZ=UTC` the same series is
  `08:00 … 09:00`. Both are right. The value is not required to be constant
  across a series, and a caller must not "correct" it.
- **A wrong `TZ` mis-renders; it cannot change which instant it is.** The
  instant is derived from the stored data, and `TZ` is applied afterwards.
- **Invalid `TZ` fails at boot** with the list of valid zones, in the same
  "fail loudly at config time" spirit as `CORS_RESOURCE_POLICY`, rather than
  silently falling back to UTC.

**When normalization cannot be done reliably** (finding 6 — a named `TZID`
that resolves nowhere, with no offset to recover), the event is still
returned best-effort, counted in the result's `errors` string, and logged
with its UID and calendar. Uniform output plus an honest count; never a
fabricated instant presented as fact.

**Fixed-offset pseudo-TZIDs are recovered, not rejected** (finding 5). The
value `TZID=UTC-04:00` is parseable even though `sabre` refuses the property:
we extract the offset ourselves, normalize from it, and treat the event as
normal. It is not an error, because there is nothing ambiguous about it.

**All-day events are dates** (findings 9, 14). `all_day: true` with `start`
as `2026-11-01` — no time, no offset, and **never converted through a
timezone**, because that is precisely what shifts the day. `TZ` does not
apply to them. `DTEND` on an all-day event is exclusive per RFC 5545, so
`end_exclusive: true` is reported rather than left for the caller to get
wrong by one day.

## Composite ids ⚠️

Finding 1: a recurring event logically appears many times and every
occurrence carries the same `UID`. Both listing and fetching need to address
one occurrence.

The format is **`{UID}::Occurrence`** (review decision), where `Occurrence`
is the occurrence's start as an ISO 8601 instant in `TZ`:

```
evt-standup@test::2026-10-09T15:00:00-04:00
```

The parsing rule is what makes it safe: **split on the *last* `::`, and
accept the split only if the tail parses as an ISO 8601 instant.** A UID
that merely contains `::` still resolves correctly, because its tail will not
parse as an instant and the whole string is then treated as a plain UID:

| Input | Resolves to |
|---|---|
| `evt-standup@test::2026-10-09T15:00:00Z` | UID `evt-standup@test`, occurrence `2026-10-09T15:00:00Z` |
| `evt-standup@test` | UID, no occurrence |
| `weird::uid::2026-10-09T15:00:00Z` | UID `weird::uid`, occurrence `2026-10-09T15:00:00Z` |
| `weird::uid@test` | UID `weird::uid@test` |
| `a@b::not-a-date` | UID `a@b::not-a-date` |

Behaviour:

- Every occurrence is returned with its **series `UID` unchanged**, plus
  `recurrence_id` (the occurrence's original `RECURRENCE-ID`) and `id` (the
  composite).
- Non-recurring events, and series returned unexpanded, have
  `id == uid` and `recurrence_id: null`.
- `calendar_get_event` accepts **either** form. Given a plain UID it returns
  the series; given a composite id it narrows to a one-occurrence window and
  returns exactly that occurrence.
- A **moved override** reports its actual start in `id` and `start`, while
  `recurrence_id` keeps the original slot — so "moved to 15:00" is
  distinguishable from "always 15:00".

Expansion is client-side and bounded (finding 2):

- Fetch with `<time-range>`, **without** `<expand>`.
- Expand locally with `sabre/vobject` against the payload's own `VTIMEZONE`.
- `limit` defaults to 100 (max 500); hitting it sets `truncated: true` and
  returns `next_from`, so paging is predictable and explicit. An unbounded
  daily series is otherwise thousands of rows in a context window.
- The expansion window is clamped (366 days max) so a pathological `RRULE`
  cannot be used to burn the process.

## Tool surface

Four tools, all read-only. Names follow this repo's `get_*` convention
(`get_weather`, `get_transactions`, `get_health_logs`).

**Calendar membership is metadata, not a tool** (review decision): which
calendar an event belongs to is one more field on the event, not a separate
round-trip. Discovery still happens internally on every call — finding 10
says it must be property-driven — it is simply not its own endpoint.

### `calendar_list_events`

| Parameter | Type | Req | Notes |
|---|---|---|---|
| `from` | string | ✅ | `YYYY-MM-DD`, inclusive, in `TZ` |
| `to` | string | ✅ | `YYYY-MM-DD`, inclusive, in `TZ` |
| `calendar` | string | – | restrict to one calendar by `href` or display name; default all |
| `search` | string | – | case-insensitive substring over summary/description/location |
| `limit` | integer | – | 1–500, default 100 |

Returns `{events: [...], count, truncated, next_from, timezone, errors?}`.
A bare array would leave `truncated` and `errors` nowhere to live.

Each event: `id`, `uid`, `recurrence_id`, `summary`, `description`, `start`,
`end`, `all_day`, `end_exclusive`, `location`, `categories`, `status`,
`calendar` (`{name, href, readonly}`), `editable`.

Ordering is by start instant, then summary, so the order is stable across
calls — a non-deterministic order makes a model's narration of a day wrong.

### `calendar_get_event`

| Parameter | Type | Req | Notes |
|---|---|---|---|
| `id` | string | ✅ | composite id, or a plain series UID |
| `calendar` | string | – | narrow the search |

One event, same shape as a list element. A composite id bounds the query to
a narrow window (finding 11 makes this a narrow fetch, not a listing). Not
found → a normal tool error naming the id.

### `calendar_list_tasks` / `calendar_get_task`

VTODO, same shape with `summary`, `description`, `due` (nullable, in `TZ`,
date-only when the task is date-only), `status`, `percent_complete`,
`priority`, `completed_at`, `categories`, `calendar`.

- **Open tasks by default** (review decision): `include_completed` defaults
  to `false` and no date range is required, because "what's outstanding?" is
  the dominant question. A caller reconstructing history passes
  `include_completed: true` plus `from`/`to` on `due` and accepts the extra
  step.
- Filtering is client-side after the fetch (finding 12).

## Errors are a sentence, detail is a log ⚠️

A listing that hit trouble still succeeds, with a single human-readable
string (review decision):

```json
{ "events": [ … ], "count": 4, "errors": "There were 2 malformed events" }
```

- Omitted entirely when nothing went wrong.
- Internal logging carries the detail — **UID and source calendar per
  affected event**, plus the reason — on the existing `mcp_invocation`
  channel, where an operator can find it and a model is not asked to parse
  it.
- This covers both shapes of trouble: a component that will not parse
  (finding 5) and a timestamp that cannot be normalized reliably
  (finding 6). Both are skipped-or-best-effort with a count, never a failed
  request: "what's on tomorrow?" is much better served by four events plus
  "1 malformed event" than by a 422.
- `401`/`403` are different in kind — a misconfigured deployment, not bad
  data — and do fail the call, with a message that names the env var and
  never echoes the password.

## Provider abstraction

Both sources satisfy one contract, so the tool layer never learns which it is
talking to and both inherit the same timezone and id semantics by
construction. If ICS did its own expansion, the two would drift exactly where
it is most dangerous — recurrence and DST — which is the argument this repo
already makes for routing REST through the MCP pipeline.

```php
interface CalendarProvider
{
    /** Non-display identifier: 'caldav', 'ics'. */
    public function name(): string;

    /** @return list<CalendarInfo> */
    public function listCalendars(): array;

    /**
     * Unexpanded VEVENT/VTODO components overlapping the window.
     *
     * @return iterable<CalendarObject>  component + href + calendar + etag
     */
    public function fetch(string $calendarHref, \DateTimeImmutable $from, \DateTimeImmutable $to, ComponentType $type): iterable;
}
```

Expansion, normalization and DTO shaping sit **above** the provider in a
shared `CalendarReader`/`EventMapper`, so the timezone rule is implemented
once.

`ComponentType` is an enum (`VEVENT`/`VTODO`) rather than a string, making
the distinction a type error rather than a runtime surprise.

### ICS is a URL, and it is made of fresh air ⚠️

An ICS source is **a URL, and may be a `file:///path/to/file.ics`** (review
decision). Two consequences:

- **It must be assumed to change at any moment**, so it is fetched on every
  call and never cached or memoized. There is no "refresh" concept to
  expose; freshness is the default.
- **`file://` needs its own fetch path.** `symfony/http-client` rejects the
  scheme outright (finding 13), so the ICS provider takes a small
  `SourceFetcher` seam with two implementations — `http(s)` via the existing
  client, local via the filesystem — rather than pretending one client
  covers both. A local file also has no `ETag`/`Last-Modified` to be clever
  with, which is another reason not to try.

Because an ICS feed is a single flat calendar with no discovery, it reports
one synthetic `CalendarInfo` (named by `ICS_NAME`, defaulting to something
stable like `ics`) and ignores the `calendar` filter.

## Transport decision: Symfony HttpClient + sabre/vobject

`sabre/dav` ships a full CalDAV client, and `context-loom`'s spec picks
`sabre/dav + sabre/vobject`. **This project should not.**

- The CalDAV read surface is three requests: `PROPFIND` (discover),
  `REPORT calendar-query` (list), `REPORT calendar-multiget` (get). They are
  XML over HTTP.
- `sabre/dav`'s client would add `sabre/http`, `sabre/event`, `sabre/uri`
  plus cURL plumbing and a `Client` whose transport we do not control, and
  would drag in a server-side DAV stack we will never run.
- `symfony/http-client` is **already a dependency**, is what
  `get_transactions` and `get_health_logs` use, and `MockHttpClient` is what
  their tests use — so CalDAV tests look exactly like the existing tests.
- What is actually wanted from sabre is `vobject`: `Reader`, `expand()`,
  `VTIMEZONE` resolution. That package is dependency-light and is the piece
  that demonstrably gets DST right (finding 3).

So: **`composer require sabre/vobject`** (`sabre/xml` comes along) and a
`CalDavClient` over `symfony/http-client` speaking those three requests.
Less code than configuring `sabre/dav`'s client, and one HTTP stack.

Two client behaviours borrowed from the prior art in `mcp-server`, because
they were paid for in production bugs:

- **Stale-connection recovery**: one retry after a transport error or `5xx`
  on an idempotent `PROPFIND`/`REPORT`, then fail. CalDAV servers restart;
  this turns a scary failure into a slow success.
- **Per-item isolation**: one malformed event must not fail a listing
  (finding 5) — hence the `errors` string above.

### XML parsing

`sabre/xml` (`Service`/`Deserializer`) for the DAV envelope, not
`SimpleXML` by hand and not regex: namespace handling (`DAV:`,
`urn:ietf:params:xml:ns:caldav`) is the entire job. The response parser must
tolerate missing properties, because servers differ wildly in what they
populate.

## Caching

v1: **nothing is cached.** Every call fetches. The ICS decision makes this
non-negotiable — an ICS URL is assumed to change whenever it likes — and for
CalDAV it is the conservative choice while correctness is being established:
a stale cache makes a timezone bug unreproducible.

A later phase may add a short TTL on **calendar discovery only** (pure
metadata, changes almost never) plus an `ETag`-keyed store so
`calendar-multiget` can send `If-None-Match`. Event data itself stays
uncached — it is the thing whose freshness the caller is asking about, and
`Last-Modified`-based staleness is not worth the class of bug it creates.

## Configuration

| Var | Default | Purpose |
|---|---|---|
| `CALDAV_URL` | *(empty)* | CalDAV base URL. Empty ⇒ CalDAV not configured. |
| `CALDAV_USERNAME` | *(empty)* | username |
| `CALDAV_PASSWORD` | *(empty)* | password / app password (secrets vault supported) |
| `CALDAV_CALENDARS` | *(empty)* | optional comma-separated `href`s to expose; empty ⇒ all discovered |
| `ICS_URL` | *(empty)* | ICS feed. `http(s)://` **or `file:///path/to/file.ics`**. Empty ⇒ ICS not configured. |
| `ICS_NAME` | `ics` | display name for the synthetic ICS calendar |
| `TZ` | PHP default (`UTC`) | **the** timezone: all output, and how `from`/`to` are read |

`CALDAV_CALENDARS` exists because a real CalDAV server exposes subscribed
holidays and shared team calendars, and each one would otherwise land in
every answer. Default stays "all", so the tool works with zero configuration.

Both sources are optional; at least one must be configured for the tools to
be usable, and with neither set they fail with a clear message naming the
env vars. (`context-shuttle` booting with no calendar configured stays
valid — the alerting prior art is stricter, but here the tools are simply
not usable.)

## Tool description text

The YAML `description` is what a model reads first, so it must carry what a
caller cannot infer:

- recurrence means **multiple results per event**, and `id` (not `uid`) is
  the handle for one occurrence;
- `from`/`to` are dates, **inclusive**, evaluated in the deployment's
  timezone;
- **all timestamps are already in that timezone** — nothing needs
  converting, and no offset reasoning is required;
- all-day events are dates with no time component;
- `truncated: true` means narrow the range rather than assume completeness;
- a non-empty `errors` string means some events were malformed or
  unschedulable — the rest are still valid.

## Testing strategy

The `get_transactions` precedent — `MockHttpClient` with a scripted response
factory, asserting the request *and* the parsed result — extended with the
fixtures that matter here. Every finding becomes a test:

- **Recurrence**: a weekly series across a DST boundary yields the right
  instants; occurrences share `uid` but have distinct composite `id`s; a
  moved override reports its actual start with the original
  `recurrence_id`; composite-id parsing covers every row of the table above,
  including a UID containing `::`.
- **Timezone**: three sources expressing one instant (UTC, named `TZID`,
  fixed-offset `TZID`) normalize to the same output under a fixed `TZ`;
  changing `TZ` changes the rendering but never the instant; a
  DST-crossing series renders the correct per-occurrence local time in
  several target zones (including the deliberate `04:00 … 05:00 … 04:00`
  case); an unresolvable `TZID` is counted in `errors` and logged with UID +
  calendar; `UTC-04:00` is recovered rather than rejected.
- **All-day**: flags, date-only emission, `end_exclusive`, and no day-shift
  under a non-UTC `TZ` (the `2026-10-31` regression).
- **Robustness**: a malformed component yields the other events plus
  `errors: "There were 1 malformed events"` (not a 422); a transport error
  retries once then reports cleanly; `401`/`403` name the env var and never
  leak the password.
- **ICS**: an `http(s)` fetch through `MockHttpClient`; a `file://` fetch
  through the filesystem path (finding 13); a changed file is reflected on
  the next call, proving nothing is cached.
- **Discovery**: calendars found by `resourcetype`, not display name;
  `readonly` from the privilege set; `CALDAV_CALENDARS` filtering;
  membership reported as event metadata.
- **Pipeline**: unconfigured-tool and tool-listing integration tests
  mirroring the `get_transactions` ones.

Fixtures are raw `.ics`/`.xml` strings in the test files (readable,
diffable, no fixture server in CI), plus one opt-in integration class that
runs against a real server when `CALDAV_URL` is set and is skipped otherwise,
so CI stays hermetic.

## Sequencing

- **Phase 1 — CalDAV events.** `sabre/vobject` + `CalDavClient`
  (discovery, `calendar-query`, `calendar-multiget`), the timezone rule,
  expansion, composite ids, `calendar_list_events`, `calendar_get_event`,
  env wiring, tests. This is "start with just reading just CalDAV events",
  with the hard semantics proven before anything is built on them.
- **Phase 2 — tasks.** `VTODO` through the same contract and mapper;
  `calendar_list_tasks`, `calendar_get_task`.
- **Phase 3 — ICS.** The `SourceFetcher` seam (http + `file://`), one
  synthetic calendar, dedupe by `(uid, start)` when CalDAV is also
  configured. Confirms the abstraction before writes put pressure on it.
- **Phase 4 (contingent) — CalDAV writes.** Events then tasks, on a single
  configured editable calendar, with `If-Match` optimistic concurrency from
  day one: "update the meeting I just showed you" is the real use case, and
  last-write-wins corrupts a shared calendar.

## Open questions

1. **`truncated` + `next_from` versus a `page` cursor.** Paging is settled;
   the shape is not. An ISO date cursor is transparent and lets a caller
   narrow a range themselves, but a looping caller resends a different
   `from` each time. A cursor token is less inspectable but unambiguous.
2. **Default `limit` of 100.** Right for "what's on this week"; low for
   "when is my next holiday", which a caller can now hit `truncated` on.
   Worth revisiting once real queries exist.
3. **`readonly` on an event.** Discovery knows it, so it is cheap to
   include, but with no writes in v1 nothing consumes it. Keep it as
   forward-compatibility, or drop it until Phase 4?
4. **Composite id when `TZ` changes.** The occurrence half is rendered in
   `TZ`, so the same occurrence has a different `id` after a `TZ` change.
   That is harmless when `TZ` is stable per deployment (its intended use),
   but it makes ids non-portable across deployments, and would break a
   stored id after an operator change. Store the occurrence in UTC instead,
   and accept an offset that does not match the displayed time?
5. **Two sources at once.** Should CalDAV and ICS be usable together in v1
   (merged listing, dedupe by `(uid, start)`) or is one-or-the-other
   simpler to reason about until Phase 3 is real?

Resolved in review: one `TZ` env var (no alias); **all output normalized
into `TZ`** so the caller never does offset/DST reasoning; `UID::Occurrence`
composite ids; paging with `truncated` + `next_from`; tasks default to open
tasks only; calendar membership is event metadata rather than its own tool;
Basic auth for v1; `errors` is a single human-readable string with
UID+calendar detail in the log; ICS is a URL that may be `file://` and may
change at any time.

Also settled while drafting: no writes in v1; no server-side `expand`
(finding 2); `symfony/http-client` + `sabre/vobject` over `sabre/dav`'s
client; date-granularity `from`/`to`; no caching of any kind.
