# CALENDARS — context-shuttle

**Status:** planning (v0.3 draft, revised after second review) · sibling
docs: `SPEC.md`, `DESIGN_CONSIDERATIONS.md`, `ROADMAP.md` · supersedes the
v0.1 and v0.2 drafts

## Goal

Calendar read access as context-shuttle tools. One provider abstraction,
two sources (CalDAV, ICS), two component types (events, tasks), and one
**rule about time**: every timestamp that leaves this server is already in
the deployment's timezone, so a caller never has to know what an offset is.

Scope of **this document**: the read path — `calendar_list_events`,
`calendar_get_event`, `calendar_list_tasks`, `calendar_get_task`. CalDAV
writes come later, behind the same contracts. Of the two sources, **only
CalDAV is built in Phase 1**; ICS follows in Phase 3 behind the same
abstraction, and tasks in Phase 2.

**ICS is deferred to Phase 3** (review decision) — CalDAV only until the
read path is proven. It is still designed for here, because the interesting
part is not how to fetch a feed, it is the constraint it puts on the tool
surface: **when ICS arrives it must be indistinguishable from a read-only
CalDAV calendar.** A caller should never learn that the deployment has one
data source rather than another, so nothing provider-shaped may appear in
the output. `readonly` is the only signal that will differ, which is why it
exists from Phase 1 (see `readonly`, below). Because v1 expands every
recurring event, `readonly` sits on each occurrence rather than on the
calendar, so every output row is self-describing.

Non-goals for v1:

- **No writes.** CalDAV create/update/delete is a later phase. ICS is
  read-only by nature and stays that way.
- **No alarms/VALARM** in the tool surface. Parsed and ignored; exposing
  them is a later, purely additive change.
- **No background polling / subscriptions.** Every call fetches.
- **No OAuth.** Basic auth only (review decision). Google's CalDAV
  endpoint dropped basic auth, so Google is out of scope until an OAuth
  path exists — say so in the README rather than half-supporting it.
- **No multi-account.** One CalDAV server per deployment, like the other
  tools here.
- **No `file://` ICS sources** (review decision). `symfony/http-client`
  refuses the scheme (finding 13), and supporting it would mean a second
  fetch path plus a second test idiom for a case that is rare, awkward in a
  container, and has no `ETag`/`Last-Modified` to be clever with. ICS is
  `http(s)` or nothing; **if a feed is not reachable over HTTP it is not
  supported**, rather than half-supported through a parallel code path.
- **No provider vocabulary in the tool surface.** No `source`, `provider`,
  or `kind` field, and no "CalDAV" or "ICS" in a description. The provider
  is an implementation detail; exposing it would invite a caller to branch
  on it, which is exactly the coupling that makes ICS expensive to add.

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
| 13 | `symfony/http-client` **refuses `file://`**: `Unsupported scheme in "file:///…": "http" or "https" expected`. | ICS is `http(s)`-only. No filesystem fetch path, no fetch seam, no second test idiom. |
| 14 | ⚠️ Converting an all-day `DATE` through a timezone **shifts the day**: `2026-11-01` renders as `2026-10-31` in `America/New_York`. | `DATE` values are never timezone-converted, anywhere. |
| 15 | ⚠️ A bare `DATE` is **also a valid instant**: `new DateTimeImmutable('2026-11-01')` succeeds and yields `2026-11-01T00:00:00+00:00`. | Composite-id parsing must be regex-first with the date case tested before the instant case — a "does the tail parse?" rule silently misreads every all-day occurrence as midnight, then shifts its day (finding 14). See Composite ids. |

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
is the occurrence's start **in UTC** — `2026-10-09T15:00:00Z` for a timed
event, `2026-11-01` for an all-day one:

```
evt-standup@test::2026-10-09T15:00:00Z    (timed, minute at 15:00 UTC)
holiday@test::2026-11-01                  (all-day, a date not an instant)
```

UTC, not `TZ`, is deliberate (review decision): the id names **a point in
time**, and that must not move when an operator changes `TZ`. It also makes
ids stable if one is ever stored by a caller, and portable between
deployments. The cost is that `id` and `start` are *intentionally different
strings* for the same occurrence (`…T15:00:00Z` vs `…T11:00:00-04:00`); a
caller must compare them as instants or not at all, never as text. That is
worth stating in the tool description, because it looks like a bug.

**The parse rule is regex-first, and the date case comes first** (finding
15). Split on the *last* `::`; if there is none, the whole string is a UID.
Otherwise classify the tail:

