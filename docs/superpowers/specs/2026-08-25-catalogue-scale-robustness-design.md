# Catalogue-Scale Robustness — Design

**Date:** 2026-08-25
**Status:** approved in conversation (A+B, with A gating B); implementation plan for phase A to follow

## Purpose

Every number in this kit is calibrated against a catalogue that does not exist in any shop.

Measured, 2026-08-25:

| Catalogue | Sellable units | Property groups | Option values | Largest family |
|---|---|---|---|---|
| `tests/Fixtures/catalog.json` — what every eval runs against | **17** | 4 | 9 | 4 variants |
| `docs/demo-catalog` — the deployed staging shop | 47 | 5 | 19 | 4 variants |
| Shopware's own `framework:demodata` | — | 8 | 51 | — |
| A real shop | 10⁴+ | 10²  | 10³ | 10¹–10² |

Against those 17 units this kit sets `MAX_VALUES_PER_FIELD = 25`, `MAX_FIELDS = 30`,
`MAX_TOTAL_CHARS = 1500`, `MIN_CANDIDATES = 20`, `MAX_CANDIDATES = 50`, `FACET_VALUE_LIMIT = 50`,
`MAX_VARIANTS = 100` and `MAX_LIMIT = 8`. **Not one of them has been observed above 17 units.** Two
are already crossed by Shopware's own demo data: the vocabulary block reports `truncated: true` at
8 fields / 51 values.

That is the risk this work exists to size. A merchant adopting a starter kit inherits its constants,
and a constant nobody has measured is a guess with a comment attached.

Nothing here is a suspected bug report. It is a measurement, and the point of running it is that the
answers are not currently knowable.

## Decisions

| # | Decision | Why |
|---|---|---|
| S1 | **Two phases with a gate between them.** Phase A tests scale *above* the commerce gateway with a large fixture. Phase B measures scale *below* it against a seeded shop. A completes and reports before B is planned | They have different costs and different kinds of answer. A is deterministic, needs no database, and belongs in the suite. B produces numbers that cannot be asserted — a latency is not a pass or a fail. Running B first would measure code A is about to change |
| S2 | **The large fixture is generated at run time from a fixed seed; only the generator is committed.** Output goes to `var/`, which is already gitignored | A committed 400 KB JSON makes every regeneration an unreviewable diff, and the generator is the reviewable artefact. Determinism comes from the seed, not from the file — and a deterministic generator is testable, which a blob is not. **Accepted cost:** a run depends on the generator being correct, so the generator carries its own unit tests asserting counts and the id of every engineered trap |
| S3 | **The large fixture is a strict superset of the small one.** All 12 existing products keep their exact ids, names, prices, stock, options and category paths; ~2,000 generated units are added around them | This is the linchpin. It means **all 15 existing journeys run against it unchanged**, so any red is caused by scale rather than by a rewritten expectation. Without it, measuring scale would mean rewriting 15 journeys first, and a rewritten journey proves nothing about a regression |
| S4 | **Scale targets: ~2,000 sellable units, 60 property groups, 400 option values, largest family 30 variants** | Chosen to *cross every constant*, not to be realistic. 60 groups passes `MAX_FIELDS = 30`; 400 values passes `FACET_VALUE_LIMIT = 50`; a 30-variant family passes `MIN_CANDIDATES = 20`, breaking the "a family must fit inside the candidate window whole" invariant that `SearchProductsTool`'s own docblock calls load-bearing. Crossing a bound at 2,000 units is the same failure as at 10,000 and runs in a fraction of the time — the fixture gateway is in-memory, so this costs milliseconds |
| S5 | **Four engineered traps, each named and each aimed at one constant** — see *Traps* below | The small fixture's eleven traps are why this project finds its own bugs. The same discipline at scale: a generated catalogue of uniform products would cross the bounds without ever producing a wrong answer, which measures nothing |
| S6 | **Phase A is opt-in via `ASSISTANT_EVAL_CATALOG=large`, default `small`** | The eval suite already costs ~10 minutes and real money for 15 journeys. Doubling that on every run buys nothing on the days nobody changed retrieval. Opt-in keeps the measurement available and the default cheap |
| S7 | **Phase A does not tune any constant.** It measures, adds four assertions, and reports | Re-tuning needs Phase B's numbers — a bound that is correct for a 2,000-unit in-memory fixture says nothing about a 10,000-product database. Changing constants here would be guessing twice and calling the second guess a fix |
| S8 | **Phase B never runs against the staging shop.** `framework:demodata` seeds thousands of products into whatever shop it is pointed at | The staging shop's `fx-*` catalogue is the demo, and its eleven engineered traps are the reason the storefront work is testable. Burying it under generated demo data would cost more than the measurement is worth |

