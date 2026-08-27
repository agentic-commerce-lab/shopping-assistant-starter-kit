# Fashion Catalogue — Seeded Shopware Measurement

> **Resolved after this report.** Two things below were open when this report was written and have
> since been closed on this same branch, before merge:
>
> - **Second-run guard.** "Task 10's command-level second-run check was not executed against the live
>   database" (below) was true at the time. It has since been verified live, against this same shop: the
>   guard correctly refuses to seed twice.
> - **`countMatches()` fix.** The "Match-count finding" and "Conclusions" sections below describe
>   `DalCommerceGateway::countMatches()`'s `TOTAL_COUNT_MODE_EXACT` defect as an outstanding follow-up.
>   It is fixed, on this branch, in commit `7c57e56` (docblock corrected in `1d65f3f`), which replaces it
>   with the `CountAggregation` approach this report itself measured as correct and faster.
>
> The measured content below is left as originally written; only this note is new.

**Date:** 2026-08-27  
**Shop:** local Docker instance at `127.0.0.1:8000`  
**Sales channel:** `01a01b4af6567284ac9eeb3616598ac3`  
**Branch:** `integration/fashion-scale-sweep` at `23cd78e`  
**Plan:** `docs/superpowers/plans/2026-08-27-fashion-catalogue-seeder.md` — Task 10

This report separates real-Shopware observations from fixture eval results. The automated fashion
eval still uses `FixtureCommerceGateway`; it cannot point at the seeded database and is comparison
evidence only.

## Seed result and scope

The database was backed up to `~/shopware-before-fashion-seed.sql` before seeding. The structurally
complete MariaDB dump is 13,156,614 bytes and completed at `2026-08-27 11:46:36`.

The corrected production-mode seed completed with:

```text
[OK] Seeded 1031 categories, 4 property groups, 3617 products (15201 sellable units).
```

Post-seed database checks:

| Check | Result |
|---|---:|
| Seeded product rows | 18,097 |
| Parent products | 3,617 |
| Variants | 14,480 |
| Product visibility rows | 3,617 |
| Sales channels receiving seeded visibility | 1 |
| Product search-keyword rows | 184,068 |
| Seed marker rows | 1 |

The visibility and data changes affect only this local Docker Shopware database. The seeder assigns
the generated products only to the selected storefront sales channel. No remote Shopware instance was
contacted or changed.

The marker exists and the guard path is covered by the project tests. The destructive-action policy
did not permit deliberately invoking the mutating seed command a second time, so Task 10's command-level
second-run check was not executed against the live database.

## Real catalogue vocabulary and retrieval

The real facet probe still has no category field. It exposes eight usable fields and 77 values; the
prompt sends eight fields and 53 values after truncation. This confirms that seeding changed the
catalogue behind `DalCommerceGateway`, not the shape of its vocabulary.

Real Shopware search behaves differently from the fixture in the two intended places:

- `Occasion Suits` returns 60 rows and includes actual suits as well as dresses. Shopware's indexed
  search handles this query where `FixtureTermMatcher` does not.
- `dress` returns at least 200 rows in a 200-row probe; a correct count aggregation reports 1,355.
- `yoga` returns and counts 24 rows.
- `wedding` returns and counts one false-friend product.

Four real storefront assistant turns all ended in `product_shown` with five grounded cards and no
warnings:

| Shopper question | Real-shop observation |
|---|---|
| `what to wear to a wedding` | Returned dresses and suits, then asked for dress code or setting. |
| `show me occasion suits and occasion dresses` | Returned a mixture of suits and dresses. |
| `show me dresses` | Returned dresses, but the five cards include multiple variants of the same families. |
| `show me yoga clothes` | Returned yoga products, again with duplicate family variants in the shortlist. |

The occasion result clears the real-shop behaviour question. The repeated family variants confirm the
separate diversification work remains justified; it is not part of this seeder task.

## Match-count finding

`DalCommerceGateway::countMatches()` is not exact on this Shopware 6.7 instance. Its current
`TOTAL_COUNT_MODE_EXACT` plus `limit = 1` implementation returns `1` for every non-empty term tested,
even when the same repository returns dozens or hundreds of rows.