| Tail matches | Kind |
|---|---|
| `^\d{4}-\d{2}-\d{2}$` | all-day occurrence — **a date, never an instant** |
| `^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z\|[+-]\d{2}:\d{2})$` | timed occurrence |
| anything else | not an occurrence — the whole string is a plain UID |

The order matters: `DateTimeImmutable` accepts `2026-11-01` as
`2026-11-01T00:00:00+00:00`, so a constructor-based "does the tail parse?"
check would treat every all-day occurrence as a midnight instant and the
reader would then shift its day under a non-UTC `TZ` (finding 14). The
timed form also **requires** an explicit `Z` or offset, so a floating
local time can never be mistaken for an instant.

This keeps a UID that merely contains `::` working, because its tail matches
neither pattern:

| Input | Resolves to |
|---|---|
| `evt-standup@test::2026-10-09T15:00:00Z` | UID `evt-standup@test`, occurrence 2026-10-09T15:00Z |
| `holiday@test::2026-11-01` | UID `holiday@test`, all-day occurrence 2026-11-01 |
| `evt-standup@test` | UID, no occurrence |
| `weird::uid::2026-10-09T15:00:00Z` | UID `weird::uid`, occurrence 2026-10-09T15:00Z |
| `weird::uid@test` | UID `weird::uid@test` |
| `a@b::not-a-date` | UID `a@b::not-a-date` |
| `a@b::2026-11-01T00:00:00` | UID `a@b::2026-11-01T00:00:00` — floating, so not an instant |

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
- The expansion window is clamped (366 days max) so a pathological `RRULE`
  cannot be used to burn the process.
- An unbounded daily series would otherwise be thousands of rows in a
  context window, which is what `limit` and the page cursor are for (below).

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
| `limit` | integer | – | 1–200, default **50** |
| `cursor` | string | – | opaque token from a previous call's `next_cursor`; omit for the first page |

Returns `{events: [...], count, has_more, next_cursor?, timezone, errors?}`.
A bare array would leave `has_more` and `errors` nowhere to live.

Each event: `id`, `uid`, `recurrence_id`, `summary`, `description`, `start`,
`end`, `all_day`, `end_exclusive`, `location`, `categories`, `status`,
`readonly`, `calendar` (`{name, href}`).

Ordering is by start instant, then summary, so the order is stable across
calls — a non-deterministic order makes a model's narration of a day wrong,
and it is also what makes a cursor meaningful.

### Paging: an opaque cursor ⚠️

**A page cursor, not a date** (review decision), because that is what the
other tools in this repo already speak: `get_transactions` and
`get_health_logs` both expose `limit`/`page` and return pagination metadata
from the upstream API, and the MCP protocol itself uses `cursor` /
`nextCursor` for list methods. An ISO-date cursor would have been a third
dialect — transparent, but asking a caller to re-derive a `from` on each
loop, and inconsistent with everything else here.

- The cursor is **opaque**: base64url of a small JSON payload carrying the
  last row's `(start instant, uid, id)`. Opaque means the caller echoes it
  back rather than constructing one, so the encoding can change later
  without breaking anything.
- `has_more: true` plus `next_cursor` means "there is another page"; pass
  the cursor back verbatim. No cursor in the response means the listing is
  complete.
- Because the cursor is anchored to the **last row's instant** rather than
  a row count, it stays correct when the underlying data changes between
  pages: an event inserted earlier in the range cannot cause a later page to
  skip or repeat rows the way an offset would.
- An unparseable cursor is a normal tool error naming the parameter, not a
  silent restart from the beginning.
- A cursor carries no credentials and no server state; it is just a
  position, so nothing needs to be stored or expired.

**`limit` defaults to 50** (review decision): large enough for a week of
events, small enough not to overwhelm a small model. Max 200, matching
`get_health_logs`, rather than the 500 of the v0.2 draft.

### `readonly`, per occurrence ⚠️

Every occurrence row carries `readonly` (review decision), **flat on the
event** rather than nested inside the `calendar` object.

- v1 **expands every recurring event**, so the output treats each occurrence
  as its own event. A row that means "standup on Oct 9" has to be readable
  on its own; making a caller correlate "can I edit this?" against a sibling
  nested object is exactly the inference a flat row exists to avoid.
- When writes land, the unit of mutation is the **occurrence** — "move
  tomorrow's standup" is an occurrence-level operation, and the field a model
  checks before attempting one belongs on the thing it is acting on.
- The *value* is still calendar-derived: a calendar's privileges are uniform,
  so every occurrence in a calendar reports the same `readonly`. This is not
  a claim of per-occurrence variance, it is a refusal to make the caller go
  and find it. If a server is ever found where one occurrence differs, the
  shape already supports saying so.
