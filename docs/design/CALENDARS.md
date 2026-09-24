# CALENDARS — context-shuttle

**Status:** planning (v0.1 draft) · sibling docs: `SPEC.md`,
`DESIGN_CONSIDERATIONS.md`, `ROADMAP.md` · supersedes nothing

## Goal

Calendar read access as context-shuttle tools. One provider abstraction,
two transports (CalDAV, ICS), two component types (events, tasks), and a
consistent answer to "what time is this actually at?".

Scope of **this document**: the read path only — `calendar_list_calendars`,
`calendar_list_events`, `calendar_get_event`, `calendar_list_tasks`,
`calendar_get_task`. Read/write (CalDAV `PUT`/`DELETE`) and ICS-as-a-file
provider come later, behind the same contracts.

Non-goals for v0.1:

- **No writes.** CalDAV writes (create/update/delete) are v0.2; ICS is
  read-only by nature and will stay that way.
- **No alarms/VALARM** in the tool surface. Parsed and ignored for now;
  exposing them is a later, purely additive change.
- **No background polling / subscriptions.** Every call is a fetch, and
  freshness is a per-call concern (see Cache).
- **No OAuth.** Username + password (and app passwords) only. Google's
  CalDAV endpoint dropped basic auth, so Google is out of scope until an
  OAuth path exists — say so in the README rather than half-supporting it.
- **No multi-account.** One CalDAV server per deployment, like the other
  tools here. `context-loom` has the same constraint for v1.

## What drove this design

Everything below was **verified against a live Radicale 3.8.0 instance**
(seeded with recurring, all-day, VTODO, TZID and override fixtures), not
inferred from documentation. `sabre/vobject` 4.6.1 was used for parsing.
The findings that actually changed the design are marked ⚠️.

| # | Finding | Consequence |
|---|---|---|
| 1 | A recurring series returns **one UID per occurrence**, and `expand` output still repeats that UID (verified: 4 occurrences, 1 distinct UID). | The tool result needs a **composite key** or the LLM cannot refer to one occurrence. |
| 2 | ⚠️ **Server-side `expand` produced wrong instants across a DST boundary.** For a `09:00 Europe/London` weekly series, Radicale applied the interval to the *UTC instant* of the first occurrence and never re-resolved the offset, so after London left DST the occurrences stayed at `08:00Z` — i.e. 08:00 GMT, an hour earlier than the 09:00 the event actually specifies. | **Never use server-side expansion.** Expand client-side (see below). |
| 3 | Client-side `sabre/vobject` `expand()` is DST-correct: `08:00Z`→`09:00Z` shift preserved 09:00 London wall clock. | The read path expands locally. |
| 4 | Returned `calendar-data` carries the `VTIMEZONE` definition, and `sabre` resolves it. | Trust the payload, not the local tz database alone. |
| 5 | `TZID=UTC-04:00` (fixed-offset pseudo-TZID) makes `sabre` **throw** `InvalidDataException`, at any `Reader` option level. Radicale itself rejects the same payload with `400`. | Treat a fixed-offset-TZID payload as unreadable-for-that-item, degrade to "date only + known offset if recoverable", and never let one bad event fail a whole listing. |
| 6 | An unresolvable `TZID` (`Custom/Zone`) silently yields **UTC** from `sabre`. | A naive pass-through would silently shift events. Must be detected and flagged. |
| 7 | `TimeZoneUtil::getTimeZone('UTC-04:00')` returns **UTC** — no error. | Silently wrong is worse than loud failure; do not rely on `TimeZoneUtil`. |
| 8 | A DST-ambiguous local time (01:30 America/New_York on fall-back day) is resolved to the **first** (DST) pass. | Ambiguity is inherent in the data; note it, don't invent one. |
| 9 | All-day events come back as bare `DATE` values; `sabre` materialises them as midnight in the default timezone (UTC here), since a `DATE` carries no zone. | Must be flagged `all_day` and formatted as a date with no time/zone, or they shift by a day for some clients. |
| 10 | `PROPFIND` on the principal returns `calendar-home-set`, and calendars are components of `/principal/`. `displayname` was the *path* in Radicale with this fixture while the display name for real deployments would be server-provided. | Discovery must be **property-driven** (`resourcetype` contains `<C:calendar/>`), not name-driven. Do not assume the display name. |
| 11 | `calendar-multiget` (batch fetch by href) works: 2 hrefs → 2 responses. | `calendar_get_event` should use it rather than a full listing + filter. |
| 12 | VTODO filtering behaves the same with and without a `<time-range>` filter on this server. | Cannot assume server-side VTODO date filtering; filter client-side after fetch. |

