# Example: a streaming tool, end to end

`echo` shows a tool that returns an answer. `count` shows a tool that **narrates
itself while it works** — which is a different protocol path, and the one this
project had never exercised. This walkthrough is the working reference: every
transcript below is real output from a running server.

```bash
symfony server:start          # or: php -S 127.0.0.1:8000 -t public/
```

`count` takes `count` (integer, 1–20, default 5) and `notify` (boolean, default
false). It counts to `count`, one number a second.

---

## 1. Over MCP: the call streams

MCP keeps a session, so handshake first, then make the call. The transcript
shows the frame's **arrival time** relative to the start of the request.

```bash
# Handshake; keep the Mcp-Session-Id it returns.
SID=$(curl -sD - -o /dev/null -X POST http://127.0.0.1:8000/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{
        "protocolVersion":"2025-03-26","capabilities":{},
        "clientInfo":{"name":"curl","version":"1.0"}}}' \
  | grep -i '^Mcp-Session-Id:' | tr -d '\r' | awk '{print $2}')

# Count to four, announcing each step.
curl -sN -X POST http://127.0.0.1:8000/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H "Mcp-Session-Id: $SID" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{
        "name":"count","arguments":{"count":4,"notify":true},
        "_meta":{"progressToken":"tok-1"}}}'
```

```
+ 0.03s  {"method":"notifications/progress","params":{"progressToken":"tok-1","progress":1,"total":4,"message":"1 of 4"}}
+ 0.04s  {"method":"notifications/resources/updated","params":{"uri":"count://stream"}}
+ 1.04s  {"method":"notifications/progress","params":{"progressToken":"tok-1","progress":2,"total":4,"message":"2 of 4"}}
+ 1.08s  {"method":"notifications/resources/updated","params":{"uri":"count://stream"}}
+ 2.05s  {"method":"notifications/progress","params":{"progressToken":"tok-1","progress":3,"total":4,"message":"3 of 4"}}
+ 2.09s  {"method":"notifications/resources/updated","params":{"uri":"count://stream"}}
+ 3.05s  {"method":"notifications/progress","params":{"progressToken":"tok-1","progress":4,"total":4,"message":"4 of 4"}}
+ 3.09s  {"method":"notifications/resources/updated","params":{"uri":"count://stream"}}
+ 3.13s  {"id":2,"result":{"content":[{"type":"text","text":"{\"count\":4,…}"}],"structuredContent":{"count":4,"notify":true,"streamed":true,"lines":[1,2,3,4]}}}
```

Four seconds of work, four seconds of transcript: the frames arrive as they are
produced, not batched at the end. The final `result` comes last, after all the
narration.

### What each channel is for

Three channels, three kinds of fact, and they are not interchangeable:

| Channel | Carries | Answers |
|---|---|---|
| the `result` | `lines: [1,2,3,4]` | *what the answer is* |
| `notifications/progress` | `progress`/`total` | *how far along this call is* |
| `notifications/resources/updated` | the URI `count://stream` | *that the counted lines changed* |

**The numbers are in the resource, not in the notification.** That is forced by
the protocol: `notifications/resources/updated` carries only a URI. It is also
the better design — a notification that carried the payload would have to be
accumulated by the client to be read, whereas a resource can be read whole
whenever the client cares to, and the read reports how many lines are left.

**`progress` carries a percentage, never the line.** The line is already in the
resource and in the final result; a third copy in the progress message would be
a third place for it to be wrong.

## 2. Reading the resource

The notification said *that* it changed. The content is a separate read, and it
works whether or not a run is in progress:

```bash
curl -s -X POST http://127.0.0.1:8000/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H "Mcp-Session-Id: $SID" \
  -d '{"jsonrpc":"2.0","id":3,"method":"resources/read","params":{"uri":"count://stream"}}'
```

```
{"result":{"contents":[{
  "text":"1\n2\n3\n4",
  "uri":"count://stream","mimeType":"text/plain",
  "_meta":{"emitted":4,"remaining":0,"total":4}
}]}}
```

Read **before** any run, it answers with an empty stream rather than an error —
`{"text":"", "_meta":{"emitted":0,"remaining":0,"total":0}}` — because "nothing
has been counted" is a true answer and it is the first one a client will meet.

## 3. Over REST: no stream, the whole answer at once

```bash
curl -s -X POST http://127.0.0.1:8000/tools/count \
  -H 'Content-Type: application/json' \
  -d '{"count":4,"notify":true}'
```

```
{"tool":"count","result":{"count":4,"notify":true,"streamed":false,"lines":[1,2,3,4]}}
```

