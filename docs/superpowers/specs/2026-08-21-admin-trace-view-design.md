# Design: Admin trace view, stage timing and retention

- **Date:** 2026-08-21
- **Author:** Robin Schulte (with Claude)
- **Stakeholder:** Juan — Linear `IDEA-9`, initiative *Human-to-agent experiences in owned surfaces*
- **Supersedes:** `2026-08-18-shopping-assistant-design.md` Should-have #5 (see D17)
- **Status:** awaiting review

## 1. Context

A comparison of the implementation against the Linear project's seven required v0 qualities
found **observability the weakest merchant-facing area**, and one claim outright false:
`README.md` states *"The merchant sees every conversation in the Administration"* and
`ARCHITECTURE.md` draws it in the component diagram. **No Administration module exists.**
Acceptance criterion A6 hedges accordingly — "Admin trace view **or DB query**" — and today
it is only ever the DB query.

Two traps were found while scoping, both of which would have cost a day each mid-build:

**The generated admin-ui route does not exist for a plugin.** Should-have #5 says *"generated
`admin-ui` over the trace custom entities"*. The entire AdminUi XML machinery lives under
`Core/System/CustomEntity/Xml/Config/AdminUi/` and is applied by `CustomEntityEnrichmentService`
— it is a **CustomEntity** feature, and ruling R78 already established that custom entities are
registered exclusively by `AppManager`, i.e. App-only. D1 chose a plugin. **This is R78 one layer
up, unnoticed.**

**Corrected 2026-08-21 during implementation.** This section originally claimed that both
definitions declare no `ApiAware` field and that a generated view would therefore render only two
timestamp columns. **That was wrong**, and it was wrong because it repeated
`ConversationDefinition`'s own docblock without checking the framework.

`Field::__construct()` adds `new ApiAware(AdminApiSource::class)` to **every** field, and
`setFlags()` re-adds it if the flag list is cleared. Declaring no flag closes nothing. Both trace
entities were therefore **already** admin-API readable — `transcript` included, contrary to the
docblock that said a readable transcript would be "a data-protection problem rather than a feature".
Nothing was ever readable over `/store-api/`; the default is admin-scoped.

The work this spec calls "exposure" is therefore already done by the framework. What actually
needed doing was the inverse: **closing `transcript`** with an explicit `removeFlag(ApiAware::class)`.
See D18 as amended.

A third divergence surfaced and is folded in: **the retention task does not exist.** Three places
in the code refer to it as though it does, the migration's `ON DELETE CASCADE` and the
`created_at` index were both placed for it, and `ARCHITECTURE.md` calls it "Not optional".

## 2. Decisions

| # | Decision | Why |
|---|---|---|
| D17 | **Hand-written Administration module**, not generated `admin-ui` | The generated route is App-only (R78 one layer up). There is no cheaper path; pretending otherwise costs a day to rediscover |
| D18 | **`transcript` is closed with `removeFlag(ApiAware::class)`; everything else stays admin-readable by the framework default** | *Amended 2026-08-21.* Originally written as "add admin-scoped `ApiAware`", on the false premise that the fields were closed. Fields are open (admin-scoped) by default, so the only real change is stripping the flag from `transcript` — the verbatim, replayable record and the widget's re-hydration source. `ApiAware`'s constructor does build an *allow*-list despite the `$protectedSources` parameter name, so a bare `new ApiAware()` would additionally open `/store-api/`; that must never be written, and the boundary test asserts it |
| D19 | **`elapsed_ms` (offset from turn start), never `duration_ms`** | `record()` is an *entry* marker at some call sites (`BoundedToolbox::execute`) and a *completion* marker at others (`SearchProductsTool`). A gap-to-next duration would mean a different thing per stage. R62's precondition is fixed; the ruling itself stands |
| D20 | **Retention ships in this slice**, global window, pruned by `created_at` | This slice turns an invisible prototype log into a prominent, browsable store of shopper utterances. The schema already anticipates the task; shipping the view without it is the wrong order |
| D21 | **No Vue test infrastructure is added** | `package.json` is explicitly dev-only and outside CI. Adding Jest/Vitest is a separate decision, not something to smuggle into this spec. The coverage hole is named in §6 rather than papered over |