## Traps

Four, each aimed at exactly one constant, each with an id a failing assertion can name.

| Id | Shape | The constant it crosses | What a wrong answer looks like |
|---|---|---|---|
| `sc-family-30` | One product, 30 variants, the **sold-out** one ranked last by insertion order | `MIN_CANDIDATES = 20` — the family no longer fits the retrieval window whole | A shopper asking for the sold-out combination is shown an in-stock sibling with a real price. This is the exact failure `SearchProductsTool`'s limit docblock records as already having happened once at `limit: 1` |
| `sc-rare-option` | An option value that is the 60th of its group, on one product | `FACET_VALUE_LIMIT = 50` — `FacetProbe` never sees it, so `QueryBuilder` cannot resolve it | The option is dropped or falls back to the model's own spelling, and the shopper gets the family instead of their variant — the same shape as the `gloves` defect, one layer down |
| `sc-deep-duplicate` | A near-duplicate of a common product name, placed late in insertion order | `MAX_CANDIDATES = 50` and narrowing to `MAX_LIMIT = 8` | Two products a shopper must be able to tell apart, and only one of them reachable |
| `sc-broad-term` | A word shared by ~500 products | `MAX_LIMIT = 8` and the shortlist premise | Eight arbitrary products presented as an answer, with nothing saying the other 492 exist |

## Architecture

```
                    ┌─────────────────────────────────────────┐
   Phase A          │  LargeCatalogGenerator  (seeded, pure)   │
   above the        │      12 real products, verbatim          │
   gateway          │    + ~2,000 generated units              │
                    │    + 4 named traps                       │
                    └────────────────┬────────────────────────┘
                                     │ writes var/catalog-large.json
                                     ▼
                    FixtureCommerceGateway ── JourneyRunner ── 15 existing
                                                    │           + 4 scale
                                                    ▼           journeys
                                            measurement report
                    ─────────────────── gate ───────────────────
   Phase B          swag:assistant:benchmark  ──▶  numbers, not assertions
   below the                against a shop seeded with framework:demodata
   gateway
```

Phase A adds one class and one env switch. It changes no production code — which is the property
that makes its result trustworthy as a baseline.

## What phase A cannot test

Stated rather than glossed, because a measurement whose limits are unclear gets over-read.

`FixtureCommerceGateway` uses `FixtureTermMatcher`: substring matching with an all-tokens pass and an
any-token fallback. It is **not** Shopware's search. It has no keyword index, no relevance ranking,
no `slop()` prefix generation, and therefore none of the plural blind spot that produced the `gloves`
defect. So phase A can say whether the layers *above* retrieval survive scale — the vocabulary block,
the candidate window, variant resolution, narrowing, and how the model behaves when the vocabulary it
is handed is a truncated sample. It can say **nothing** about retrieval quality at scale.

One consequence worth naming in advance: `FixtureTermMatcher`'s any-token fallback matches far more
at 2,000 units than at 17. If a journey goes red at scale, "the fixture matcher got broader" is a
candidate cause that has to be ruled out before the product is blamed.

## Phase B, in outline

Planned separately, after A reports.

A read-only `swag:assistant:benchmark` console command against a shop seeded by
`framework:demodata`, reporting per turn: facet-probe milliseconds, search milliseconds at p50 and
p95, the vocabulary block's field count / value count / truncation flag, `retrieve` hit counts, and
the prompt's character count. Numbers in a table, re-runnable, quotable in the README. No assertions:
a latency is not a pass.