## Authentication

`CALDAV_URL`, `CALDAV_USERNAME`, `CALDAV_PASSWORD` — same shape as the
existing `PENNYTRACK_*` / `VITALPULSE_*` pattern, so the operator's mental
model carries over: unset means the tool is not usable, and invoking it
yields a clear "not configured" error rather than a container `TypeError`.

Two differences from the existing tools, both deliberate:

- The password **must** be allowed to live in the secrets vault
  (`bin/console secrets:set CALDAV_PASSWORD`) as well as `.env.local`. It is
  the first genuinely sensitive credential this project holds.
- `CALDAV_URL` is **not** probed at boot. A down calendar server must not
  prevent context-shuttle from booting, and every other tool here sets that
  precedent — the failure belongs at invocation time, with a message that
  names the env var.

Auth is **HTTP Basic only** in v1. That is a real (if small) cost of
building on `symfony/http-client` instead of `sabre/dav`'s client: the
latter negotiates `CURLAUTH_BASIC | CURLAUTH_DIGEST`, `HttpClient` does not.
Basic is what Radicale, Nextcloud, Baïkal and Fastmail all accept, and
digest-only servers are rare enough to defer; if one shows up it is a
contained change inside `CalDavClient` (see Open questions).

## Timezone model ⚠️

This is the part most likely to be silently wrong, so it gets its own
section and its own invariant.

**The invariant: every timestamp this tool returns is an unambiguous
instant.** Whatever the event stored, the output says what absolute moment
it is, plus enough context to render it. No field is ever a bare wall clock
whose meaning depends on who is reading it.

How that is achieved, per event:

1. **The stored definition is the source of truth.** Read
   `DTSTART`/`DTEND` raw values **plus** their `TZID` parameter, and the
   `VTIMEZONE` from the same payload. `sabre`'s resolved `getDateTime()`
   uses them.