## 3. Scope

### Must have

1. Admin-scoped `ApiAware` on the field set in §5.1, `transcript` excluded
2. ACL privileges, read-only, registered from the module
3. `elapsed_ms` recorded per trace event, from an injectable monotonic clock
4. Administration module: conversation listing + trace detail timeline
5. The four never-collapsed payload fields rendered inline
6. Daily retention `ScheduledTask` with a `traceRetentionDays` config field
7. Documentation corrected: ARCHITECTURE.md schema, the admin-ui claim, README

### Should have

8. `outcome` as a filterable listing column — it makes the view a triage tool for
   `tool_limit_exceeded` and `escalate`, the two failures the handoff calls live

### Explicitly cut from this slice

No-result-intent aggregation · conversion-adjacent event linking · incremental trace writes
(and therefore live progress in the widget) · the analytics-sink extension point / Langfuse ·
per-sales-channel retention windows · any write or delete path from the Administration ·
Vue unit tests

## 4. Acceptance criteria

| # | Criterion | How it is verified |
|---|---|---|
| A8 | A merchant with the ACL opens a conversation in the Administration and sees every pipeline stage of every turn, in order, with its elapsed offset | Manual run against a real shop |
| A9 | `transcript` carries no `ApiAware`, and no **declared** field on either entity is readable from `SalesChannelApiSource` | Definition-level unit test — this test *is* the boundary guarantee. It additionally pins the framework default (fields are admin-readable unless stripped), so that if a future Shopware release closes fields by default the test fails and says so rather than leaving `removeFlag` silently redundant |
| A10 | `filtersDropped`, `inventedProductIds`, `modelClaimsDiscarded`, `stockSource` render inline and are never collapsed | Manual run; the four are the ways this product class lies |
| A11 | `elapsed_ms` is monotonic within a turn and resets at turn start | Unit test with an injected clock; multi-turn probe run |
| A12 | A conversation older than the window is deleted, and its trace events go with it | Handler unit test with injected clock + cascade assertion |

## 5. Design

### 5.1 API surface

**One line of production change:** `transcript` gains `->removeFlag(ApiAware::class)`.

Every other field on both definitions is already `ApiAware(AdminApiSource::class)` — the framework
default from `Field::__construct()`. No flag is added anywhere by this slice.

`createdAt`/`updatedAt` are likewise default-exposed and carry no conversation content.

**What closing `transcript` does and does not buy.** It does *not* hide shopper content: the
`understand` stage's payload carries the shopper's message, and a merchant with the ACL can
reconstruct most of a conversation from payloads. That is the feature — "the merchant sees what
the assistant understood" cannot be delivered while hiding what the shopper said. What it buys is
narrower and real: the verbatim, complete, replayable record stays off the API; the widget's
re-hydration path stays server-only; and exposure is stage-shaped, so a future redaction policy
has per-stage granularity to work with.

Removing the flag does not affect the widget. `read_protected` is enforced in the API layer, and
`DalConversationStore::history()` reads through the DAL directly.

**Note for whoever assesses this:** `transcript` was readable over `/api/` from the moment the
entity shipped until 2026-08-21, while the code asserted it was not. Admin-API only, ACL-gated,
never shopper-reachable — but the code's claim and its behaviour disagreed.

`createdAt` and `updatedAt` stay as they are on both entities — `EntityDefinition::defaultFields()`
appends them `ApiAware` and they cannot be suppressed. They carry no conversation content, and A9
is scoped to declared fields for that reason.

ACL: `swag_assistant_conversation:read`, `swag_assistant_trace_event:read`. Read-only throughout.

### 5.2 Timing

`TraceRecorder` takes an injectable monotonic clock — a `\Closure(): int` over `hrtime(true)`,
defaulted so no call site changes. Each `record()` stamps milliseconds since **turn start**.

Elapsed is relative to the turn, not the conversation: a conversation spans turns and `seq` keeps
growing across them, so conversation-relative offsets are meaningless by turn three. **The
implementation must confirm the recorder's lifecycle is per-turn** — the probe command runs
several turns in one process, which is where this breaks silently.

