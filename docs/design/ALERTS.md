# ALERTS — context-shuttle

**Status:** Phase 0 (`send_alert`) and Phase 1 (interactive plumbing:
store, `/ask` form, `/inputs/{id}`) implemented · Phase 2
(`ask_user`, `ask_user_confirm`, `get_user_answer`) pending · design
v0.3, revised after three review rounds · supersedes nothing ·
sibling docs: `SPEC.md`, `DESIGN_CONSIDERATIONS.md`, `ROADMAP.md`

## Goal

Two tool families sharing one delivery abstraction:

1. **One-way alerts** (`send_alert`) — "hey boss, the thing is done",
   with optional link. Ships first.
2. **Interactive requests** (`ask_user`, `ask_user_confirm`,
   `get_user_answer`) — the LLM asks the human a question through the
   same provider, the human answers on a web form, and the answer is
   retrieved by follow-up:

   > "I want your input boss, do we change the CI for all the projects or
   > customize the CI for this one project?"

   → notification with a link → boss types a reply on a tiny web page →
   submit → the answer is fetched via `get_user_answer` (or the harness's own
   polling) and handed back to the LLM.

Providers at launch: **ntfy** and **Discord** (webhook). Either or both
can be enabled — a provider is on when its config env vars are present —
and every enabled provider receives every alert (see Provider
abstraction). The provider is behind an interface so more can follow
(email, Slack, Telegram…) without touching tool contracts.

## Decisions locked in review (v0.3)

- **Twig for the `/ask` form.** SPEC v1's "no Twig" non-goal was
  predicated on "no user interface"; the form is exactly one page, and
  Twig is the framework-blessed way to render it (Form component for
  the POST handling where it helps). SPEC.md's non-goals get a pointer
  here when Phase 1 lands.
- **`send_alert` carries a link from day one** (Phase 0), not deferred:
  the same `actionUrl` mechanism later renders the `/ask/{id}` link, so
  building it once in Phase 0 means Phase 2 adds nothing to the
  providers.
- **Interactive requests are non-blocking by default.** `ask_user*`
  returns an id as soon as at least one provider has accepted the
  message; `get_user_answer` takes the id and returns `pending` /
  `answered` / `expired`. Blocking is an **opt-in** (`wait: true`,
  bounded by `timeout_seconds`) and exists on the free-text tool
  (`ask_user`) only — `ask_user_confirm` never blocks.
- **Harness-side polling without LLM intervention.** A lightweight
  status endpoint lets a harness (task-loom) poll for answers itself,
  so its tool queue never blocks on a human and nothing depends on the
  LLM remembering to follow up — or doing so correctly. Non-task-loom
  clients use `get_user_answer` or `wait: true` (on `ask_user`) as
  suits them.
- **Deliberately blunt tool names.** Request tools are `ask_user`
  (free text) and `ask_user_confirm` (yes/no); the fetch tool is
  `get_user_answer`. The ask ↔ get-answer mirroring is chosen so even
  small models can tell which side of the coin they are on.
- **Link trust: show the domain, skip the allowlist (Phase 0).**
  `send_alert`'s `link` stays unrestricted in v1; instead both
  providers render a bounded, bolded `**domain**/path` line in the
  notification's visible text (rules in *Visible-link formatting*), so
  the boss can judge a tap before taking it. A domain/prefix allowlist
  was considered and set aside as brittle (legitimate links are
  arbitrary; prefixes are defeated by redirectors).
- **Multi-provider by config presence (no `ALERT_PROVIDER`).** ntfy is
  enabled iff `NTFY_TOPIC` is set; Discord iff `DISCORD_WEBHOOK_URL`
  is set. Zero providers configured = the existing clear "not
  configured" tool error; both configured = both receive every alert.
- **Delivery semantics: report, persist only after ≥ 1 delivery.**
  Mint the id first (it must be in the link), attempt every enabled
  provider, then persist and return only if at least one delivered.
  Partial success = success result carrying the per-provider report;
  all-fail (or none configured) = `isError`, nothing persisted, no id
  revealed. One provider failing never blocks the others.
- **Interactive requests go out at priority 4** (fixed in v1):
  important enough to act on; no `@`-mention — requests need a human
  act, but they are not emergencies.
- **No long-poll on the status endpoint in v1.** Short polling only;
  revisit if poll volume proves a problem.

## Non-goals