- It is the **only field that will distinguish an ICS calendar from a CalDAV
  one** when Phase 3 lands, and the only honest signal a caller gets that
  "edit this" is not an option — so it earns its place before the write
  tools exist rather than being retrofitted onto a shipped response shape.
- This placement is tied to expanding by default. An un-expanded series view
  would be per-series, so the field would need revisiting there — one
  more reason not to build one without a reason (see *Deferred
  deliberately*).
- The v0.2 draft carried a separate per-event `editable` field. That is
  still dropped: `readonly` is the single source of truth, and two fields
  that must always agree is a bug waiting to happen.

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
`priority`, `completed_at`, `categories`, `readonly`, `calendar`
(`{name, href}`).

- **Open tasks by default** (review decision): `include_completed` defaults
  to `false` and no date range is required, because "what's outstanding?" is
  the dominant question. A caller reconstructing history passes
  `include_completed: true` plus `from`/`to` on `due` and accepts the extra
  step.
- Paging is the same opaque cursor (`limit` / `cursor` / `next_cursor`).
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
    /** Non-display identifier used for logging only: 'caldav', 'ics'. */
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

`name()` is **for logs, not output** — it exists so an operator reading
`mcp_invocation` can tell which side a problem came from, and it must never
reach a tool result. That is the mechanism behind "ICS appears no different
from a read-only CalDAV calendar".

Expansion, normalization and DTO shaping sit **above** the provider in a
shared `CalendarReader`/`EventMapper`, so the timezone rule is implemented
once.

`ComponentType` is an enum (`VEVENT`/`VTODO`) rather than a string, making
the distinction a type error rather than a runtime surprise.

### ICS: deferred, and indistinguishable when it lands

An ICS source is **an `http(s)` URL** (review decision after finding 13: no
`file://`, so no fetch seam, no filesystem path, no second test idiom). Two
consequences that survive from the v0.2 draft:

- **It must be assumed to change at any moment**, so it is fetched on every
  call and never cached or memoized. There is no "refresh" concept to
  expose; freshness is the default. A feed has no `ETag`/`Last-Modified` to
  lean on either.
- **It is read-only, and that is the whole difference.** One synthetic
  `CalendarInfo` (named by `ICS_NAME`), `readonly: true` on every row it
  produces, everything else identical to a CalDAV calendar's events. No `source` field, no provider
  name in the output.

**Deferred to Phase 3** (review decision): CalDAV only until the read path is
proven. Designing it now anyway is the point — the constraint above is a
constraint on the *tool surface*, and it is cheap to honour while the surface
is still being designed and expensive to retrofit once a caller has learned
to branch on a provider field.

It is also why there is no `SourceFetcher` seam in this design. The v0.2
draft introduced one to abstract `http(s)` and `file://`; without `file://`
there is nothing to abstract — an ICS URL is just a `GET` through the same
`symfony/http-client` every other tool already uses, so the ICS provider
becomes a small class over the existing client.

Because an ICS feed is a single flat calendar with no discovery, it ignores
the `calendar` filter.

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
| `ICS_URL` | *(empty)* | ICS feed URL. **`http(s)://` only** (`file://` is not supported). Empty ⇒ ICS not configured. |
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

Only CalDAV is wired up in Phase 1, so during Phase 1–2 the `ICS_*` rows are
documentation of intent rather than live configuration. A non-`http(s)`
`ICS_URL` (in particular `file://`) is rejected with a message that says why,
rather than being silently ignored.

## Tool description text

The YAML `description` is what a model reads first, so it must carry what a
caller cannot infer. Nothing in it may name a data source.

- recurrence means **multiple results per event**, and `id` (not `uid`) is
  the handle for one occurrence;
- `from`/`to` are dates, **inclusive**, evaluated in the deployment's
  timezone;
- **all timestamps are already in that timezone** — nothing needs
  converting, and no offset reasoning is required;
- `id` is a UTC instant (or a date, for all-day events) and is **not**
  textually equal to `start`; compare them as times if at all;
- every row is self-describing — `readonly` sits on the event, not on its
  calendar, so a single occurrence carries everything needed to decide
  whether it can be edited;
- all-day events are dates with no time component;
- pass `cursor` back verbatim to page; `has_more: false` means the listing is
  complete;
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
  including a UID containing `::` and a floating local time.
- **Id stability**: the same occurrence has the same `id` under two
  different values of `TZ`, while `start` differs — asserting that the two
  are deliberately different strings and that the underlying instant is
  identical.
- **All-day id**: an all-day occurrence's `…::2026-11-01` id parses back as
  a **date**, not as midnight on the 31st or the 1st in some zone
  (finding 15) — the regression test for the trap above.
