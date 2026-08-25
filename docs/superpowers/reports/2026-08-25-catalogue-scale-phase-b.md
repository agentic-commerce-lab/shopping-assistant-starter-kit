# Catalogue Scale — Phase B Numbers

**Date:** 2026-08-25
**Shop:** `shopping-assistant-test`, seeded with `framework:demodata --reset-defaults --products=10000 --properties=100 --categories=50`, restored afterwards from a 12 MB `mariadb-dump`
**Command:** `swag:assistant:benchmark --label=demodata-10k --repetitions=20`, three runs, `APP_ENV=prod`
**Spec:** `docs/superpowers/specs/2026-08-25-catalogue-scale-robustness-design.md`
**Phase A:** `docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md`

No number here is a pass or a fail. This is the half phase A could not measure: everything below the
gateway, against a real Shopware catalogue through the DAL.

**`APP_ENV=prod`, deliberately.** `dev` runs with `debug=true` and the profiler collecting, which
inflates every figure below. The plan did not specify an environment; it should have, and does now.

## The shop that was measured

| | Before seeding | After seeding | Restored |
|---|---|---|---|
| Product rows | 135 | 19,555 | 135 |
| Parent products | 121 | 10,121 | 121 |
| Property groups | 13 | **13** | 13 |
| Property options | 795 | **795** | 795 |
| Categories | 810 | 4,796 | 810 |
| `product_property` links | not measured | 70,942 | not measured |

Restored counts match the before-state exactly, and `swag:assistant:probe --search="Trail Jersey"`
returns the demo product with all three traps intact — Blue/M at stock 0 and 74.90, Black/M at 69.90,
parent at 79.90. The local shop is unchanged by this exercise.

**`--properties=100` created no property groups.** The seed reported `property_group 100 items` in
0.51 s, but the count is unchanged at 13 and every group and option in the database still dates from
the original install (2026-08-19). 0.51 s was never enough time for 100 groups of `rand(30-300)`
options. What demodata did instead was link its 10,000 new products to the **existing** 795 options —
70,942 `product_property` rows. See Finding 5: this is why one column of this report is empty.

## What the model is told, on a real catalogue

| | fields | values |
|---|---|---|
| available (what the probe found) | 6 | 106 |
| sent to the model | 6 | **66** |

| truncated | vocabulary chars | prompt chars |
|---|---|---|
| yes | 1,407 | 4,566 |

For reference, the same command against the unseeded 135-product shop reported `available 8 / 75`,
`sent 8 / 51`, 1,299 vocabulary chars, 4,458 prompt chars. That run was in `dev`, so its latencies are
not comparable with anything below, but the counts are environment-independent.

Two observations, neither of them a conclusion:

- **Truncation happens on a real shop at 13 property groups**, well under `MAX_FIELDS` (30): 106
  values available, 66 sent. The cut is values-only, which is the budget behaving as designed.
- **The field count went down as the catalogue grew** — 8 fields on 135 products, 6 on 19,555. Field
  count is evidently not monotonic in catalogue size, presumably because the aggregation returns a
  bounded set of buckets and which groups surface depends on the product mix. Recorded because it is
  surprising, not because it is understood. Worth a look before anyone reasons from a field count.

## Latency

### Facet probe — the largest single cost in the assistant's own path

| | Run 1 | Run 2 | Run 3 |
|---|---|---|---|
| live (ms) | 557.9 | 560.4 | 578.3 |
| cached (ms) | 6.4 | 0.4 | 0.3 |

Three runs, ~558–578 ms live. Tight enough to be a property of the code rather than of the laptop.

### Search, per query

Three runs of 20 repetitions each. Ranges are across the three runs.

| Term | p50 ms | p95 ms | worst max ms | retrieve hits |
|---|---|---|---|---|
| jacket | 36.7 – 37.9 | 37.6 – 70.9 | 213.4 | 0 |
| shoes | 41.3 – 45.1 | 45.5 – 68.8 | 702.0 | 10 |
| gloves | 33.1 – 36.9 | 36.2 – 38.8 | 39.9 | 0 |
| blue jersey | 54.2 – 55.1 | 56.8 – 66.5 | 93.7 | 18 |
| water bottle | 58.3 – 64.8 | 68.0 – 69.4 | 84.4 | 39 |
| jaket | 33.8 – 37.0 | 36.6 – 40.4 | 67.8 | 0 |
| winter cycling jacket | 54.7 – 56.6 | 57.3 – 59.8 | 62.5 | 7 |
| zzzznotathing | 33.2 – 37.1 | 34.6 – 38.4 | 40.0 | 0 |

Retrieve hit counts were identical in all three runs, so the catalogue and the queries are stable and
the timing spread is the machine's, not the data's.

### Cards endpoint

| ids requested | catalogue lookups | total ms | ms per id |
|---|---|---|---|
| 12 | 12 | 143.8 | ~12.0 |