### Handoff for whoever plans B

**Correction to an earlier reading of this section.** The first version of this spec said B's plan had
to wait for A's thresholds. That was half wrong: B *reports* numbers and asserts none, so it needs no
thresholds to be built. Thresholds are needed by the constant-tuning decision that comes **after** B,
not by B. What genuinely blocks a B plan is the environment question below, plus the softer point that
A's findings decide which columns are worth the most depth.

So B is plannable from this spec alone, given one decision. The rest is written down here so it is not
re-derived.

**The one decision: where the large shop lives.** `framework:demodata` seeds into whatever shop it is
pointed at, and it **adds rather than replaces** — nothing existing is deleted.

| Option | Cost |
|---|---|
| Seed `shopping-assistant-test` in place | Cheapest. The seeded Trail Jersey survives, so `tests/e2e` still passes — but its search ranking and the vocabulary block do not, and the local shop stops being the small-catalogue reference the storefront work uses. Reversible only by restoring the database |
| **Snapshot, seed, measure, restore** | One extra step each way (`mysqldump` in the `database` container). Keeps the e2e shop intact and makes the measurement repeatable. **Recommended** |
| A third docker stack | Cleanest isolation, most disk and setup for a measurement that runs occasionally |

Never the staging shop — decision S8, and demodata adding rather than replacing is exactly why: the
`fx-*` catalogue would survive but be buried under generated products, which costs the eleven
engineered traps their usefulness without deleting a thing.

**The seed command**, with `--reset-defaults` so it does not also generate thousands of orders,
customers and reviews nobody is measuring:

```
php bin/console framework:demodata --reset-defaults --products=10000 --properties=100 --categories=50
```

`--properties` creates each group with `rand(30-300)` options, so 100 groups is comfortably past
`MAX_FIELDS` (30) and `FACET_VALUE_LIMIT` (50) — the same bounds phase A crosses in the fixture, now
below the gateway where the aggregation actually runs.

**Where each number comes from**, so the command is a wiring job rather than a design one:

| Number | Source |
|---|---|
| facet-probe ms | wrap `FacetProbe::probe()`; the `facet.probe` trace event already marks `live` vs `cache` |
| search ms, p50/p95 | wrap `CommerceGatewayInterface::search()` across N repetitions of a query list |
| vocabulary fields / values / truncated | `CatalogVocabulary::renderWithStats()` returns exactly `array{text, fieldCount, valueCount, truncated}` |
| prompt chars | `strlen(SystemPrompt::build($config, $vocabulary))` |
| retrieve hits | the `retrieve` trace event's `hits` payload |
| cards-endpoint cost | `AssistantCardController` performs one catalogue lookup **per id** — a known N+1, and the reason `CardIdList::MAX_IDS` is 12. Count queries, not just milliseconds |

**A console command has no HTTP request**, so `SalesChannelContextProvider::current()` throws.
`ProbeCommand` already solves this — it builds a context and supplies it through that provider's
callback scope. Copy that pattern rather than inventing one; it is the reason a real-catalogue check
is possible from the CLI at all.

**Read A's report first.** Its *Findings* section decides which of the columns above deserves depth,
and its *What this does not say* section is the half of the picture B exists to fill.

## Testing

- **The generator** gets deterministic unit tests: the counts of S4, the presence and shape of every
  trap in *Traps*, and byte-identical output for the same seed.
- **The 15 existing journeys** are the measurement. They are not modified; S3 is what makes that
  possible.
- **The four scale journeys** are the new assertions, one per trap.
- **The deterministic suite** must stay green and must not get slower: the generator runs only when
  `ASSISTANT_EVAL_CATALOG=large` is set.

## Out of scope

- Tuning any constant (S7).
- Phase B's implementation (S1).
- Shop context / FAQ / RAG, extensibility documentation, and B2B account capabilities — the three
  other items on the roadmap, deliberately not mixed in.
- Making `FixtureCommerceGateway` behave like Shopware's search. It is a fixture; the DAL is where
  search quality is measured, and that is phase B's half.