One JSON body, returned in about 0.03 s. Note `"streamed":false`: REST has a
single body to return and no way to carry an event stream, so it tells the tool
not to stream (see [Why REST never streams](#why-rest-never-streams)), the tool
returns everything at once, and — because there is nothing to watch — it does
not wait out its one-second cadence either.

This is the behaviour that used to be a **500**. Before the fix, `count` over
REST took the endpoint down: the tool streamed, and the REST controller's
attempt to read the streamed body as a string corrupted the response. See
`docs/design/DESIGN_CONSIDERATIONS.md` → "Why REST never streams".

## 4. Watching it in a browser or client

Any MCP client that displays progress and follows resource updates will show a
`count` run live. Two things about that are worth knowing:

- **`progress` appears only if the client asked for it.** `notifications/progress`
  requires the request to carry a `progressToken`; the SDK's helper is a no-op
  without one, because a server must not send progress to a client that did not
  request it. This is why `notify` exists — it drives the resource channel,
  which needs no client opt-in.
- **`notify: true` sends the update notice whether or not you subscribed.** That
  is deliberate for a test fixture (see below).

## 5. Why REST never streams

Both surfaces run the *same* pipeline, so the difference has to be explicit
rather than accidental. It is one flag, on the request, read by the tool:

```jsonc
// REST's request, built by ToolRestController:
{"params": {"name": "count", "arguments": {...},
            "_meta": {"app/streaming": false}}}   // ← the tool is told not to stream
```

```jsonc
// MCP's request, built by the client:
{"params": {"name": "count", "arguments": {...}}}
// no flag → streaming allowed, which is the protocol's default
```

`App\Mcp\StreamingIntent::allowed($requestContext)` reads it. Absent means
allowed, so MCP — which does not send the flag — keeps streaming; only an
explicit `false` turns it off. The state travels on the request rather than in a
shared service, so under a worker serving two callers one caller's choice cannot
reach the other's call.

As a backstop, `ToolRestController` refuses to coerce an `text/event-stream`
body at all: if a tool ever streams on REST despite the flag, the caller gets a
named 500 instead of a corrupted response.

## 6. The pieces, and what they are for

| File | Role |
|---|---|
| `config/tools/count.yaml` | The tool declaration: name, schema, defaults, 1–20 range |
| `src/Tool/Count/CountTool.php` | The handler — counts, and announces each step |
| `src/Tool/Count/CountResource.php` | Serves `count://stream`: the lines so far, plus `emitted`/`remaining`/`total` |
| `src/Tool/Count/CountStream.php` | The lines, keyed by session, in their own cache pool |
| `src/Tool/Count/CountResourceRegistrar.php` | Declares the resource on the server builder |
| `src/Mcp/StreamingIntent.php` | Reads the `app/streaming` flag from the request |

`count` is registered from YAML like every other tool. The **resource** is not:
a resource has a different shape (a URI and a MIME type, no arguments), and
stretching the tool YAML to cover both would be a worse contract than one
declaration in code.

### A runner-up design worth knowing about

`CountStream` keeps its state in a cache pool keyed by session id, and *not* on
the session itself. The obvious choice is the session — it has exactly the right
lifetime — and it is wrong, in a way that is easy to ship by accident:

The SDK keeps its own per-request state on the session, including
`_mcp.outgoing_queue`, the queue notifications are flushed from. `Session::set()`
only touches an in-memory copy that `save()` writes wholesale, so a `save()`
from inside a tool re-writes that queue as the tool's copy of the session
remembers it — resurrecting notifications the transport has already delivered.
The symptom is that **every notification is re-sent on every subsequent tick**.
A separate pool removes the conflict instead of trying to schedule around it.

## 7. What a *real* streaming tool would do differently

`count` is a fixture, and two of its choices are fixture choices:

- **It notifies without checking for a subscription.** The spec expects
  `notifications/resources/updated` to go only to sessions subscribed to that
  resource. `count` sends it to anyone who asks, so that a one-line `curl` can
  see the stream. A tool serving a real resource should check first — the
  subscription is on the session, written by `SessionSubscriptionManager`, and
  readable as `$context->getSession()->get('resource_subscriptions', [])`.
- **It announces by the clock, not by progress.** One second between lines is
  what makes it watchable. Real work would notify when something actually
  happened.

## 8. A note on `php -S`

The built-in server is single-threaded: while a streaming `tools/call` is open,
it cannot serve a second request, so a `resources/read` issued *during* a run
will not be answered until the run finishes and will report the finished stream.
That is the dev server, not the code — under `symfony server:start` (or any real
SAPI with workers) the read lands mid-run and returns the lines counted so far.
The tool writes each line through to its cache pool *before* announcing it, so
there is nothing to catch up on once a request can be served.