2. **JSON output carries three fields**, so both the LLM and any downstream
   renderer have what they need:

   | Field | Example | Meaning |
   |---|---|---|
   | `start` | `2026-10-29T09:00:00+00:00` | the instant, always offset-qualified ISO 8601 |
   | `start_local` | `2026-10-29T09:00:00+00:00` | the same instant rendered in the display zone (below) |
   | `start_tz` | `Europe/London` | the zone the *event itself* is defined in (its `TZID`), or `null` for UTC/floating/all-day |

   `start` is not "UTC"; it is the event's own local time with its offset
   attached (`2026-10-29T09:00:00+00:00` above is 09:00 London/GMT — the
   event's own zone, which happens to be +00:00 that week). An event stored
   in `America/New_York` reports `-04:00`/`-05:00` as appropriate. Nothing
   is normalised to a foreign zone unless asked.

3. **`TZ` is the display zone.** `TZ` (with `TIMEZONE` as an alias, because
   the user asked for both and one of them is conventional) sets the zone
   that `*_local` fields render in, and the zone used for the
   `date`/`from`/`to` interpretation described below. Unset → PHP's
   `date_default_timezone_get()` (UTC in our containers).
   - It is **not** used to reinterpret stored event data. It cannot change
     `start`. A wrong `TZ` can only make the rendering wrong, never invent a
     different instant — that asymmetry is the point.
   - Invalid value → fail at boot with the list of valid zones, rather than
     silently falling back. This is the same "fail loudly at config time"
     rule as `CORS_RESOURCE_POLICY`.
   - PHP does **not** read `TZ` by itself (verified: `TZ=Australia/Sydney
     php -r 'echo date_default_timezone_get();'` → `UTC`), so this must be
     read explicitly. Do not assume the container's `TZ` reaches PHP.

4. **All-day events are dates, not midnights.** `all_day: true` and
   `start: "2026-11-01"` (no time, no offset). `DTEND` for an all-day event
   is exclusive per RFC 5545, so the tool also reports
   `end_exclusive: true` rather than letting a caller compute a duration
   that is off by one day. `*_local` mirrors the date.

5. **Unresolvable or exotic `TZID`s are flagged, never guessed.** If the
   `TZID` cannot be resolved from the payload's `VTIMEZONE` or the tz
   database (finding 6/7), the item still returns, with
   `"tz_resolved": false` and the raw value preserved in `start_raw`. A
   caller that sees this knows the offset may be wrong; a caller that does
   not look still gets the correct *wall clock* and the reason to distrust
   it. Silence, or a fabricated UTC, is the failure mode we are avoiding.

6. **Fixed-offset pseudo-TZIDs are recovered where possible.** If the raw
   value carries `TZID=UTC-04:00` (finding 5), the offset is parsable even
   though `sabre` refuses the property: emit the recovered offset, set
   `tz_resolved: false`, and keep `TZID` in `start_tz`. If it cannot be
   recovered, return the wall clock with `tz_resolved: false`.

**Range semantics (`from`/`to`).** Both are **dates** (`YYYY-MM-DD`) in the
display zone, half-open on the wire and inclusive in the vocabulary:
`from` is the start of that day, `to` is the end of that day (the server
sees `end = to+1 day at 00:00`). Date granularity is what an LLM actually
passes ("what's on next week?") and it removes a whole class of
off-by-one-timezone bugs at the boundary. An event is included when its
expanded occurrence **overlaps** the window — the server applies this to
event matching.

## Recurrence: the UID problem ⚠️

Finding 1 is the user's point, confirmed: a recurring event logically
appears many times, all with the same `UID`. Both "list" and "get" need to
address one occurrence.

The design:

- Every occurrence is returned with the **series UID** unchanged, plus
  `recurrence_id` (the occurrence's `RECURRENCE-ID`, ISO 8601) and
  `occurrence_start`.
- `id` — the addressable handle — is a **composite**:
  `{uid}__{occurrence_start_utc}`, e.g.
  `evt-standup@test__2026-10-09T15:00:00Z`. Stable, human-legible in a
  transcript, and derived only from data already present, so it can be
  recomputed rather than looked up.
- Non-recurring events get `id == uid` and `recurrence_id: null`. Master
  events returned without expansion (single occurrence) also keep `id ==
  uid`.
- `calendar_get_event` accepts **either** form. Given a plain UID it returns
  the master; given a composite id it narrows the server query to a
  one-occurrence window and returns exactly that occurrence. This is what
  makes "the 15:00 standup on the 9th" addressable when the series'
  other occurrences moved or were overridden.
- A **moved-override** occurrence (verified: `DTSTART` 15:00 with
  `RECURRENCE-ID` 08:00) reports its *actual* start, and its `id` uses the
  actual start — while `recurrence_id` keeps the original slot, so a caller
  can tell the difference between "moved" and "was always 15:00".

Expansion is **client-side and bounded**:

- Fetch with a `<time-range>` filter, **without** `<expand>` (finding 2).
- Expand locally with `sabre/vobject`, using the payload's own `VTIMEZONE`
  (findings 3, 4).
- Cap the result (`limit`, default 100, max 500) and report
  `truncated: true` + `next_from` when hit. An unbounded daily series over a
  year is otherwise thousands of rows into an LLM's context, which is both
  a cost and a comprehension problem.
- Expansion window is clamped to a maximum span (e.g. 366 days) so a
  pathological `RRULE` cannot be used to DoS the process.

## Tool surface

Names follow this repo's existing `get_*` convention (`get_weather`,
`get_transactions`, `get_health_logs`) rather than `context-loom`'s
domain-prefixed names, which belong to a different project's
conventions. Five tools, all read-only.

### `calendar_list_calendars`

| Parameter | Type | Req | Notes |
|---|---|---|---|
| `include_readonly` | boolean | – | default `true`; whether to report calendars lacking write privilege |

Returns every calendar collection discovered under the principal, with
`name` (display name), `href` (its path — the stable identifier),
`components` (`["VEVENT","VTODO"]`), `readonly` (derived from
`current-user-privilege-set`, finding 10), and `description`.

This answers "which calendar do I pass?" for every other tool, and is the
first thing an LLM should call. `href` — not `name` — is the identifier to
pass back: display names are not guaranteed unique, and Radicale returned the
*path* as the display name in testing.

### `calendar_list_events`

| Parameter | Type | Req | Notes |
|---|---|---|---|
| `from` | string | ✅ | `YYYY-MM-DD`, inclusive, in display zone |
| `to` | string | ✅ | `YYYY-MM-DD`, inclusive |
| `calendar` | string | – | restrict to one calendar `href` or display name; default all |
| `search` | string | – | case-insensitive substring over summary/description/location |
| `limit` | integer | – | 1–500, default 100 |
| `timezone` | string | – | override display zone for this call |

Returns `{events: [...], count, truncated, next_from, timezone}` — a bare
array would leave `truncated` nowhere to live. Each event carries `id`,
`uid`, `recurrence_id`, `summary`, `description`, `start`/`end`/`start_local`/
`end_local`/`start_tz`, `all_day`, `end_exclusive`, `location`,
`categories`, `status`, `calendar` (name + href), `editable`, `tz_resolved`,
`start_raw`.

Ordering is by start instant ascending; events with identical starts sort by
summary so the order is stable across calls (a non-deterministic order makes
the LLM's "and then…" narration wrong).

### `calendar_get_event`

| Parameter | Type | Req | Notes |
|---|---|---|---|
| `id` | string | ✅ | composite occurrence id, or a plain series UID |
| `calendar` | string | – | narrow the search |
| `timezone` | string | – | override display zone |

One event, same shape as a list element. When `id` is a composite, the
`RECURRENCE-ID` time is used to bound the query to a narrow window
(finding 11 makes this a `calendar-multiget`-style narrow fetch, not a full
listing). Not found → a normal tool error naming the id.

### `calendar_list_tasks` / `calendar_get_task`

VTODO, same shape as events with `summary`, `description`, `due` (nullable,
same three-field time treatment), `status` (`NEEDS-ACTION`/`IN-PROCESS`/
`COMPLETED`/`CANCELLED`), `percent_complete`, `priority`, `completed_at`,
`categories`, `calendar`.

Two deliberate differences from events:

- **No date range required.** A task list is small and "what's
  outstanding?" is the dominant question; making the caller supply a window
  would force a wrong answer for undated tasks. Optional `from`/`to` filter
  on `due`, plus `include_completed` (default `false`).
- **Server-side date filtering is not trusted** (finding 12), so filtering
  is client-side after the fetch.

## Provider abstraction

Both providers satisfy one contract, so the tool layer never learns which
transport it is talking to. This is also the seam that ICS drops into later
without touching a single tool signature.

```php
interface CalendarProvider
{
    /** Non-display identifier: 'caldav', 'ics'. */
    public function name(): string;

    /** @return list<CalendarInfo> */
    public function listCalendars(): array;

    /**
     * Raw VEVENT/VTODO components overlapping the window, unexpanded.
     *
     * @return iterable<CalendarObject>  component + href + calendar + etag
     */
    public function fetch(string $calendarHref, \DateTimeImmutable $from, \DateTimeImmutable $to, ComponentType $type): iterable;
}
```

Expansion, timezone resolution and DTO shaping sit **above** the provider in
a shared `CalendarReader`/`EventMapper`, so both providers get the identical
timezone and composite-id semantics by construction. If ICS did its own
expansion, the two transports would drift exactly where it is most dangerous
(recurrence + DST), which is the same argument the repo already makes for
routing REST through the MCP pipeline.

`ComponentType` is an enum (`VEVENT`/`VTODO`) rather than a string, so the
`VEVENT` vs `VTODO` distinction is a type error rather than a runtime
surprise.

## Transport decision: Symfony HttpClient + sabre/vobject

`sabre/dav` ships a full CalDAV client, and `context-loom`'s spec picks
`sabre/dav + sabre/vobject`. **This project should not.**

- The CalDAV read surface we need is three requests: `PROPFIND` (discover),
  `REPORT calendar-query` (list), `REPORT calendar-multiget` (get). They are
  XML bodies over HTTP — a `CalendarRequest` value object and a
  `CalendarResponse` parser for each.
- `sabre/dav`'s client would add `sabre/http`, `sabre/event`, `sabre/uri`,
  plus cURL plumbing, and a `Client` object whose transport we do not
  control. It also drags in a server-side DAV stack we will never run.
- `symfony/http-client` is **already a dependency** and is what
  `get_transactions` and `get_health_logs` use, and `MockHttpClient` is what
  their tests use. Using it means the CalDAV tests look exactly like the
  existing tests, and `TRACE`/profiler/`retry_failed` behaviour is uniform
  across every tool.
- What we actually want from sabre is `vobject`: `Reader`, `expand()`,
  `VTIMEZONE` resolution. That package is small and dependency-light
  (`sabre/xml`, `sabre/uri`), and it is the piece that demonstrably gets
  DST right (finding 3).

So: **`composer require sabre/vobject`** (plus `sabre/xml`, pulled
automatically), and a ~small `CalDavClient` over `symfony/http-client`
speaking the three requests above. It is less code than configuring
`sabre/dav`'s client, and it keeps one HTTP stack in the project.

Two client behaviours borrowed from the prior art in `mcp-server` because
they were paid for in production bugs:

- **Stale connection recovery**: one retry after a transport error /
  `5xx` on an idempotent `PROPFIND`/`REPORT`, then fail. CalDAV servers
  restart; a retry turns a scary failure into a slow success.
- **Per-item failure isolation**: one malformed event must not fail the
  listing (finding 5). Collect it as an entry in `errors` and carry on.
  An LLM asked "what's on tomorrow?" is much better served by four events
  plus "1 event could not be parsed" than by a 422.

### XML parsing

`sabre/xml` (`Service`/`Deserializer`) for the DAV envelope, not
`SimpleXML` by hand and not regex. Namespace handling is the entire job here
(`DAV:`, `urn:ietf:params:xml:ns:caldav`), and this is well-trodden API
surface rather than something to hand-roll. Whatever is chosen, the response parser must be tolerant of missing
properties: servers differ wildly in what they populate.

## Caching

Calendar reads are cheap per call but the LLM tends to call them repeatedly
("what's on today?" → "what about the 14th?" → "and the details?").

v0.1: **no cache.** Every call hits the server. Correct beats fast, and
correctness is exactly what we are still establishing — a stale cache would
make a timezone bug unreproducible.

v0.2 (only if calls prove noisy): a short-TTL `symfony/cache` on the
**calendar discovery** call only (`calendar_list_calendars`), which is pure
metadata and changes almost never, plus an ETag-keyed store so
`calendar-multiget` can send `If-None-Match`. Event data itself stays
uncached — it is the thing whose freshness the caller is actually asking
about. Per-request connection reuse is a `McpServerFactory`-level concern,
not a calendar one.

## Configuration

| Var | Default | Purpose |
|---|---|---|
| `CALDAV_URL` | *(empty)* | CalDAV base URL. Empty ⇒ tool unconfigured. |
| `CALDAV_USERNAME` | *(empty)* | username |
| `CALDAV_PASSWORD` | *(empty)* | password / app password (secrets vault supported) |
| `CALDAV_CALENDARS` | *(empty)* | optional comma-separated `href`s to expose; empty ⇒ all discovered |
| `TZ` | PHP default (`UTC`) | display zone for `*_local` fields and `from`/`to` interpretation |
| `TIMEZONE` | *(unset)* | alias of `TZ`; `TZ` wins if both are set |

`CALDAV_CALENDARS` exists because "a single CalDAV server can have many
calendars" (the user's point) is not only a discovery problem but a
**noise** problem: a real server exposes subscribed holidays, shared team
calendars and the like, and every one of them would otherwise land in every
answer. Default remains "all", so the tool works with zero configuration.

## Tool description text

The `description` in `config/tools/calendar*.yaml` is what the LLM reads
first, so it has to carry the things a caller cannot infer:

- that recurrence means **multiple results per event**, and that `id` (not
  `uid`) is the handle for one occurrence;
- that `from`/`to` are dates in the display zone, inclusive;
- that timestamps come back as instants plus `*_local` in the display zone,
  and that `tz_resolved: false` means "distrust the offset";
- that `truncated: true` means the range was wider than `limit` and the
  caller should narrow it rather than assume completeness.

## Testing strategy

The `get_transactions` precedent — `MockHttpClient` with a scripted
response factory, asserting the request *and* the parsed result — extended
with the fixtures that matter here. Every finding in the table above becomes
a test:

- **Recurrence**: a weekly series expanded over a DST boundary yields the
  right instants (the `08:00Z`→`09:00Z` shift); occurrences share a `uid`
  but have distinct composite `id`s; a moved override reports its actual
  start and its original `recurrence_id`.
- **Timezone**: a `Europe/London` event read under `TZ=Australia/Sydney`
  keeps the same `start` and changes only `*_local`; `tz_resolved: false`
  for `Custom/Zone`; offset recovery for `TZID=UTC-04:00`; a DST-ambiguous
  local time is returned without pretending to disambiguate it.
- **All-day**: flags, date-only formatting, exclusive-end reporting, and no
  day-shift under a non-UTC `TZ`.
- **Robustness**: a malformed component in a listing yields the other events
  plus an `errors` entry (not a 422); a transport error retries once then
  reports cleanly; `401`/`403` produce an auth message that does not leak the
  password.
- **Discovery**: calendars found by `resourcetype`, not by display name;
  `readonly` derived from the privilege set; `CALDAV_CALENDARS` filtering.
- **Pipeline**: unconfigured-tool error and tool-listing integration tests
  mirroring the `get_transactions` ones.

Fixtures live as raw `.ics` + `.xml` strings in the test files (readable,
diffable, and no fixture server to run in CI), with one opt-in integration
test class that runs against a real CalDAV server when `CALDAV_URL` is set —
skipped otherwise, so CI stays hermetic.

## Sequencing

- **Phase 1 — read path, events only.** `sabre/vobject` + `CalDavClient`
  (discovery, `calendar-query`, `calendar-multiget`), timezone resolver,
  expansion, `CalendarEvent` mapping, `calendar_list_calendars`,
  `calendar_list_events`, `calendar_get_event`, env wiring, unit +
  integration tests. This is the whole of "start with just reading just
  CalDAV events".
- **Phase 2 — tasks.** `VTODO` through the same provider contract and
  mapper; `calendar_list_tasks`, `calendar_get_task`.
- **Phase 3 — ICS provider.** Read-only, same contract, dedupe by
  `(uid, start)` when both providers are configured. Confirms the
  abstraction before it is under write pressure.
- **Phase 4 (contingent) — CalDAV writes.** create/update/delete for events
  then tasks, on a single configured editable calendar; `etag`
  `If-Match` optimistic concurrency from day one, because "update the
  meeting I just showed you" is the actual use case and last-write-wins
  corrupts a shared calendar.

Phase 1 is deliberately the *thin, correct* slice: if the timezone and
recurrence semantics survive it, everything after is additive.

## Open questions

1. **`TZ` vs `TIMEZONE`.** The design accepts both with `TZ` winning. Is the
   alias worth the ambiguity, or should exactly one name be canonical and
   the other rejected loudly?
2. **Should `TZ` affect `from`/`to` interpretation, or only rendering?**
   This design says both (one knob, consistent story). The alternative —
   `from`/`to` always in the event's own zone — is arguably safer but asks
   the LLM to reason about per-calendar zones.
3. **Composite `id` delimiter.** `__` is legible but is not impossible
   inside a real UID. Escaping, a different separator, or
   `${uid}::${occurrence}` — settle before implementation, since the format
   becomes part of the API.
4. **`truncated` behaviour.** Default `limit: 100` with `truncated` +
   `next_from` (this design), versus auto-narrowing the range, versus
   returning only counts for over-wide ranges. The first is predictable;
   the second thinks for the caller.
5. **Tasks: `include_completed` default.** `false` here ("what's
   outstanding"), but a caller asking "what did I finish last week?" needs
   `true` plus a date filter, which is the one case the date-range-optional
   decision makes awkward.
6. **One tool or two for calendars?** `calendar_list_calendars` as a
   separate tool from a `calendars` field on the list tools is one more
   round-trip but a cleaner contract. Prior art exposes it separately.
7. **Digest auth.** v1 is Basic-only. Is that acceptable, or does a digest
   fallback (a small `curl`-based path inside `CalDavClient`) need to exist
   before the first real deployment?
8. **`errors` array in a successful result.** Load-bearing for robustness
   (and for honest answers), but it is a field most callers will ignore.
   Does it deserve to be in the payload, or only in the invocation log?
9. **ICS as provider vs as file.** A feed (URL) is read-only and refreshes;
   a local `.ics` file has none of those properties. Confirm the v1 ICS
   scope is the feed only.

Resolved during drafting: no writes in v0.1; no server-side `expand`
(finding 2); `symfony/http-client` + `sabre/vobject` over `sabre/dav`'s
client; date-granularity `from`/`to`; composite occurrence ids; no cache in
v0.1.