`TraceEvent` gains `elapsedMs`; `TraceEventEntity` gains the property and its R62 docblock is
rewritten to record that the precondition was fixed, not the ruling overturned;
`TraceEventDefinition` gains `IntField('elapsed_ms', 'elapsedMs')`. A **new** migration adds the
column — the tables exist in installed shops. `DalConversationStore` persists it.

### 5.3 Administration module

`shopware/administration: ~6.7.0` joins `require`. `composer run build:storefront` already shells
`shopware-cli extension build .`, which builds administration too; the script is renamed
`build` since the name `build:storefront` becomes untrue.

**Listing** — `sw-entity-listing` over conversations: created, sales channel, turns, outcome,
total ms.

**Detail** — conversation header, then the trace as a timeline keyed on `elapsedMs`, one row per
event (`+2,400ms · retrieve · 7 hits`), payload expandable per row except the four fields above.

Admin snippets (en-GB, de-DE) bundle inside the module — a different mechanism from the
storefront's `Resources/snippet/*.json`, which is untouched.

`scripts/check_file_length.php` walks `src`, so admin JS falls under the file-length gate; the
detail page will need splitting. `composer-dependency-analyser` must see the new dependency used.

### 5.4 Retention

`traceRetentionDays` in `config.xml` under a new *Data retention* card, default **30**. There is no
"keep forever" value: an empty field falls back to the default rather than disabling the task, so a
merchant who never opens the card still gets pruning.

A daily `ScheduledTask` + handler, tagged `shopware.scheduled.task`, deletes conversations whose
`created_at` predates the window. `ON DELETE CASCADE` removes their trace events. Deletion runs in
bounded batches — the implementation picks the size; the binding constraint is that one run must
never hold a long transaction on a shop serving shoppers.
Pruning keys on `created_at` because the migration's index on it exists for no other purpose.

The window is a **global** system-config value. A scheduled task has no sales-channel context and
would otherwise iterate every channel to prune one table.

## 6. Testing, and the hole in it

Fully covered, in the existing PHPUnit suite:

- the §5.1 boundary — that `transcript` is closed and nothing is store-API readable (A9)
- clock-injected timing: exact offsets, monotonic within a turn, reset across turns (A11)
- `DalConversationStore` round-trip for `elapsed_ms`; the new migration
- the retention handler with an injected clock and cascade assertion (A12)

**Not covered: the Vue module.** There is no Vue test infrastructure and D21 does not add one.
`package.json` is explicitly *"Dev-only… not part of CI"*. The module gets one Playwright path at
best, and realistically manual verification against a real shop. This is a genuine gap and is
recorded here rather than left implied by "the slice is tested".

## 7. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| The `removeFlag` call on `transcript` is deleted by someone who assumes an unflagged field is a closed field | **high** | Exactly the belief that left it open in the first place. A9's test fails loudly; the docblock and the ruling both state that fields are open by default |
| Admin module cost is underestimated — `shopware/administration` is a new dependency with its own build | medium | D21 keeps the module thin: two read-only pages, no write path |
| The recorder's lifecycle is not per-turn and `elapsed_ms` drifts across a multi-turn conversation | medium | Called out in §5.2; A11 covers it with a multi-turn probe run |
| First retention run on an existing shop deletes a large batch | low | Batched deletion; the prototype has no large installs |

## 8. Documentation and rulings to update

- `ARCHITECTURE.md`: `duration_ms` → `elapsed_ms` in the trace-event schema; the four
  never-collapsed fields corrected to their real camelCase names; "generated admin-ui" replaced,
  **with the R78-one-layer-up finding recorded** so nobody attempts the generated route again
- `README.md`: the Administration claim becomes true on merge
- `DalConversationStore` and `Migration1755720000`: their retention-task references become true
- New rulings for D18 (the `ApiAware` widening) and D19 (R62's precondition)

## 9. Follow-ups

In rough priority: no-result-intent and conversion-adjacent aggregation on top of the module ·
incremental trace writes (unlocks live progress during the 16–19s wait) · the analytics-sink
extension point, with Langfuse as its first consumer (D15) · shopper-facing "situational
awareness" slice — cart read, out-of-stock alternatives, page context.
