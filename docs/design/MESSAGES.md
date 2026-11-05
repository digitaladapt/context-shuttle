# MESSAGES — the message-bus tool family

**Status:** shipped · always registered — the store is this gateway's own

## What this is

Two tools — `send_message`, `get_messages` — plus a REST surface
(`GET/POST /messages`) over a native SQLite message store. context-shuttle
becomes the postal system for humans and residents: a conversation is an
append-only log keyed by name, and every participant — tool caller, REST
client, polling harness — reads and writes the same store with the same
monotonic id space.

Spec origin: the resident project's `SEND_MESSAGE.md` design note (the
resident harness consumes this family as its human-contact surface). This
document is the implementation record; the design argument lives there.

## The distinction from `send_alert`

| | `send_alert` | `send_message` |
|---|---|---|
| Direction | one-way | bi-directional |
| Expectation | fire-and-forget; no reply is owed | conversational; a reply is possible |
| Shape | operator notification (channel, severity) | stored thread, per conversation |
| Consumer | the operator's alerting | any polling client — today one resident harness; tomorrow, several |
| State | none | the conversation *is* the state |

`send_alert` says "something happened." `send_message` says "someone spoke."

## The store

A SQLite file — `MESSAGES_DB` when named, else `var/messages.sqlite` —
created idempotently on first use, never probed at boot (a directory that
cannot be written must not stop the gateway from serving its other tools).
One table: `(id, conversation, sender, body, created_at)`; one index on
`(conversation, id)`; WAL mode. No ORM, no migrations — the project carries
neither, and a message bus is two columns past what one SQLite file holds.

Deliberately append-only: nothing is updated, nothing is deleted, a
conversation is a log — the same discipline every client of it (a resident's
context window among them) already lives by.

## What the family decides, and what it passes through

- **A body is normalized, not trusted.** Scrubbed to well-formed UTF-8,
  control characters stripped (newline and tab survive), trimmed, capped at
  4000 bytes on a character boundary (`truncated` says so on the result).
  A body that is blank after all that is refused — "stored" must mean
  stored, the memory family's precedent.
- **A conversation name is an address.** Lowercase letters, digits, dot,
  underscore, hyphen; up to 64 characters. Empty means `default`, not an
  error — every caller that omits the parameter lands in the same place as
  one that sends `""`.
- **`id` is the currency.** The tool and the REST surface are two views of
  one store; a cursor from either pages in the other. A page reports
  `newest_id` (what it contains, or the `since` it was given when empty)
  and `more_available`, so a reader holds nothing but a number it can
  re-derive from the last page it read.
- **Reads include the caller's own past messages.** Re-reading one's own
  words is half the point — a resident whose exchanges fell out of its
  context window re-reads them here. There is no sender filter; the log is
  small and the reader is a model that can skim.
- **`get_messages` is for recall, not polling.** New mail is *delivered* to
  a resident by its harness as user turns; the tool's description says so,
  because a caller that could not know otherwise would burn turns asking an
  inbox question the delivery path has already answered.

## The one honest hole: identity

A `sender` is a *claim*, not a credential. This deployment authenticates one
actor for the whole gateway (one key, no per-operation permissions), so the
store cannot check who is speaking. `MESSAGES_ACTOR` names this deployment's
resident — the sender of record for a `send_message` that claims none — and
the REST POST defaults to `operator`. A caller can name a sender it is not;
the raw stream would expose that immediately, and v1 accepts the hole
rather than building a boundary that does not exist.

The fix is structural and pre-designed: per-participant keys, named at
registration, sender derived server-side — at which point the `sender`
parameter is removed. The group-chat morph (several residents, one shared
conversation, each harness polling the same store) is what makes that fix
necessary, and nothing in the store, the endpoints, or the tools changes
when it arrives: only the clients multiply.

## REST surface

```
GET  /messages?conversation=<name>&since=<id>&limit=<n>   → a page, oldest first
POST /messages  { body, conversation?, sender? }           → append (201)
```

Both sit on the same store as the tools — no MCP session, no tool-invocation
log entry, because a human posting through their harness is not a tool call.
Pre-authenticated by its caller, like the whole REST surface.

## Verification

- `tests/Unit/Tool/Messages/MessagesToolTest.php` — 14 tests over a
  throwaway SQLite file: send results, refusals, conversation isolation,
  cursor paging, truncation on a character boundary, control-character
  stripping, sender defaulting.
- `tests/Integration/MessagesRestTest.php` — the whole kernel: REST POST/GET
  contracts, 422 paths, cursor paging through HTTP, the **shared id space**
  (a REST cursor pages in `get_messages` and a tool send is visible to
  REST), and the family's always-registered posture.