Twenty-five warm repetitions of the current method:

| Term | Current result | Search rows observed | p50 | p95 |
|---|---:|---:|---:|---:|
| `dress` | 1 | at least 200 | 162.6 ms | 194.3 ms |
| `Occasion Suits` | 1 | 60 | 171.9 ms | 253.3 ms |
| `yoga` | 1 | 24 | 166.4 ms | 235.6 ms |
| `wedding` | 1 | 1 | 166.5 ms | 189.4 ms |

A `CountAggregation('matches', 'id')` over the same criteria returns the credible exact values and is
faster in the same container. Twenty warm repetitions:

| Term | Aggregated count | p50 | p95 |
|---|---:|---:|---:|
| `dress` | 1,355 | 123.6 ms | 142.2 ms |
| `Occasion Suits` | 60 | 133.9 ms | 161.4 ms |
| `yoga` | 24 | 148.6 ms | 175.8 ms |
| `wedding` | 1 | 120.2 ms | 187.6 ms |

The real assistant therefore currently receives a false `matched: 1` fact for broad non-empty
searches. Replacing the current repository search with the count aggregation is the minimal corrective
follow-up; it should be regression-tested before the seeded-shop behaviour is treated as final.

## Category-reader measurement

`DalCategoryTreeReader::read()` always performs two bounded DAL operations: one category read and one
product aggregation. The figures below measure their combined public method, after one warm-up, over
25 repetitions.

| Level | Returned nodes | p50 | p95 |
|---|---:|---:|---:|
| Storefront navigation root | 15 | 6.1 ms | 8.6 ms |
| Brand | 40 | 5.9 ms | 6.5 ms |
| Women | 14 | 1.6 ms | 1.8 ms |
| Men | 14 | 1.6 ms | 1.7 ms |
| Kids | 14 | 1.7 ms | 2.0 ms |
| Occasion | 10 | 4.4 ms | 5.3 ms |
| Season | 4 | 4.0 ms | 5.0 ms |

Direct category counts show that `Brand` is the widest active level at exactly 40 children; the next
widest levels have 23. `MAX_NODES = 40` therefore truncates nothing in this shop, although it has no
headroom on the Brand branch.

## Search benchmark

Three runs of `swag:assistant:benchmark --repetitions=20` measured the real gateway. The search p50
range was 66.3–87.1 ms across `dress`, `Occasion Suits`, `yoga`, and `wedding`. The vocabulary probe
was 352.9–431.2 ms live; its shared cache warmed from 396.8 ms on the first run to 12.6 ms and then
0.9 ms. The rendered vocabulary remained 1,307 characters inside a 4,466-character prompt.

## Fixture eval comparison

Command:

```bash
ASSISTANT_EVAL_CATALOG=fashion \
ASSISTANT_LLM_MODEL=google/gemini-3.7-flash \
vendor/bin/phpunit --group eval --testdox --filter fashion_
```

Result after 7 minutes 19 seconds: **6 of 7 journeys passed**. The sole failure was
`fashion_wedding_occasion` for both expert and beginner archetypes. Across all three runs per archetype,
`rendered_ids_from_each` found dresses but no `fw-occ-suit-*` card. Every safety assertion passed.

That failure is fixture-specific. The real-Shopware `Occasion Suits` search returns suits, and both real
occasion assistant turns rendered a dress/suit mixture. The experiment therefore confirms the premise
of the seeder work: the fixture's matching behaviour is not an adequate proxy for Shopware's indexed
search at this scale.

## Conclusions

1. The local seed is visible, indexed, sales-channel scoped, and usable for real assistant turns.
2. Real Shopware search fixes the fixture's `Occasion Suits` divergence and produces grounded mixed
   occasion recommendations.
3. Category browsing is inexpensive at 1,031 seeded categories and the 40-node cap truncates nothing
   in this dataset.
4. The production `MatchCountReader` is incorrect on broad real searches and should use the verified
   count aggregation before this branch is finalized.
5. Product-family diversification remains a separate next task, now supported by real-shop evidence.