Twelve ids, twelve lookups, sequential. `CardIdList::MAX_IDS` is 12 because of this shape.

## Findings

### Finding 1 — the facet probe costs ~560 ms, once per request, before the model is called

**Measured.** 557.9 / 560.4 / 578.3 ms live against 10,121 parent products; 0.3–6.4 ms once cached.

**Why the "once cached" number does not rescue it.** `FacetProbe` caches per `CatalogScope` for the
lifetime of its own instance, and its docblock is explicit that "an instance of this class lives for
one request". There is no cache pool behind `DalCommerceGateway::facets()`. So on PHP-FPM, where
nothing survives between requests, **every assistant turn that reaches retrieval pays the full ~560 ms
once**, and the cheap figure only applies to a second probe inside the same turn.

That is roughly 10× the cost of the search it exists to prepare (33–65 ms p50). It is the single
largest latency in code this project owns.

The spec's own words for cross-request invalidation were "a later plan's concern". This is the number
that concern was waiting for.

### Finding 2 — search is comfortable at 10k products, and scales with hits, not catalogue size

**Measured.** p50 33–65 ms, p95 35–71 ms across every query shape tried, on 19,555 product rows.

Cost tracks how much a query *finds*, not how big the catalogue is: the queries returning nothing
(`gloves`, `jaket`, `zzzznotathing`) sit at 33–37 ms, and the widest (`water bottle`, 39 hits) at
58–65 ms. Nothing here suggests retrieval latency is a problem at this scale.

The worst-case maxima (702 ms, 213 ms) all occurred on the first repetition of a run and never
recurred, so they are warmup rather than tail latency. Reported because p95 over 20 samples is the
19th value and therefore excludes them — the max column is where they show.

### Finding 3 — one card row costs 12 sequential lookups and ~144 ms

**Measured.** 12 ids → 12 catalogue lookups → 143.8 ms, ~12.0 ms per id.

This is the N+1 the spec asked to be counted rather than timed, now with both numbers. It is paid on
every card row the storefront renders, in addition to the turn that produced the ids. A batched lookup
is the obvious lever, and at ~12 ms per sequential id the saving is most of 144 ms.

### Finding 4 — the vocabulary block truncates on a real shop, but only values

**Measured.** 106 values available, 66 sent, `truncated: yes`, at 6 fields and 13 property groups.

Phase A's Finding 1 fix — dropping whole fields when even one value each will not fit — **was not
exercised here**, because 6 fields is far under `MAX_FIELDS` (30) and the block never got near that
path. The fix remains covered by unit tests and the generated fixture only. Its behaviour against a
real aggregation is still unverified, and Finding 5 is why.

### Finding 5 — the seed could not produce the catalogue this column needed

`framework:demodata --properties=100` did not create property groups on a shop that already had 13.
The spec's *Handoff* chose `--properties=100` precisely because "100 groups is comfortably past
`MAX_FIELDS` (30) and `FACET_VALUE_LIMIT` (50)", and that premise turns out not to hold on a
non-empty shop.

So the question "what does the vocabulary block do when a real DAL aggregation returns more than 30
fields" is **still open after phase B**, which is not what either the spec or the plan expected. It is
a gap in the method, not a defect in the product, and closing it needs a different seeding approach —
a fresh shop, or property groups created explicitly — rather than another run of this command.

## What this does not say

**Model latency and cost.** No model was called, deliberately, so no figure here includes it. The eval
suite measures that half and spends real money doing it. A shopper's wall-clock wait is these numbers
plus the model's.

**Anything above 10k products.** 19,555 product rows on one laptop, docker, MariaDB 11.8 with default
tuning, warm OS cache. These are shape-of-the-curve numbers, not capacity planning.

**`MAX_FIELDS` below the gateway** — see Finding 5.

**Concurrency.** Every measurement is sequential and single-client. Nothing here says what the facet
probe's ~560 ms does under ten simultaneous shoppers, which is the question that decides whether
Finding 1 is a latency problem or a capacity one.

**The before-seed comparison row** ran in `dev`; only its counts are comparable, not its timings.

## Recommended next step

**A cross-request cache for the facet probe, and a batched cards lookup.** In that order, and both
before any constant is tuned.

The reasoning: Finding 1 is ~560 ms on every turn, deterministic across three runs, in code this
project owns, and already anticipated by `FacetProbe`'s own docblock as deferred work. Finding 3 is
~144 ms on every card row from a loop that could be one call. Together they are most of the
assistant's non-model latency, and neither needs a constant changed to fix — which matters, because
spec decision S7's tuning question now has a clear answer for one bound and no answer at all for
another:

- `CardIdList::MAX_IDS = 12` is not too high on latency grounds once the lookup is batched; tuning it
  down would be treating the symptom.
- `MAX_FIELDS = 30` still has no real-catalogue evidence either way (Finding 5), so it should not be
  touched.

Phase A's Finding 2 — the 30-variant family answering the wrong variant — remains open and is
unaffected by anything measured here.
