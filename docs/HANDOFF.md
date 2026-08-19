# Handoff — 2026-08-19: from grounded core to Shopware plugin

## Where this stands

The **grounded core and the eval harness are built and measured against a real LLM.**
The **Shopware plugin does not exist.** `composer.json` says `"type": "library"`; there is no
plugin base class, no `services.xml`, no `config.xml`, no controller, no widget, no admin view,
no trace persistence, and no `DalCommerceGateway` — so nothing has ever run inside Shopware and
nothing has ever seen a real product.

188 tests / 503 assertions green, `composer run quality` exit 0, 70 commits on
`feat/grounded-core`.

## The decision this handoff exists to carry

Against the spec's Must-haves:

| # | Must-have | Needs Shopware | State |
|---|---|---|---|
| 1 | Chat widget in a real 6.7 storefront | yes | **nothing** |
| 2 | Answer from real catalogue data, variant-level stock | yes | **nothing** |
| 3 | "add that to my cart" lands in the real cart | yes | **nothing** |
| 4 | Terminal eval run, 6 journeys green | no | measured, 4 failing on retrieval quality |

A full day went into #4 — the only Must-have that does not need a shop — while the three that do
sat at zero. **Stop fixing eval residuals. Build the plugin.**

The switch signal had already arrived: live runs 1–3 each found a genuine pilot blocker (turn-killing
crashes), run 4 found only retrieval-quality issues. Marginal value dropped; the loop should have
ended there.

Remaining eval failures are **not** blockers. Zero crashes as of run 4, and the assertions that
carry the product claim — `no_invented_product` and `no_unbacked_price_in_prose` — pass **3/3 on
every journey and every archetype**. What fails is a weak model's retrieval on vague phrasing
against 12 invented products. Tuning that against fixtures optimises the decoy; re-measure after
`DalCommerceGateway` lands, against real product names.

## Order of work

1. **Plugin skeleton** — `composer.json` → `shopware-platform-plugin`, plugin base class,
   `src/Resources/config/services.xml`, `config.xml`. Small and mechanical. This is the overdue
   Wed 19 item whose stated outcome is *"plugin installable"*, and everything else waits on it.
2. **`DalCommerceGateway`** — the Thu 20 item, previously deferred. Now the critical path: it is
   where the real unknowns live (Store API vs DAL, sales-channel context, variant and property
   mapping) and it is what turns a library into a Shopware feature. `CommerceGatewayInterface` is
   the only seam it must satisfy; `FixtureCommerceGateway` is the reference implementation.
3. **Trace entities + migration** — acceptance criterion A6 ("every turn produces a persisted
   trace") is unmet; `TraceRecorder` is in-memory only. Prerequisite for the admin view.
4. **Storefront widget + controller** — the thing a shopper touches. No streaming (spec).
5. **Admin trace view** — "Should", not "Must". Drop this first if time runs short; the spec's own
   open question Q2 doubts it belongs.

## Read these first

| File | Why |
|---|---|
| `docs/superpowers/specs/2026-08-18-shopping-assistant-design.md` | The authority. Decisions D1–D16, acceptance criteria A1–A7, day plan, Must/Should/Cut |
| `ARCHITECTURE.md` | Architecture of record, including two dated corrections a live run forced |
| `.superpowers/sdd/2026-08-18-grounded-core/standing-constraints.md` | Binding constraints — version pins, quality gate, pragma carve-outs |
| `.superpowers/sdd/2026-08-18-grounded-core/progress.md` | 55 rulings, each with its reasoning and what it costs if wrong |
| `docs/adr/0001-symfony-ai-as-agent-runtime.md` | Why Symfony AI, and what 0.12 cost us in practice |

## Known issues — a list, not a work queue

Every one of these is measured, not suspected. Do not re-derive them.

1. `injection_discount · beginner` 0/3 — "metal bottle holder" against a catalogue saying "Alloy
   Bottle Cage". Tier-0 text search cannot bridge it; **the catalogue-vocabulary block did not fix
   it** (the block reaches the model — proven deterministically — the model does not use it).
2. `variant_stock · beginner` 0/3 — "a medium" against option value `M`. Same cause, same
   non-result from the vocabulary block.
3. `variant_stock · expert` 2/3 — one run rendered `fx-026-black-m` for a blue-M question.
4. `cart_add` 0/3 both archetypes — the model shows the product and never calls `add_to_cart`;
   2 of 6 runs exhausted the tool-call budget. Previously this crashed the suite; it now degrades
   and the assertion names the real problem.
5. `price_constraint · beginner` went 2/3 → 0/3 between runs 3 and 4. Possibly a vocabulary-block
   regression, possibly run-to-run variance. **One data point — do not treat either reading as
   established.**
6. Ranking runs at pipeline stage 6, not stage 9 as originally documented: the gateway applies sort
   **and limit** during retrieval, so an out-of-stock unit can be truncated away before variant
   resolution sees it. `SearchProductsTool::MIN_LIMIT` is a **mitigation**. The real repair is
   applying the limit after variant resolution, which needs the gateway seam to distinguish "how
   many to retrieve" from "how many to return".
7. The prose audit covers **currency figures only**. A model that looks up a product and then
   describes option values it never verified is not caught. Unbuilt, not solved.
8. **No trace has ever been read end to end.** Every claim about model behaviour in this handoff is
   inferred from assertion text. `TraceRecorder` collects everything and nobody reads it — fold a
   trace dump into item 3, where you will be reading traces anyway.

## Three lessons that cost real time today

- **Read the whole vendor method, not the part that confirms the hypothesis.** Two separate
  misdiagnoses came from partial reads of `symfony/ai-agent`: once against unreleased trunk, once
  by stopping three lines above the `catch` block that mattered. No Symfony AI API claim is valid
  unless read from the installed `vendor/`.
- **"A foreseeable condition aborts the turn instead of degrading" happened three times**
  (ledger R48, R49, R52) — a serializer failure, a `TypeError`, and the tool-call cap. Each was
  found by a live run, none by the deterministic suite. Check any new turn-ending condition
  against that list.
- **A green suite proves the tree it ran on.** 162 tests passed while the assistant rendered
  nothing, because every scripted transcript had the model quoting product ids — something no real
  model does. Two safety assertions passed *vacuously*. Prefer a test that fails when the control
  is removed; mutation-check the ones that matter.
