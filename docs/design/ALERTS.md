# ALERTS — context-shuttle

**Status:** planning (v0.1 draft, revised after review) · supersedes nothing ·
sibling docs: `SPEC.md`, `DESIGN_CONSIDERATIONS.md`, `ROADMAP.md`

## Goal

Two tool families sharing one delivery abstraction:

1. **One-way alerts** (`send_alert`) — "hey boss, the thing is done",
   with optional link. Ships first.
2. **Interactive requests** (`request_confirm`, `request_input`,
   `get_input`) — the LLM asks the human a question through the same
   provider, the human answers on a web form, and the answer is
   retrieved by follow-up:

   > "I want your input boss, do we change the CI for all the projects or
   > customize the CI for this one project?"

   → notification with a link → boss types a reply on a tiny web page →
   submit → the answer is fetched via `get_input` (or the harness's own
   polling) and handed back to the LLM.

Providers at launch: **ntfy** and **Discord** (webhook). The provider is
behind an interface so more can follow (email, Slack, Telegram…) without
touching tool contracts.

## Decisions locked in this review (v0.1)

- **Twig for the `/ask` form.** SPEC v1's "no Twig" non-goal was
  predicated on "no user interface"; the form is exactly one page, and
  Twig is the framework-blessed way to render it (Form component for
  the POST handling where it helps). SPEC.md's non-goals get a pointer
  here when Phase 1 lands.
- **`send_alert` carries a link from day one** (Phase 0), not deferred:
  the same `actionUrl` mechanism later renders the `/ask/{id}` link, so
  building it once in Phase 0 means Phase 2 adds nothing to the
  providers.
- **Interactive requests are non-blocking by default.** `request_*`
  returns an id immediately; `get_input` takes the id and returns
  `pending` / `answered` / `expired`. Blocking is an **opt-in**
  (`wait: true`, bounded by `timeout_seconds`) for clients that want
  one-shot semantics.
- **Harness-side polling without LLM intervention.** A lightweight
  status endpoint lets a harness (task-loom) poll for answers itself,
  so its tool queue never blocks on a human and nothing depends on the
  LLM remembering to follow up — or doing so correctly. Non-task-loom
  clients use `get_input` or `wait: true` as suits them.

## Non-goals

- No Discord bot / native interactive components (buttons, modals) in
  v1 — the web form is provider-agnostic and works everywhere. Native
  components are a possible later phase behind the *same* tool contract
  (see Phase 3).
- No inbound callbacks/webhooks in v1 — the shuttle would have to be
  reachable by the harness and per-request callback routing adds
  surface; polling covers the need. `callback_url` stays on the shelf
  as a future option.
- No multi-recipient routing, templating, or digesting/scheduling.
  One deployment = one "boss" (the configured destination).
- No persistence layer for anything in Phase 0 (see Pending-request
  store for the one exception, which is cache, not ORM).

## Tool surface

### `send_alert` (Phase 0)

| Parameter | Type | Req | Notes |
|---|---|---|---|
| `title` | string | ✅ | short headline, ≤ 200 chars |
| `body` | string | – | markdown-ish detail, ≤ 4 000 chars |
| `priority` | integer | – | 1–5, default 3 (see Priority model) |
| `tags` | array[string] | – | ntfy tags / discord topic hints; ≤ 8 |
| `link` | string | – | http(s) URL rendered as the notification's click action (ntfy `Click`, Discord embed link) |
| `link_label` | string | – | display label for the link, default "Open" |

Returns a delivery receipt: provider name, provider message id (when
the provider gives one), delivered priority, link attached. Result is
*not* `isError`-worthy unless delivery itself failed — that distinction
matters to the calling LLM.

Handler: `App\Tool\Alerts\AlertTool::sendAlert` as
`config/tools/send_alert.yaml`, same registration dance as
`get_transactions` (public service, clear "not configured" failure).

### `request_confirm` (Phase 2)

| Parameter | Type | Notes |
|---|---|---|
| `question` | string | the yes/no question, shown verbatim |
| `confirm_label` / `dismiss_label` | string | button labels, defaults "Yes"/"No" |
| `timeout_seconds` | integer | max wait, default 600, max 3600 |
| `wait` | boolean | default `false`; `true` blocks the call until answered/expired |

