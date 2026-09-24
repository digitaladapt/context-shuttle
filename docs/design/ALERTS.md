# ALERTS — context-shuttle

**Status:** planning (v0 draft, pre-implementation) · supersedes nothing ·
sibling docs: `SPEC.md`, `DESIGN_CONSIDERATIONS.md`, `ROADMAP.md`

## Goal

Two tool families sharing one delivery abstraction:

1. **One-way alerts** (`send_alert`) — "hey boss, the thing is done."
   Ships first.
2. **Interactive requests** (`request_confirm`, `request_input`) — the
   LLM asks the human a question through the same provider, the human
   answers on a web form, and the *tool call itself* returns the answer:

   > "I want your input boss, do we change the CI for all the projects or
   > customize the CI for this one project?"

   → notification with a link → boss types a reply on a tiny web page →
   submit → `request_input` returns `"customize just this one"` to the
   harness.

Providers at launch: **ntfy** and **Discord** (webhook). The provider is
behind an interface so more can follow (email, Slack, Telegram…) without
touching tool contracts.

## Non-goals

- No Discord bot / native interactive components (buttons, modals) in v1 —
  the web form is provider-agnostic and works everywhere. Native
  components are a possible later phase behind the *same* tool contract
  (see Phase 3).
- No multi-recipient routing, templating, or digesting/scheduling.
  One deployment = one "boss" (the configured destination).
- No persistence layer for anything in Phase 0 (see Interactive store for
  the one exception, which is cache, not ORM).

## Tool surface

### `send_alert` (Phase 0)

| Parameter | Type | Req | Notes |
|---|---|---|---|
| `title` | string | ✅ | short headline, ≤ 200 chars |
| `body` | string | – | markdown-ish detail, ≤ 4 000 chars |
| `priority` | integer | – | 1–5, default 3 (see Priority model) |
| `tags` | array[string] | – | ntfy tags / discord topic hints; ≤ 8 |

Returns a delivery receipt: provider name, provider message id (when the
provider gives one), delivered priority. Result is *not* `isError`-worthy
unless delivery itself failed — that distinction matters to the calling
LLM.

Handler: `App\Tool\Alerts\AlertTool::sendAlert` as
`config/tools/send_alert.yaml`, same registration dance as
`get_transactions` (public service, clear "not configured" failure).

### `request_confirm` (Phase 2)

| Parameter | Type | Notes |
|---|---|---|
| `question` | string | the yes/no question, shown verbatim |
| `confirm_label` / `dismiss_label` | string | button labels, defaults "Yes"/"No" |
| `timeout_seconds` | integer | max wait, default 600, max 3600 |

Returns `{status: "answered", answer: true|false}` or
`{status: "expired"}`. `expired` is a **normal result**, not an error —
the LLM must be able to reason about "nobody answered" without the
harness treating the tool as failed.

### `request_input` (Phase 2)

| Parameter | Type | Notes |
|---|---|---|
| `question` | string | shown verbatim on the form |
| `placeholder` | string | textarea hint, optional |
| `timeout_seconds` | integer | as above |

Returns `{status: "answered", answer: "<text>"}` or `{status: "expired"}`.

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
and — Phase 2 — an optional `actionUrl` + `actionLabel` the provider
renders as a link/button). `DeliveryReceipt` likewise (provider,
message id, echoed priority). Providers are HTTP-push only; they never
know what a PendingRequest is. That separation is what keeps Phase 2 a
composite of existing parts.

The active provider is selected by env (`ALERT_PROVIDER`), instantiated
per deployment, autowired through a `#[Target]`-bound constructor
argument on the tool (the one-implementation-per-interface hint the
conventions file prescribes). Multi-provider fan-out ("send to both") is
a later config question, not a code change: the tool depends on an
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
  headers; JSON body publish when we need ntfy extras (actions, click).
- Interactive (Phase 2): the action URL rides along as ntfy's `Click`
  header, and a `view` action on mobile opens the form in the ntfy app's
  browser. Nothing new to invent.

### Discord

- Config: `DISCORD_WEBHOOK_URL` (+ optional `DISCORD_MENTION_USER_ID`).
- `send_alert` maps to a webhook message with an embed (title, body,
  colour-by-priority, footer with tool name). Tags → embed fields or
  `[tag]` title prefixes; decide during implementation, keep it plain.
- `allowed_mentions: {users: [...]}` as above. Suppress notifications
  below priority 5 entirely by not mentioning.