- No Discord bot / native interactive components (buttons, modals) in
  v1 — the web form is provider-agnostic and works everywhere. Native
  components are a possible later phase behind the *same* tool contract
  (see Phase 3).
- No inbound callbacks/webhooks in v1 — the shuttle would have to be
  reachable by the harness and per-request callback routing adds
  surface; polling covers the need. `callback_url` stays on the shelf
  as a future option, and the status endpoint itself stays short-poll
  (no long-poll hold in v1).
- No multi-recipient routing, templating, or digesting/scheduling.
  One deployment = one "boss"; "all enabled providers" is that same
  boss on several channels, not several recipients.
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
| `link` | string | – | http(s) URL rendered as the notification's click action (ntfy `Click`, Discord embed link); destination shown as a bounded `**domain**/path` line in the visible text (see Visible-link formatting) |
| `link_label` | string | – | display label for the link, default "Open" |

Returns a delivery report: per enabled provider, its name, its
message id (when the provider gives one), and success/failure. Result
is *not* `isError`-worthy unless **no** provider delivered (or none is
configured) — that distinction matters to the calling LLM.

Handler: `App\Tool\Alerts\AlertTool::sendAlert` as
`config/tools/send_alert.yaml`, same registration dance as
`get_transactions` (public service, clear "not configured" failure).

### `ask_user` (Phase 2)

The free-text side. Name reads on its own: "the LLM asks the user".

| Parameter | Type | Notes |
|---|---|---|
| `question` | string | shown verbatim on the form |
| `placeholder` | string | textarea hint, optional |
| `timeout_seconds` | integer | max wait, default 600, max 3600 |
| `wait` | boolean | default `false`; `true` blocks until answered/expired (the only tool with `wait` — see Interaction model) |

Always sent at priority 4 (see Priority model).

Non-blocking result: `{id, status: "pending", expires_at}`.
`wait: true` result: `{status: "answered", answer: "…"}` or
`{status: "expired"}`.

### `ask_user_confirm` (Phase 2)

The yes/no side — same family, `confirm` suffix, no free text.

| Parameter | Type | Notes |
|---|---|---|
| `question` | string | the yes/no question, shown verbatim |
| `confirm_label` / `dismiss_label` | string | button labels, defaults "Yes"/"No" |
| `timeout_seconds` | integer | as above |
| `wait` | – | **not offered** — a confirmation is quick by nature; the harness (or `get_user_answer`) handles the wait. Keeps one blocking tool, not two. |

Always sent at priority 4 (see Priority model).

Non-blocking result only: `{id, status: "pending", expires_at}`;
the answer arrives as `answer: true|false` via `get_user_answer`.

### `get_user_answer` (Phase 2)

The other side of the coin: reads as "get the user's answer", so the
ask ↔ get-answer pairing is inferable without documentation.

| Parameter | Type | Notes |
|---|---|---|
| `id` | string | the identifier returned by `ask_user` / `ask_user_confirm` |

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

### Fan-out: config presence is the switch

Providers are **collected, not selected**. Every configured provider
receives every alert; there is no `ALERT_PROVIDER` scalar to keep in
sync with reality. A provider is enabled iff its required config is
present:

| Provider | Required env | Optional env |
|---|---|---|
| `ntfy` | `NTFY_TOPIC` | `NTFY_URL` (default `https://ntfy.sh`), `NTFY_TOKEN` |
| `discord` | `DISCORD_WEBHOOK_URL` | `DISCORD_MENTION_USER_ID` |