Non-blocking result: `{id, status: "pending", expires_at}`.
`wait: true` result: `{status: "answered", answer: true|false}` or
`{status: "expired"}`.

### `request_input` (Phase 2)

| Parameter | Type | Notes |
|---|---|---|
| `question` | string | shown verbatim on the form |
| `placeholder` | string | textarea hint, optional |
| `timeout_seconds` | integer | as above |
| `wait` | boolean | as above |

Same result shapes; `answer` is the reply text.

### `get_input` (Phase 2)

| Parameter | Type | Notes |
|---|---|---|
| `id` | string | the identifier returned by `request_*` |

Returns `{status: "pending"|"answered"|"expired", answer?, question?,
answered_at?}`. `expired` and `pending` are **normal results**, not
errors — the LLM must be able to reason about "not yet" and "nobody
answered" without the harness treating the tool as failed.

## Provider abstraction

```php
/**
 * One implementation per delivery channel.
 */
interface AlertProvider
{
    /** Non-display identifier: 'ntfy', 'discord', … */
    public function name(): string;

    /** Delivery receipt, or throws on failure. */
    public function send(OutboundAlert $alert): DeliveryReceipt;
}
```

`OutboundAlert` is a plain readonly DTO (title, body, priority, tags,
and an optional `actionUrl` + `actionLabel`). `send_alert`'s `link`
parameter and Phase 2's `/ask/{id}` URL are the *same* mechanism — one
code path, two callers. `DeliveryReceipt` likewise (provider, message
id, echoed priority). Providers are HTTP-push only; they never know
what a PendingRequest is. That separation is what keeps Phase 2 a
composite of existing parts.

The active provider is selected by env (`ALERT_PROVIDER`), instantiated
per deployment, autowired through a `#[Target]`-bound constructor
argument on the tool (the one-implementation-per-interface hint the
conventions file prescribes). Multi-provider fan-out ("send to both")
is a later config question, not a code change: the tool depends on an
interface, and a composite provider is just another implementation.

## Priority model

Five levels, modelled after ntfy's (the richer of the two):

| Level | Meaning | ntfy | Discord |
|---|---|---|---|
| 1 | FYI, no interruption | `min` | grey sidebar colour, no mention |
| 2 | routine | `low` | green |
| 3 | normal (default) | `default` | blue |
| 4 | important | `high` | orange |
| 5 | urgent, interrupt me | `urgent`/`max` | red + `@user` mention |

- A `Priority` value object validates 1–5 and carries per-provider
  renderings (`ntfyPriority(): int`, `discordColor(): int`,
  `mentions(): bool`). Mapping lives in one class, not scattered
  `match` statements.
- Discord colour = decimal int for the embed `color`. Mention at 5 is a
  **user id**, not `@everyone` — webhook `allowed_mentions` pins the
  allowed user and suppresses `@everyone`/`@here` unconditionally, so an
  LLM cannot mass-ping a server no matter what it puts in the body.
- Level-5 email-push / phone-push escalation is a future knob
  (`ALERT_ESCALATE_5_TO_EMAIL` or similar); out of scope for v1.

## Provider notes

### ntfy

- Config: `NTFY_URL` (server, default `https://ntfy.sh`), `NTFY_TOPIC`,
  optional `NTFY_TOKEN` for access-controlled servers.