- Interactive (Phase 2): the action URL is an embed "button-style" link
  (webhooks cannot create real buttons — that's Phase 3 territory).

## Interactive requests (Phase 2) — the interesting part

### The non-realtime tool problem

A tool call is request/response; a human answer arrives minutes later.
Established patterns for closing that gap:

| Pattern | Who uses it | How it works | Fit here |
|---|---|---|---|
| **Blocking tool call** | open-webui `ask_user`, Claude Code/CodeIDE "ask the human" tools | Tool call holds the worker until the human answers or a TTL expires; answer is the tool result | ✅ Primary. We own the harness-side contract and most MCP clients already tolerate multi-minute tool calls (it's one long HTTP/SSE request). |
| **Polling** | callback-adjacent patterns; REST-ish | `request_input` returns an id immediately; a second `get_reply` tool polls for the answer | ❌ Requires the LLM to remember to poll; two tools where one should exist. Fallback if blocking proves problematic. |
| **Elicitation** | MCP spec (server→client request) | Server asks the *MCP client* to prompt the user | ❌ Only works if the client implements elicitation (client capability, not guaranteed) — and we want the *boss on their phone*, not whoever is at the MCP client. |
| **Progress + cancel** | long-running MCP ops | … | Orthogonal, doesn't deliver an answer. |

Decision: **blocking with a TTL**, exactly like open-webui's `ask_user`.
The tool's PHP handler sleeps/polls the pending-request store until
answered or expired, then returns. Caveats to verify during
implementation: web-server/FrankenPHP worker occupancy during long
blocks (bounded by `timeout_seconds`), and REST callers going through a
proxy with its own read timeout (document it; the REST surface can
later gain a non-blocking mode returning a status URL — flagged as an
open question, not built).

### Pending-request store

- `PendingRequest` id (128-bit, URL-safe), question, type, options,
  created-at, expires-at, status (`pending|answered|expired`), and the
  reply. Store in **`symfony/cache`** (already a dependency) — filesystem
  or Redis adapter, TTL = `timeout_seconds` + grace. No Doctrine, no
  migrations, per AGENTS.md's "smallest option" rule.
- Expiry handled lazily (read checks `expires_at`); a janitor command is
  only added if the store proves noisy.

### Web form

- `GET /ask/{id}` — minimal server-rendered page (Twig or a plain PHP
  template — **decision needed**, see open questions): question text,
  confirm buttons or a textarea, submit. No JS required.
- The id *is* the capability: 128 random bits, single-use, expires with
  the request. No login on the form itself — same trust model as an
  ntfy topic name, and the payload is the boss's own answer. (If the
  deployment is internet-exposed, `TOOL_TOKEN` from the ROADMAP auth
  item becomes the answer; the form route is listed first in its scope.)
- `POST /ask/{id}` stores the reply, flips status, shows "thanks, you
  can close this tab", and the blocked tool call unblocks within its
  poll interval.
- Single-use: once answered, `GET` shows "already answered", `POST` is
  rejected. Re-requesting a question mints a new id.

### Flow

```
LLM → request_input(question)
        │
        ├─ PendingRequest stored (id, TTL)
        ├─ OutboundAlert{actionUrl: /ask/{id}} → provider → boss's phone
        └─ handler blocks (poll store, ≤ timeout_seconds)
                                   │
boss opens /ask/{id}, types, submits ┘
        │
        ▼
  store.status = answered, reply saved
        │
        ▼
tool returns {status: "answered", answer: "..."} → harness → LLM
```

## Open questions

1. **Max block time.** Default 600 s is a guess. What do our actual
   harnesses (and any reverse proxies in front of REST callers) tolerate
   before giving up? Should `timeout_seconds` max be lower?
2. **Template engine for `/ask/{id}`.** SPEC.md v1 says "no Twig" — the
   form is the first server-rendered page. Twig (Flex recipe, one
   dependency) vs a single hand-rolled PHP template. Leaning Twig; the
   api-gateway archetype didn't anticipate *any* HTML, and it will now
   have exactly one page.
3. **Confirmation vs input — one tool or two?** Two tools (current
   plan) give the LLM clearer affordances and better schemas; one
   `request_input` with an optional `confirm` mode halves the YAML.
   Two, provisionally.
4. **REST blocking.** `POST /tools/request_input` blocking for ten
   minutes will trip most HTTP clients. Options: document-and-accept,
   cap `timeout_seconds` lower for REST, or a `wait: false` argument
   returning a status URL. Decide when Phase 2 lands; MCP is the
   primary consumer.
5. **Priority for interactive requests.** Floor at 4 (mention the boss)
   since the request needs a human act? Or default 3 and let the LLM
   raise it?
6. **Provider failure during Phase 2.** If the notification can't be
   delivered, the handler must fail fast (isError) rather than block
   for the full TTL on a request nobody will ever see. Agreed in
   principle; wording of the error to be settled in tests.
7. **Fan-out / multiple channels.** One provider per deployment for
   now; is "send to both ntfy and discord" wanted early enough to
   design the env shape now (`ALERT_PROVIDER=ntfy` scalar vs a list)?

## Sequencing

- **Phase 0 — `send_alert`**: `Priority` VO, `AlertProvider` interface,
  `NtfyProvider`, `DiscordProvider`, `AlertTool`, YAML, env vars, unit
  tests (MockHttpClient, PennyTrack-style) + integration test for the
  unconfigured-error path.
- **Phase 1 — plumbing for interactive**: pending-request store on
  cache, `/ask` routes + form, token single-use semantics, tested
  without any provider (direct HTTP against the form).
- **Phase 2 — `request_confirm`, `request_input`**: blocking handlers,
  action URL in `OutboundAlert`, provider rendering of the link,
  end-to-end test with a fake provider.
- **Phase 3 (contingent)** — provider-native interaction (Discord
  buttons/components via a bot instead of a webhook) *behind the same
  tool contract*, only if the web form proves insufficient.

Phase 1 is split out deliberately: the form/store mechanics are testable
and useful independently, and Phase 2 then reduces to a handler plus a
link render.

## Testing strategy

- Unit: providers against `MockHttpClient` (assert headers, priority
  mapping, `allowed_mentions` suppression — the @everyone guard is a
  security-ish property, pin it with a test); `Priority` mapping table;
  `PendingRequest` lifecycle.
- Integration: unconfigured-tool friendly error (mirror the
  `get_transactions` test); `/ask/{id}` GET/POST single-use flow via
  `WebTestCase`; full `request_input` round-trip with a scripted fake
  provider (no real network, no real blocking — fake answers
  immediately; a separate short-TTL test exercises expiry).