# Calendar writes — findings against a live server

Everything here was measured against the Radicale 3.8.0 fixture instance
(`http://localhost:5232`, `lyra/lyra-secret`) before any write code was
written, because the design deliberately left these questions open:

> *to be decided against a real server when the work starts rather than
> guessed at now* — `CALENDARS.md`, "What is still open"

Probes are reproducible with `curl`; the ones that mattered are quoted.

## 1. `If-Match` / `If-None-Match` are enforced — optimistic concurrency works

| Request | Result |
|---|---|
| `PUT` new object, `If-None-Match: *` | `201 Created` + `ETag` |
| `PUT` again with `If-None-Match: *` | **`412 Precondition Failed`** |
| `PUT` with a stale `If-Match` | **`412 Precondition Failed`** |
| `PUT` with the current `If-Match` | `204 No Content` + new `ETag` |
| `DELETE` with a stale `If-Match` | **`412 Precondition Failed`** |
| `DELETE` with the current `If-Match` | `200 OK` |
| `DELETE` of something absent | `404 Not Found` |

So the design's "`If-Match` from day one" is not merely supported, it is
**enforced by the reference server** — the last-write-wins corruption it
exists to prevent is genuinely preventable here. `412` is the signal to
surface as "someone else changed this", distinct from a generic failure.

## 2. The server does **not** enforce UID ↔ href consistency

A `PUT` whose payload `UID:different-uid@test` landed at
`name-does-not-match.ics` with `201 Created`: the filename and the UID are
free to disagree.

Two consequences:

- We choose the href. Nothing stops a future second write for the same UID
  from creating a *second* object, so **we** have to be deliberate about it.
- **A duplicate UID in a second href is rejected** (`409 Conflict`), which
  means the server *does* enforce per-collection UID uniqueness even though
  it does not tie a UID to a filename. That is what makes "one object per
  UID" a server-backed invariant rather than a convention we hope for.

**Our rule:** for a new event the href is derived from the UID, but never
verbatim (see 3). For an existing event the href is taken from the object we
already fetched, so a rename is impossible by construction.

## 3. A UID is attacker-influenced text and **must not** become a path

Putting `UID:evil/../../pwned@test` and requesting the percent-encoded href
`/lyra/work/evil%2F..%2F..%2Fpwned.ics` created a real object at
**`/lyra/pwned.ics`** — *outside* the target collection. Confirmed on disk:
`/tmp/rad/collections/collection-root/lyra/pwned.ics`.

This is the one finding that changes the design. The UID arrives from a
server or from a caller's `uid` argument, and if it is interpolated into a
request path, `..` escapes the calendar the operator designated as the only
writable one. A write tool would then be a way to write **anywhere** the
account can reach, which is exactly the boundary `CALDAV_EDITABLE_CALENDAR`
is supposed to be.

**Mitigations, both applied:**
- A UID is never used as a path segment. New objects get a generated,
  opaque filename (`bin2hex(random_bytes(16)).'.ics'`); the UID lives in the
  payload, which is where it belongs.
- Every href we PUT or DELETE to is confirmed to still sit under the
  configured calendar's href, and to contain no `.`/`..` segment, as a
  belt-and-braces check *in addition* to the generated name.

## 4. Recurrence: a per-occurrence edit is an in-place `PUT` of the same object

The design asked how "edit one occurrence" should work. Measured:

- A master with `RRULE:FREQ=WEEKLY;COUNT=4` plus a second `VEVENT` carrying
  `RECURRENCE-ID` for one slot is **a valid single calendar object**. It was
  accepted as an in-place `PUT` with `If-Match` (`204`), and the object
  afterwards contains both components.
- Our own reader then reports the moved occurrence with the *new* start in
  `start`, and the *original* slot in `recurrence_id` — the mapper already
  documents this ("moved to 15:00 stays distinguishable from always 15:00").

```
id=probe-series@test::2026-12-14T15:00:00Z  start=2026-12-14T10:00:00-05:00
                                            rec-id=2026-12-14T10:00:00Z
```

The id's occurrence half is always the occurrence's **current start**
(`DTSTART`, in UTC) — for a moved occurrence that is the *new* time, not the
original slot. What differs is the relationship to `RECURRENCE-ID`:

| Occurrence | `DTSTART` | `RECURRENCE-ID` | equal? |
|---|---|---|---|
| unmodified | `…12-07T10:00Z` | `…12-07T10:00Z` | yes |
| moved override | `…12-14T15:00Z` | `…12-14T10:00Z` | no |

So an id alone does not say which *slot* it came from; the object has to be
expanded around the named instant to recover its `RECURRENCE-ID`. That is
the step that makes "edit this occurrence" well-defined: expand, find the
occurrence whose `DTSTART` matches the id, and read its `RECURRENCE-ID` —
which is the original slot, and therefore the value a new override needs.

**Decision:** v1 supports editing a *single occurrence* by writing an
override into the existing object, and editing *the whole series* by
replacing the master component in place. Both are in-place `PUT`s with
`If-Match`, so neither can create a duplicate. Editing "this and all future
occurrences" (splitting the series) is **not** supported and is refused
rather than approximated.

## 5. Deleting one occurrence is `EXDATE`, not a `DELETE`

`EXDATE` on the master removes a single occurrence and leaves the rest
(verified: 4 occurrences → 3, the excluded slot gone). A `DELETE` on the
href would remove the entire series.

**Decision:** deleting one occurrence rewrites the object with an added
`EXDATE`; deleting the whole series issues a real `DELETE`. The distinction
is made from the same rule as §4 — a composite id names an occurrence, a
plain UID names the series.

## 6. sabre/vobject escapes on serialize — building via the data model is safe

`SUMMARY` set to a string containing `\n` and a literal
`DESCRIPTION:injected\r\nBEGIN:VALARM` serialized as:

```
SUMMARY:Line one\nDESCRIPTION:injected\nBEGIN:VALARM
```

Folded onto one property with the control characters escaped — **no header
injection**, no smuggled component. Semicolons, commas and backslashes in
`DESCRIPTION` were escaped too. So the rule is: build payloads through
`VCalendar`/`VEvent` and the property API, and never by string
concatenation. Hand-rolling would put caller-supplied `summary` straight
into the wire format.

Round-tripping an object that contains properties we do not model
(`ATTENDEE`, `VALARM`) preserved them intact, which is what makes
"fetch, modify, write back" safe rather than destructive.

## 7. A `PUT` into a non-existent collection is a clean failure

`409 Conflict` for a PUT to `/lyra/nonexistent/foo.ics`. Not a crash and not
a silent create, so a mis-designated calendar surfaces as an error the
operator can act on rather than as a phantom calendar.

## What this implies for the implementation

1. **`If-Match` on every update and delete; `If-None-Match: *` on create.**
   Treat `412` as its own error, not a generic HTTP failure — it is the
   optimistic-concurrency signal and the caller needs to know to re-read.
2. **Generate opaque hrefs.** Never derive a path from a UID or any other
   caller/server-supplied string.
3. **Re-read before write.** An update is *fetch current object → modify →
   PUT with the etag we just saw*, which is both how `If-Match` gets its
   value and how unmodelled properties survive.
4. **Occurrence vs series is decided by the id.** Composite id ⇒ occurrence
   (`RECURRENCE-ID` override for edit, `EXDATE` for delete). Plain UID ⇒ the
   whole series. No parameter to say which — the shape of the id already
   does, and a second way to say it would be a second thing to get wrong.
5. **"This and future" is refused, not approximated.**