- **Paging**: a range longer than one page returns `has_more: true` and a
  `next_cursor`; following it yields the next page with no overlap and no
  gap; walking to exhaustion returns every event exactly once; an event
  added *before* the cursor between pages causes neither a skip nor a
  duplicate; a malformed `cursor` is a clear error, not a silent restart.
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
- **`readonly`**: a calendar advertising read-only privileges reports
  `readonly: true` and a writable one `false`; every expanded occurrence of
  a series carries it, so a single row is self-describing.
- **No provider leakage**: a test asserting that no tool result and no YAML
  description contains `caldav`, `ics`, `source`, or `provider` — so Phase 3
  cannot introduce a provider-shaped field by accident.
- **ICS** *(Phase 3)*: an `http(s)` fetch through `MockHttpClient`; a
  changed feed is reflected on the next call, proving nothing is cached; its
  events are shaped identically to a read-only CalDAV calendar's.
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
  expansion, composite ids, page cursor, `calendar_list_events`,
  `calendar_get_event`, env wiring, tests. This is "start with just reading
  just CalDAV events", with the hard semantics proven before anything is
  built on them.
- **Phase 2 — tasks.** `VTODO` through the same contract and mapper;
  `calendar_list_tasks`, `calendar_get_task`.
- **Phase 3 — ICS.** A small `http(s)` provider over the **existing**
  client, one synthetic `readonly` calendar, dedupe by `(uid, start)` when
  CalDAV is also configured. The acceptance criterion is not that it
  fetches, it is that its output is **indistinguishable from a read-only
  CalDAV calendar** — one `readonly` flag and nothing else.
- **Phase 4 (contingent) — CalDAV writes.** Events then tasks, on a single
  configured editable calendar, with `If-Match` optimistic concurrency from
  day one: "update the meeting I just showed you" is the real use case, and
  last-write-wins corrupts a shared calendar. `readonly` is how a caller
  knows which occurrences it can act on.

## Deferred deliberately (YAGNI)

Three questions came up in review and were closed with "if we run into it,
we'll sort it out". They are recorded so the reasoning is visible rather
than lost — but none of them blocks Phase 1, and none of them gets
speculative code.

1. **Un-expanded series.** v1 always expands, so a series is never returned
   as an `RRULE`. If a wide-window listing over a large series ever makes
   that hurt, the fix is a bounded window plus a "this series continues"
   signal — not handing the caller the recurrence rule, which is the
   reasoning the timezone rule exists to spare them. Revisit with a real
   calendar and a query that is actually slow.
2. **Dedupe key when CalDAV and ICS overlap.** Phase 3 dedupes by
   `(uid, start)`. Two sources for the same meeting may not share a UID, and
   two distinct events may. Nothing is built until ICS exists, and by then
   there is a real feed to test against — picking a key now would be
   inventing a rule with no evidence.
3. **Task paging without a date range.** Open tasks default to no range, so
   there is no window to anchor a cursor to. Either the cursor anchors to
   `(due, id)` with undated tasks last, or tasks grow a required range.
   Phase 2 settles it against real task data.

The trade-off being taken: a speculative answer to any of these costs design
surface now and would most likely be wrong. A design that documents the gap
beats one that silently guesses.

Settled in the third pass: `search` stays a case-insensitive substring over
**summary/description/location** — good enough for now, and widening it
(attendees, categories) is purely additive later. `readonly` is **per
occurrence**, flat on the event rather than nested in `calendar`, because v1
expands every recurring event and every output row should be self-describing.

Resolved in review (second pass): ICS is **`http(s)` only** (`file://`
dropped — not worth a parallel fetch path); paging is an **opaque
`cursor`/`next_cursor`** for consistency with the other tools and MCP
itself; `limit` defaults to **50** (max 200); `readonly` is included **from
Phase 1** because writes are coming; the occurrence half of a composite id
is **UTC** so it survives a `TZ` change; ICS is **deferred to Phase 3** and
must be indistinguishable from a read-only CalDAV calendar, with no
provider vocabulary anywhere in the tool surface.

Resolved in review (first pass): one `TZ` env var (no alias); **all output
normalized into `TZ`** so the caller never does offset/DST reasoning;
`UID::Occurrence` composite ids; tasks default to open tasks only; calendar
membership is event metadata rather than its own tool; Basic auth for v1;
`errors` is a single human-readable string with UID+calendar detail in the
log; ICS is assumed to change at any time.

Also settled while drafting: no writes in v1; no server-side `expand`
(finding 2); `symfony/http-client` + `sabre/vobject` over `sabre/dav`'s
client; date-granularity `from`/`to`; no caching of any kind.
