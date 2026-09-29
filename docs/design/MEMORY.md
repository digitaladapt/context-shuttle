# MEMORY — the memory-draft tool family

**Status:** shipped · `MEMORY_DRAFT_URL` gates the whole family

## What this is

Five tools — `memory_recall`, `memory_remember`, `memory_keys`,
`memory_stats`, `memory_forget` — backed by a
[memory-draft](https://github.com/digitaladapt/memory-draft) instance.

memory-draft is a keyword-addressed memory store: sentences per key, with
revisions for concurrency, an explicit miss with near-miss suggestions, and a
two-tier (hot/cold) age model. No embeddings, no vector store. The premise,
from the project's own README:

> The model repeatedly needs to answer one question — *"what do I know about
> X?"* — and the cheapest useful answer is a sentence or two per keyword,
> retrieved by name.

That is the gap this family fills here. Every other tool in context-shuttle
reads a *service* (money, health, mail, calendar). This one is the only thing
that stores what the deployment **learned**, across sessions and across
models.

## Why a native tool rather than registering memory-draft's MCP server

`ROADMAP.md`'s standing rule is "existing MCP server first". memory-draft does
not ship one — its HTTP API is the surface — so the question does not arise.
Where it *would* be tempting to build one upstream, the answer is the same as
ever: this project's guarantees (one namespace, one invocation log, the shared
REST + OpenAPI surface) are the reason the mirror exists.

## The gate: `MEMORY_DRAFT_URL` is the whole permission model

**With the variable empty, none of the five tools is registered at all.**
They are absent from `tools/list`, so a model cannot recall from, write to, or
forget a store the operator did not name.

This is a stronger statement than a refusal at call time, and it is the one an
operator can verify by looking. It matters most for `memory_forget` — the only
irreversible verb in the family — but it applies to the reads too, and that is
a **deliberate divergence from the pattern used elsewhere**:

| Family | Unconfigured behaviour | Why |
|---|---|---|
| penny-track, vital-pulse, calendar reads | Listed; invoking says which variable to set | The tool reads a service whose *address* is incidental to the question. "List my transactions" has an answer beyond "configure me". |
| calendar **writes** | Absent | Opt-in, because a mutation is not recoverable. |
| **memory (all five)** | **Absent** | The URL **is** the resource. A recall from a store nobody named is not a configuration problem to report — it is a memory this deployment does not have. A tool that always answers "not configured" would be furniture. |

The last row is the one worth re-reading before "fixing" the inconsistency:
the difference is not read-versus-write, it is whether the missing
configuration leaves a question the tool could have answered.

There is no finer gate to build. memory-draft has **no authentication and no
per-operation permissions** — anyone who can reach an instance can read and
write it. A per-tool toggle here would be a boundary that does not exist,
which is worse than an honest one-variable opt-in, because it would invite an
operator to believe the restrictive setting meant something.

## What is passed through, and what is decided here

The tool is a transport, not a second implementation. These four behaviours
belong to memory-draft and arrive untouched, because re-deciding them would
create two answers that can disagree — and the caller could not tell which one
answered:

- **A miss is a 200 with `suggestions`**, never an error. The suggestions are
  the point of the service; a tool that turned "I do not know X" into a
  failure would throw them away, and the caller would conclude the same thing
  it wrongly concludes from a guessed key.
- **Spelling variants resolve in the same round trip** and report
  `resolved_from`. `ContextShuttle`, `context-shuttle`, `Context Shuttle` and
  `CONTEXTSHUTTLE` are one key. This is why the tool sends the caller's
  spelling rather than canonicalizing it: memory-draft *records* the variant
  as an alias from exactly what was sent, so normalizing here would discard
  the alias the store is designed to learn.
- **Trimming demotes, and `replace` retires.** Above the hot cap, the oldest
  sentences move to cold storage — retrievable with `include_cold` — and
  `mode: replace` moves the current sentences to that same tier rather than
  deleting them. The store's only deletion is past the cold cap, and it is
  reported on the write that caused it.
- **A stale write is kept and flagged `backfill`**, ranked below current
  knowledge, rather than rejected. The rationale in memory-draft is that the
  caller is a model reasoning from what it read, and dropping its write
  discards something the newer writer may never have known.

Two things the tool *does* decide, because they are transport concerns rather
than store policy:

1. **A write with nothing to store, and a delete with no key, are refused
   before the request.** Upstream, both are 200s that read like success:
   `remember` with blank sentences answers `empty: true` (visible in the
   detail, missable in the shape), and `forget` of an absent key is a 404
   whose body is the real answer. Refusing locally means "stored" always
   means stored.
2. **A 404 on an API path names the variable.** A URL that answers but not
   with this API is a configuration mistake, not an empty result. The one
   exception is `forget`, where "nothing matched" is a legitimate answer and
   is rendered as one.

And one rendering rule, because tool results are what a context window is
made of: an upstream error body is summarized to a bounded, single-line
string with markup stripped, so a reverse proxy's HTML error page cannot
arrive as tool output. This mirrors `ToolFailureTranslatingReferenceHandler`'s
reason for existing — the operator's one useful clue must survive — without
letting that clue become unbounded.

## Requirements as tokens, not booleans

This family needed a second visibility gate, and the existing shape could not
express one: `ToolDefinition::$requiresWrites` was a boolean that
`CalendarWriteGate` owned outright.

It is now `requires: <token>` (`calendar_writes`, `memory_store`), and gates
are composed by `ChainedToolAvailability`:

- A gate answers **only for the requirement it owns** and allows everything
  else, which is what makes the composition an AND with no gate knowing about
  the others.
- A requirement **no gate claims is allowed**. The failure mode of a forgotten
  gate is therefore a listed tool that refuses — today's behaviour for every
  unconfigured tool — rather than a tool that vanishes with nothing able to
  say why. That direction is chosen deliberately: visibility should only ever
  be narrowed by something actively claiming to narrow it.

`ToolAvailability`'s original docblock anticipated this ("It is an interface
with one implementation … What 'available' means for a given requirement is a
question for whoever owns that requirement"), so this is the second
implementation arriving, not a redesign.

## Not in scope

- **A memory-draft MCP server.** Deferred until the HTTP API stops being
  enough — and if it ever ships, it should live in memory-draft, not here.
- **Embeddings / semantic recall.** The service's whole argument is that
  retrieval by name is the cheap useful answer. Adding vectors here would be
  building a different product in the wrong repository.
- **Per-tool permissions within the family.** Nothing upstream to enforce
  them against (see above).
- **Bulk export/import.** `memory_keys` + `memory_recall` covers reading a
  store out; a migration tool is a different problem with a different blast
  radius.

## Verification

- `tests/Unit/Tool/MemoryDraft/MemoryDraftToolTest.php` — 42 tests over a
  recorded HTTP client: every operation's request shape and response
  pass-through, plus each local refusal and upstream failure path.
- `tests/Unit/Tool/MemoryDraft/MemoryDraftGateTest.php` — the gate and the
  chain, including the unclaimed-requirement case.
- `tests/Integration/MemoryDraftGatingTest.php` — the whole kernel: 16 tools
  without a store, 21 with, and the REST/OpenAPI surfaces agreeing with the
  registry.
- `tests/Integration/MemoryDraftLiveTest.php` — against a real instance,
  `--group live`, skipped unless `MEMORY_DRAFT_LIVE_URL` is set. This is the
  counterweight to the recorded tests: it caught that the store reports
  `intent: backfill` (not `backfilled`, which is the *sentence* flag) during
  development.