Both set = both receive; one set = one receives; neither = the tool's
existing clear "not configured" error (same shape as
`get_transactions`' unconfigured path). A self-hosted ntfy server is a
first-class case: `NTFY_URL` + `NTFY_TOKEN` are optional companions to
the required topic, not alternatives to it.

### Delivery semantics: report, persist only after ≥ 1 delivery

Delivery is **best-effort across providers**: each attempt is isolated
(one provider failing — timeout, 4xx, whatever — never blocks the
others), and the tool returns a per-provider report:

- **≥ 1 delivered** → success result. The report lists every provider
  with its outcome; failures are noted in the report but are not
  `isError` — the alert reached the boss. For interactive requests,
  this is also the moment the PendingRequest is persisted and the id
  returned (see Mint → send → persist).
- **0 delivered, ≥ 1 configured** → `isError` with the per-provider
  failure report. Nothing was persisted; for interactive requests no
  id is revealed (nobody could ever answer it).
- **0 configured** → the clear "not configured" error, before any
  work happens.

This is the "report, don't reroute" posture: the LLM sees exactly
which channels worked so it can decide whether a human ever saw the
message — without the shuttle inventing retry policy. The class this
logic lives in (working name `AlertDispatcher`) is the seam where
Phase 2 hangs; provider implementations stay dumb HTTP pushes.

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
- **Interactive requests are pinned at level 4** in v1 — important
  enough to act on, not an emergency; the tools surface no `priority`
  parameter, and mention remains a level-5-only lever.
- Discord colour = decimal int for the embed `color`. Mention at 5 is a
  **user id**, not `@everyone` — webhook `allowed_mentions` pins the
  allowed user and suppresses `@everyone`/`@here` unconditionally, so an
  LLM cannot mass-ping a server no matter what it puts in the body.
- Level-5 email-push / phone-push escalation is a future knob
  (`ALERT_ESCALATE_5_TO_EMAIL` or similar); out of scope for v1.

## Visible-link formatting

Both providers render the same bounded, readable destination line
whenever an `actionUrl` is present. The click action is invisible
until tapped, so this line is the only pre-tap signal the boss gets.
One shared formatter, unit-tested once, rendered identically by every
provider:

- **Domain: rightmost 50 chars.** Long hosts keep the *right* end
  (registrable domain + TLD) and elide on the left, which drops excess
  subdomains first — the part a human would discard by eye anyway.
- **Path: leftmost 50 chars.** Paths keep their head (the meaningful
  prefix) and elide the tail.
- **Domain is bolded** (`**domain**`), path is not, so the two halves
  read as distinct at a glance. ntfy's markdown subset and Discord
  embed bodies both render the bold.
- An elided section gets a `…` at the cut edge so truncation is
  visible rather than silent.
- Query strings and fragments are omitted (path only) — they are noise
  for a human, often long, and sometimes carry signed tokens that
  should not be splashed on a lock screen.

Example renders (the line appended after the human body text; these
are actual formatter outputs, i.e. future test fixtures):

    → **example.com**/run/42
    → **example.com**/github/actions/runs/98765/jobs/123456789012345678…
    → **…k8s.nightly.eu-west-1.staging.internal.example.com**/reports/q3
    → **…k8s.nightly.eu-west-1.staging.internal.example.com**/reports/q3/artifacts/final-results-with-very-long…

The first case shows both halves intact; the second a long path with
intact domain; the third a long domain (subdomains elided, registrable
domain kept) with a short path; the fourth both elided at once.

The click target is untouched — shortening is display-only; the full
URL rides the provider's link field (ntfy `click`, Discord embed URL).
For interactive requests the same formatter runs on the `/ask/{id}`
URL; the domain will be the shuttle's own host, which is the desired
signal ("this is the shuttle's form, not some third party").

## Provider notes

### ntfy

- Config: `NTFY_TOPIC` (required to enable; public topics are fine),
  `NTFY_URL` (server, default `https://ntfy.sh` — set it for a
  self-hosted server), `NTFY_TOKEN` (optional, for access-controlled
  servers).
- Publish via `POST {NTFY_URL}/{topic}` with `Title`, `Priority`, `Tags`
  headers; JSON body publish when we need ntfy extras — `click` for the
  link (opens in the ntfy app's in-app browser on mobile).
- Link rendering: `click` carries the full URL; the **visible body**
  gets the shared `**domain**/path` line (see Visible-link formatting),
  appended after the human text, because the click action itself is
  invisible until tapped. Keep it in the message body, not the title —
  titles truncate first.
- Interactive (Phase 2): the `/ask/{id}` URL rides the same `click`
  field and gets the same visible-domain treatment (host will be the
  shuttle's own hostname — expected and fine). Nothing new to invent.

### Discord

- Config: `DISCORD_WEBHOOK_URL` (required to enable) + optional
  `DISCORD_MENTION_USER_ID`.
- `send_alert` maps to a webhook message with an embed (title, body,
  colour-by-priority, footer with tool name, link as the embed URL).
  Tags → embed fields or `[tag]` title prefixes; decide during
  implementation, keep it plain.
- Link rendering: the embed URL powers the click-through, and the
  embed body (or a field) gets the shared `**domain**/path` line (see
  Visible-link formatting), so the destination is readable before
  tapping.
- `allowed_mentions: {users: [...]}` as above. Suppress notifications
  below priority 5 entirely by not mentioning.
- Interactive (Phase 2): the `/ask/{id}` link is the embed URL, with
  the same visible-domain line — webhooks cannot create real buttons
  (that's Phase 3 territory).

## Interactive requests (Phase 2) — the interesting part

### Interaction model: non-blocking by default

Interactive requests are **always sent at priority 4** in v1: a human
act is needed, so it should look important, but the shuttle does not
let an LLM escalate a question to `urgent` + mention. A fixed level
also means the priority table's level-5 email/phone escalation stays
decoupled from questions.

The instinct is to make `ask_user` block until the human answers
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
| Non-blocking `ask_user*` → id → `get_user_answer` follow-up | ✅ **Default.** Works for any MCP client; the LLM (or the harness, on the client's behalf) fetches the answer. |
| Harness polls the status endpoint directly | ✅ **Primary for task-loom.** No LLM involvement, no blocked tool queue, no reliance on model memory. |
| Blocking with TTL | ✅ **Opt-in** (`wait: true` on `ask_user` **only**) for one-shot clients and scripts. One blocking tool, not two — a confirm blocks nothing by design. |
| MCP elicitation (server→client request) | ❌ A *client* capability we can't assume — and we want the boss on their phone, not whoever is at the MCP client. |
| Inbound callback/webhook on answer | 🕐 Deferred: needs inbound reachability and per-request routing; polling covers v1. Revisit if poll volume matters. |

### Flow (non-blocking, harness-polled)

```
LLM → ask_user(question)
        │
        ├─ mint id, build OutboundAlert{actionUrl: /ask/{id}}
        ├─ dispatch to every enabled provider (best-effort, isolated)
        │      ├─ ≥ 1 delivered → persist PendingRequest (id, TTL)
        │      │     └─ returns {id, status: "pending", expires_at}  ← immediately
        │      └─ 0 delivered → isError, nothing persisted, no id returned
        │
boss opens /ask/{id}, types, submits ┘  (id already delivered)
        │
        ▼
  store.status = answered, reply saved
        │
        ▼
task-loom polls GET /inputs/{id}  (or: LLM calls get_user_answer)
        │
        ▼
answer fed back into the conversation → LLM proceeds
```

With `wait: true` (on `ask_user` only) the handler does not return at
the `pending` step: after persisting, it blocks — polling the store,
≤ `timeout_seconds` — until the status changes, and returns the final
answer as the tool result. That is the open-webui shape, for clients
that want it.

### Mint → send → persist (ordering, and why)

The id must be **in the URL of the notification**, so it has to exist
before the send. But a request that was never delivered should not be
persisted (nobody will ever answer it; a store full of undeliverable
pending requests is noise). So the order is:

1. **Mint** the id (128-bit, URL-safe) in memory.
2. **Send** to every enabled provider with `actionUrl: /ask/{id}`.
3. **If ≥ 1 delivered:** persist the PendingRequest (status
   `pending`, TTL from `timeout_seconds`) and return the id. **If 0
   delivered:** persist nothing, return `isError` with the
   per-provider report.

The id is only *revealed* in step 3's response, so an all-fail call
never leaks a dead id. The same ordering applies to an interactive
`wait: true` call: even when the send fails, the tool must fail fast
rather than block on an id nobody will ever see.

One subtlety worth a test: two interactive requests racing on the
same persistence backend are independent (distinct ids), but a
`wait: true` handler's poll loop must not hold a lock that blocks the
form's `POST` write — polling reads, the form writes; cache adapters
that serialise writes (e.g. filesystem) are fine as long as the poll
keeps its reads short.

### Status endpoint (harness surface)

- `GET /inputs/{id}` — plain JSON `{status, answer?, question?,
  expires_at, answered_at?}`. Deliberately **outside the tool
  pipeline**: a harness polling every few seconds must not flood the
  `mcp_invocation` log (a 5-second cadence over a 10-minute TTL is 120
  log lines per request). Tool-grade interactions go through
  `get_user_answer`; machine-grade polling goes here.
- **Short polling only in v1** — no `?wait=30` long-poll hold. The
  endpoint is trivially cacheable/stateless; a long-poll hold would
  pin a PHP worker per waiting client, which is the exact cost this
  whole design avoids on the tool side. Revisit only if real poll
  volume proves it necessary (YAGNI for v1 per review).
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

1. **Fan-out env shape.** Settled: presence-based (no
   `ALERT_PROVIDER`), see Fan-out. Left open only whether a future
   `ALERT_DISABLE=discord`-style kill switch is worth having when
   someone wants config present but a channel muted — YAGNI until
   asked for.
2. **Visible-link cut details.** Format locked (rightmost-50 domain,
   leftmost-50 path, bold domain, `…` at elided edges); what remains
   is only the edge-case harvest at implementation time — e.g. a URL
   with no path, or a bare-registrable-domain host — all pinned by the
   formatter's own unit tests.

Resolved in review:

- Twig (yes) — the v0.1 open question.
- Link in Phase 0 (yes).
- Blocking vs non-blocking: non-blocking default; `wait: true` opt-in
  on `ask_user` **only** (v0.2).
- REST blocking: moot — non-blocking is the default REST shape too.
- One-vs-two interactive tools: two, plus the fetch tool (v0.1).
- Tool naming: `ask_user`, `ask_user_confirm`, `get_user_answer` —
  maximum legibility for small models (v0.2).
- Link trust: no allowlist in Phase 0; visible destination domain
  instead (v0.2); formatted as `**domain**/path` with the 50-char
  rules above (v0.3).
- Long-poll on status endpoint: no, v1 is short-poll only (v0.3).
- Priority floor for interactive requests: fixed at 4, no
  `@`-mention (v0.3).
- Provider failure on interactive send: report per provider; require
  ≥ 1 delivery to persist and return the id; all-fail = `isError`
  with nothing persisted (v0.3).
- Fan-out: presence-based multi-provider (both ntfy and Discord when
  both configured), not a scalar or a list env (v0.3).

## Sequencing

- **Phase 0 — `send_alert` with link**: `Priority` VO,
  `AlertProvider` interface, `OutboundAlert` (incl. `actionUrl`),
  `NtfyProvider`, `DiscordProvider`, presence-based provider
  collection + `AlertDispatcher` (best-effort fan-out, per-provider
  report), visible-link formatter, `AlertTool`, YAML, env vars
  (`NTFY_TOPIC`/`NTFY_URL`/`NTFY_TOKEN`,
  `DISCORD_WEBHOOK_URL`/`DISCORD_MENTION_USER_ID`), unit tests
  (MockHttpClient, PennyTrack-style) + integration tests for the
  unconfigured-error path and for partial/all-fail provider
  outcomes.
- **Phase 1 — plumbing for interactive**: pending-request store on
  cache, `/ask` routes + Twig form, `GET /inputs/{id}` status
  endpoint, single-use semantics, tested without any provider
  (direct HTTP against form and status endpoint).
- **Phase 2 — `ask_user`, `ask_user_confirm`, `get_user_answer`**:
  non-blocking handlers, `/ask/{id}` link through the existing
  `actionUrl` path, `wait: true` opt-in blocking on `ask_user` only,
  mint → send → persist ordering, fixed priority 4, end-to-end test
  with a fake provider (including a partial-delivery case).
- **Phase 3 (contingent)** — provider-native interaction (Discord
  buttons/components via a bot instead of a webhook) *behind the same
  tool contract*, and/or inbound callbacks, only if the web form and
  polling prove insufficient.

Phase 1 is split out deliberately: the form/store/status mechanics are
testable and useful independently, and Phase 2 then reduces to
handlers plus a link render.

## Testing strategy

- Unit: providers against `MockHttpClient` (assert headers, priority
  mapping, click/embed-URL link rendering, and `allowed_mentions`
  suppression — the @everyone guard is a security-ish property, pin it
  with a test); the visible-link formatter (the four render cases in
  Visible-link formatting become assertions; boundaries at exactly 50,
  51 chars); `Priority` mapping table (incl. interactive-floor = 4);
  `PendingRequest` lifecycle; `AlertDispatcher` (all-ok, one-fails,
  all-fail — the last two via providers that throw).
- Integration: unconfigured-tool friendly error (mirror the
  `get_transactions` test); multi-provider config detection (topic
  only, webhook only, both, neither); `/ask/{id}` GET/POST single-use
  flow via `WebTestCase`; `GET /inputs/{id}` status transitions
  (pending → answered, pending → expired by TTL); full `ask_user`
  round-trip with a scripted fake provider (no real network; fake
  answers immediately; a separate short-TTL test exercises expiry, a
  short-`timeout_seconds` test exercises `wait: true` both ways, and
  an all-providers-fail test asserts no id is returned and nothing is
  persisted).