- Publish via `POST {NTFY_URL}/{topic}` with `Title`, `Priority`, `Tags`
  headers; JSON body publish when we need ntfy extras — `click` for the
  link (opens in the ntfy app's in-app browser on mobile).
- Interactive (Phase 2): the `/ask/{id}` URL rides the same `click`
  field. Nothing new to invent.

### Discord

- Config: `DISCORD_WEBHOOK_URL` (+ optional `DISCORD_MENTION_USER_ID`).
- `send_alert` maps to a webhook message with an embed (title, body,
  colour-by-priority, footer with tool name, link as the embed URL).
  Tags → embed fields or `[tag]` title prefixes; decide during
  implementation, keep it plain.
- `allowed_mentions: {users: [...]}` as above. Suppress notifications
  below priority 5 entirely by not mentioning.
- Interactive (Phase 2): the `/ask/{id}` link is the embed URL —
  webhooks cannot create real buttons (that's Phase 3 territory).

## Interactive requests (Phase 2) — the interesting part

### Interaction model: non-blocking by default

The instinct is to make `request_input` block until the human answers
(open-webui's `ask_user` pattern). Two problems with making that the
*only* mode:

1. A blocking tool call ties up a harness worker for minutes and
   depends on the client tolerating one long request.
2. The non-blocking alternative — the LLM calls a follow-up tool later
   — depends on the model *remembering* to follow up, and doing so
   correctly, with the right id, before the TTL lapses.

Both problems disappear when the **harness** owns the waiting: it can
poll a plain HTTP status endpoint on its own schedule, no LLM
intervention, and feed the answer back to the conversation when it
lands. task-loom's tool queue never stalls on a human; other clients
keep the simpler options.

| Pattern | Verdict |
|---|---|
| Non-blocking `request_*` → id → `get_input` follow-up | ✅ **Default.** Works for any MCP client; the LLM (or the harness, on the client's behalf) fetches the answer. |
| Harness polls the status endpoint directly | ✅ **Primary for task-loom.** No LLM involvement, no blocked tool queue, no reliance on model memory. |
| Blocking with TTL (open-webui `ask_user`) | ✅ **Opt-in** (`wait: true`) for one-shot clients and scripts. Same tool, bounded by `timeout_seconds`. |
| MCP elicitation (server→client request) | ❌ A *client* capability we can't assume — and we want the boss on their phone, not whoever is at the MCP client. |
| Inbound callback/webhook on answer | 🕐 Deferred: needs inbound reachability and per-request routing; polling covers v1. Revisit if poll volume matters. |

### Flow (non-blocking, harness-polled)

```
LLM → request_input(question)
        │
        ├─ PendingRequest stored (id, TTL)
        ├─ OutboundAlert{actionUrl: /ask/{id}} → provider → boss's phone
        └─ returns {id, status: "pending", expires_at}   ← immediately
                                   │
boss opens /ask/{id}, types, submits ┘
        │
        ▼
  store.status = answered, reply saved
        │
        ▼
task-loom polls GET /inputs/{id}  (or: LLM calls get_input)
        │
        ▼
answer fed back into the conversation → LLM proceeds
```

With `wait: true`, the third step instead blocks inside the handler
(polling the store, ≤ `timeout_seconds`) and returns the final answer
as the tool result — the open-webui shape, for clients that want it.

### Status endpoint (harness surface)

- `GET /inputs/{id}` — plain JSON `{status, answer?, question?,
  expires_at, answered_at?}`. Deliberately **outside the tool
  pipeline**: a harness polling every few seconds must not flood the
  `mcp_invocation` log (a 5-second cadence over a 10-minute TTL is 120
  log lines per request). Tool-grade interactions go through
  `get_input`; machine-grade polling goes here.
- Same id-as-capability model as the form: 128 random bits,
  single-use, expires with the request. No separate auth in v1; the
  form route and this endpoint are first in scope when the ROADMAP
  `TOOL_TOKEN` auth item lands.

### Web form (Twig)

- `GET /ask/{id}` — minimal server-rendered Twig page: question text,
  confirm buttons or a textarea, submit. No JS required.
- The id *is* the capability: 128 random bits, single-use, expires with
  the request. No login on the form itself — same trust model as an
  ntfy topic name, and the payload is the boss's own answer.
- `POST /ask/{id}` stores the reply, flips status, shows "thanks, you
  can close this tab".
- Single-use: once answered, `GET` shows "already answered", `POST` is
  rejected. Re-requesting a question mints a new id.

### Pending-request store

- `PendingRequest` id (128-bit, URL-safe), question, type, options,
  created-at, expires-at, status (`pending|answered|expired`), and the
  reply. Store in **`symfony/cache`** (already a dependency) —
  filesystem or Redis adapter, TTL = `timeout_seconds` + grace. No
  Doctrine, no migrations, per AGENTS.md's "smallest option" rule.
- Expiry handled lazily (read checks `expires_at`); a janitor command
  is only added if the store proves noisy.

## Open questions

1. **`wait` semantics.** Default `false` per the model above — confirm.
   Should `wait: true` be offered on both `request_confirm` and
   `request_input`, or is one-shot blocking really only a
   `request_input` use case?
2. **Long-poll on the status endpoint.** `GET /inputs/{id}?wait=30`
   (server holds up to 30 s until status changes) would cut poll
   traffic ~an order of magnitude vs short polling. Nice-to-have or
   YAGNI for v1?
3. **Link trust.** `send_alert`'s `link` lets the LLM put any URL in
   front of the boss, who trusts notifications. Options: unrestricted
   (v1, simplest), a configurable allowlist (`ALERT_LINK_ALLOWLIST`,
   prefix match), or same-host-only default with an env escape hatch.
   Lean allowlist-by-default; decide at Phase 0 implementation.
4. **Priority floor for interactive requests.** Floor at 4 (mention
   the boss) since the request needs a human act? Or default 3 and let
   the LLM raise it?
5. **Provider failure during Phase 2.** If the notification can't be
   delivered, the handler must fail fast (isError, no pending request
   created) rather than mint an id nobody will ever see. Agreed in
   principle; wording of the error to be settled in tests.
6. **`get_input` naming.** `get_input` vs `get_reply` vs
   `check_input` — `get_input` pairs naturally with `request_input`,
   but "input" reads oddly for `request_confirm` answers.
7. **Fan-out / multiple channels.** One provider per deployment for
   now; is "send to both ntfy and discord" wanted early enough to
   design the env shape now (`ALERT_PROVIDER=ntfy` scalar vs a list)?

Resolved this round: Twig (yes), link in Phase 0 (yes), blocking vs
non-blocking (non-blocking default, blocking opt-in), REST blocking
(moot — non-blocking is the default REST shape too), one-vs-two
interactive tools (two, plus `get_input`).

## Sequencing

- **Phase 0 — `send_alert` with link**: `Priority` VO,
  `AlertProvider` interface, `OutboundAlert` (incl. `actionUrl`),
  `NtfyProvider`, `DiscordProvider`, `AlertTool`, YAML, env vars, unit
  tests (MockHttpClient, PennyTrack-style) + integration test for the
  unconfigured-error path.
- **Phase 1 — plumbing for interactive**: pending-request store on
  cache, `/ask` routes + Twig form, `GET /inputs/{id}` status
  endpoint, single-use semantics, tested without any provider
  (direct HTTP against form and status endpoint).
- **Phase 2 — `request_confirm`, `request_input`, `get_input`**:
  non-blocking handlers, `/ask/{id}` link through the existing
  `actionUrl` path, `wait: true` opt-in blocking, end-to-end test
  with a fake provider.
- **Phase 3 (contingent)** — provider-native interaction (Discord
  buttons/components via a bot instead of a webhook) *behind the same
  tool contract*, and/or inbound callbacks, only if the web form and
  polling prove insufficient.

Phase 1 is split out deliberately: the form/store/status mechanics are
testable and useful independently, and Phase 2 then reduces to
handlers plus a link render.

## Testing strategy

- Unit: providers against `MockHttpClient` (assert headers, priority
  mapping, click/embed-URL link rendering, `allowed_mentions`
  suppression — the @everyone guard is a security-ish property, pin it
  with a test); `Priority` mapping table; `PendingRequest` lifecycle.
- Integration: unconfigured-tool friendly error (mirror the
  `get_transactions` test); `/ask/{id}` GET/POST single-use flow via
  `WebTestCase`; `GET /inputs/{id}` status transitions (pending →
  answered, pending → expired by TTL); full `request_input` round-trip
  with a scripted fake provider (no real network; fake answers
  immediately; a separate short-TTL test exercises expiry, and a
  short-`timeout_seconds` test exercises `wait: true` both ways).