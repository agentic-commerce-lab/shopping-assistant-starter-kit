# Handoff — 2026-08-27: the fashion-scale sweep

> **HISTORICAL. Both of its tasks are done and the branch it describes is merged.**
>
> `integration/fashion-scale-sweep` merged to `main` in `b8f5211` on 2026-08-27, and 77 commits have
> landed on `main` since. Its closing section, "Not merged", is therefore false as written, and its
> test/quality figures are a snapshot of that merge, not of `main` today.
>
> Kept, and moved here from the repository root, because a dozen committed plans and specs cite it by
> name — chiefly "The environment, exactly" for how the Docker shop is driven, "What the spec got
> wrong" for the decisions measurement refuted, and Task A's rule against a `--force` reseed.
> `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md` builds directly on its
> Task B.
>
> There is no current handoff. `ARCHITECTURE.md` is the architecture of record.


**Task A is done.** The fashion catalogue is seeded, measured, and reviewed — see
`docs/superpowers/reports/2026-08-27-fashion-catalogue-seeded.md` for the real-shop measurements, and
its "Resolved after this report" note for the two things it originally left open (the second-run guard,
and the `countMatches()` fix — see below) that have since been closed. **Task B, below, is the one piece
of work still open on this branch.** Read this file, then the reports it names. Everything below is
measured; where something is a guess it says so.

**Branch:** `integration/fashion-scale-sweep`, worked on directly (no worktree — the local Docker shop
bind-mounts this exact directory, so a worktree elsewhere would not be visible to it). Nothing is pushed,
nothing merged to `main`.
**State:** `vendor/bin/phpunit --exclude-group eval` → **938 tests / 18,768 assertions OK**.
`composer run quality` → **exit 0**.

## Read these first, in this order

| Document | Why |
|---|---|
| `docs/superpowers/reports/2026-08-26-occasion-queries-baseline.md` | The original defect, and Finding 1 — why fixture results for this query class cannot be trusted |
| `docs/superpowers/reports/2026-08-27-end-to-end-on-the-real-shop.md` | Everything measured on a real shop, including what could not be measured, before the seeder existed |
| `docs/superpowers/reports/2026-08-27-fashion-catalogue-seeded.md` | Task A's own measurement: the seeded shop, the `countMatches()` defect it found and that has since been fixed, and the duplicate-family-variant evidence that keeps Task B blocked no longer, just next |
| `docs/superpowers/plans/2026-08-27-fashion-catalogue-seeder.md` | The plan Task A was executed from, task by task |
| `docs/superpowers/specs/2026-08-26-occasion-queries-at-fashion-scale-design.md` | The spec. **Several of its decisions were refuted by measurement — see "What the spec got wrong" below.** |
| `ARCHITECTURE.md` | Architecture of record |

## The environment, exactly

The local shop is **Docker**, and this cost the last session real time to discover:

- Shop: `~/Workspace/shopping-assistant-test`. `docker compose up -d` (it is probably already up).
- `compose.override.yaml` **bind-mounts this repo** to `/var/www/html/plugin-src`. So `plugin-src` looks
  empty on the host — it is a mount point — and the container always runs whatever branch is checked
  out here. There is no deploy step; `git switch` changes what the shop runs.
- `docker compose exec -T web sh -lc '...'` for console commands. **`rtk` does not exist in the
  container** — use plain `grep`.
- `docker compose exec -T database sh -lc 'mariadb -uroot -proot shopware -e "..."'` for SQL.
  `dbal:run-sql` is broken here; go to the database directly.
- After changing PHP: `php bin/console cache:clear --no-warmup`.
- Storefront endpoint: **`POST http://127.0.0.1:8000/assistant/chat`** with
  `{"message": "..."}` and `X-Requested-With: XMLHttpRequest`. **`localhost` fails** — the sales-channel
  domain is `127.0.0.1:8000`.
- Shop contents: **135 products, 810 categories**, 11 shop-info documents / 16 vectors indexed.
- Backup taken before any change: `~/shopware-before-integration-migrate.sql` (13 MB).

**Two settings were changed on the shop and you may want them back:**
```bash
# chat model was anthropic/claude-sonnet-5
bin/console system:config:set SwagAssistantStarterKit.config.llmModel anthropic/claude-sonnet-5 \
  --salesChannelId=01a01b4af6567284ac9eeb3616598ac3
```
`embeddingModel` was also set to `baai/bge-m3` on the default row (the Storefront channel already had it).

## Running the evals

```bash
# fixture, deterministic, no model
vendor/bin/phpunit --exclude-group eval

# real model. ASSISTANT_LLM_* come from .env; override the model inline.
ASSISTANT_EVAL_CATALOG=fashion ASSISTANT_LLM_MODEL=google/gemini-3.7-flash \
  vendor/bin/phpunit --group eval --testdox
```

`ASSISTANT_EVAL_CATALOG` selects `small` (default), `large` or `fashion`. Journeys declare which
catalogue they were written for and are **skipped** on the others — see `src/Eval/JourneyCatalogue.php`.

**`google/gemini-3.7-flash` is ~5× cheaper than sonnet-5 and passed everything sonnet passed, plus one
thing sonnet fails.** Use it for routine runs. Caveat: `ARCHITECTURE.md` records the chat model as a
safety control for shop information, and that was measured against six documents, not a merchant's real
terms.

The provider key has a **daily credit limit**. A run that dies with HTTP 402 has exhausted it; wait for
the reset rather than debugging the code.

---

# Task A — seed the fashion catalogue into the real shop (done)

**Status: complete.** Built, unit tested, run against a real local Docker Shopware shop, measured, and
reviewed (including a final whole-branch review and its fix pass). Nothing below is prescriptive any
more — it is kept as the record of why this was built and what it found, for anyone reading this after
the fact. For the actual measurements, read
`docs/superpowers/reports/2026-08-27-fashion-catalogue-seeded.md`; for the implementation record,
`docs/superpowers/plans/2026-08-27-fashion-catalogue-seeder.md` and
`.superpowers/sdd/2026-08-27-fashion-catalogue-seeder/progress.md`.

**Why this was first.** Every scale number produced before this existed was a **fixture** number, and
the fixture is demonstrably weaker than real Shopware search in ways that flatter the results. Until
this existed, nothing about 15,000 products across 1,000 categories had been verified.

The two divergences, both measured before Task A, both confirmed still real once real Shopware was in
the loop:

1. **`FixtureFacetBuilder` emits a `categoryPath` Terms facet**, so the prompt's vocabulary block
   literally contains `Occasion Dresses` and `Occasion Suits`. `DalCommerceGateway::facets()` registers
   price, `properties`, `options` and `manufacturer.name` — **no category aggregation at all**. The
   model was reading category names off a list production does not have.
2. **`FixtureTermMatcher` has no stemming.** `Occasion Suits` fails its all-token pass, so the any-token
   pass matches "occasion" alone and returns the **dresses**. That wrong-but-non-empty result also stops
   `RelaxedTermRetry` firing. This is why `fashion_wedding_occasion`'s `rendered_ids_from_each` was red
   on gemini against the fixture — documented in the journey file. **Real Shopware search does not have
   this defect**: the seeded-shop report shows `Occasion Suits` correctly returning suits.

## What was built

`swag:assistant:seed-fashion-catalogue`, a dev-only console command
(`src/Command/SeedFashionCatalogueCommand.php`) that writes ~15,200 sellable units across ~1,031
category nodes through the DAL, matching the fixture's taxonomy (`src/Command/Seed/*`:
`FashionSeedTaxonomy`, `FashionSeedTraps`, `CategoryTreePlan`, `PropertyGroupPlan`, `ProductPlan` /
`ProductFillerBuilder`, `SeedId`, `SeedGuard`, `SeedWriter`, `SeedRunner`, `SeedCompletion`).

- The fixture generator is `tests/Fixtures/Fashion/FashionCatalogGenerator.php` and its taxonomy is
  `FashionTaxonomy.php` (3 departments × 14 garment types × 22 cuts, plus Brand / Season / Occasion).
  **Measured fixture output: 3,629 products, 15,218 sellable units, 1,043 category nodes, 5.7 MB.**
- **`tests/` is not autoloaded inside a running Shopware installation**, so the command could not use
  the generator directly. The word lists are duplicated in `src/Command/Seed/`, and
  `FashionSeedTaxonomyParityTest`/`FashionSeedTrapsParityTest` assert the duplication away.
- Guard against running twice: `SeedGuard` checks for a marker category before any write and refuses a
  second run with a non-zero exit. There is deliberately no `--force`: the recovery for "I seeded
  twice" is a database restore either way. **Verified live**, on this shop, after the seeded-shop report
  was written: the guard correctly refuses a second run.
- `mariadb-dump` before seeding. `APP_ENV=prod` when seeding and when measuring — `dev` runs the
  profiler and inflates every number.

## What the seed measured (see the report for full detail)

- The vocabulary block still lacks category names, as expected — seeding changed the catalogue behind
  `DalCommerceGateway`, not the shape of its vocabulary.
- The full eval suite against the seeded shop: `Occasion Suits` behaves correctly under real Shopware
  search, resolving the fixture-only defect above.
- **`MatchCountReader`'s `countMatches()` was measured wrong on a real index**: its original
  `TOTAL_COUNT_MODE_EXACT` implementation returned `1` for every non-empty search on this Shopware 6.7
  instance regardless of the true count (confirmed against 1,355 seeded dresses, 60 occasion suits, 24
  yoga pieces, all reported as `1`). **This is fixed on this branch**, in commit `7c57e56` (docblock
  corrected in `1d65f3f`): `DalCommerceGateway::countMatches()` now uses a `CountAggregation`, which the
  same report measured both correct and faster (down to ~130 ms warm) for every term that broke the old
  approach.
- `DalCategoryTreeReader`'s two queries at 1,031 categories are inexpensive (single-digit to
  low-double-digit milliseconds per level), and `MAX_NODES = 40` truncates nothing in this shop — Brand
  is the widest branch at exactly 40 children, so it has no headroom, but nothing is cut.
- Real assistant turns against the seeded shop returned dresses **and** suits for occasion queries, but
  also surfaced **duplicate family variants in the rendered shortlist** (e.g. multiple sizes/colours of
  the same dress shown as if they were different products) — real-shop evidence that Task B, below,
  remains real, open work, not a speculative concern.

---

# Task B — a diverse sample, not the top N (done)

**Status: complete.** Built, unit tested (including a pinned skew case — see below), run against a real
local Docker Shopware shop, measured, and reviewed (including a final whole-branch review and its fix
pass). Nothing below is prescriptive any more — it is kept as the record of why this was built and what
it found. For the implementation record, `docs/superpowers/plans/2026-08-27-family-diversified-narrowing.md`,
`docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md`, and
`.superpowers/sdd/2026-08-27-family-diversified-narrowing/progress.md`.

**The finding, in the merchant's words:** *"what if the shop has hundreds of dresses and we just show
them 4 because those are the first 4 results."*

He was right. Task A's real-shop measurement confirmed why: the seeded-shop report
(`docs/superpowers/reports/2026-08-27-fashion-catalogue-seeded.md`) recorded real assistant turns where
the rendered shortlist for `show me dresses` and `show me yoga clothes` contained **duplicate family
variants of the same product** — not a fixture artefact, but the real-Shopware ranking behaviour this
task existed to fix.

## What already existed

`MatchCountReader` (added 2026-08-27) gives the model the **exact** match count instead of a floor
capped at the candidate window. Measured on the fashion fixture:

| query | shown | matched, before → after |
|---|---|---|
| `dress` | 8 | 32 → **1,133** |
| `dress` + `suit` | 8 | 50 → **2,152** |
| `Occasion Dress` | 8 | 25 → **25** |

So the assistant can tell *"all six occasion dresses"* from *"eight of eleven hundred"*, and
`fashion_many_matches` asserts it shows products and asks at most one question. Observed on gemini:
*"There are many styles available across the collection."*

## What was built

`FamilyDiversifier` (`src/Core/Tool/FamilyDiversifier.php`): a two-pass narrowing step that replaces the
plain `array_slice($survivors, 0, $limit)` that used to run at the end of `SearchProductsTool`. Pass one
keeps the first card of every distinct family (parent id, or own id for a standalone product) until the
limit is reached or `$survivors` runs out; pass two backfills from already-represented families if pass
one didn't fill the limit. `FamilyDiversifier::of()` **is** the narrowing step now — `SearchProductsTool`
no longer slices separately. See its docblock and `CandidateInterleave`'s for the full mechanism,
including a known, deliberate limitation: the diversifier has no notion of which search term produced a
card, so when one term contributes many distinct families and another contributes one large family with
many variants, the rendered set can skew toward the many-family term. That skew is pinned as a unit test
(`FamilyDiversifierTest::testOneTermsManyFamiliesSkewsThePastAnotherTermsOneLargeFamily`), not just
documented in prose.

## What was measured

- **Fixture**, measured directly by the final code reviewer against the real tool pipeline: a genuine
  **3 → 8 family-spread improvement** on a fuzz-tested scenario, with the known duplicate-family-variant
  bug (1 family / 100 cards / limit 50 still correctly returning 50 cards) confirmed fixed.
- **Real seeded Docker shop** (not the fixture), live turn on `google/gemini-3.7-flash`, prompt *"show me
  dresses"*: rendered exactly **5 distinct dress styles among 5 rendered cards** — Pleated Midi Occasion
  Dress, Tiered Chiffon Occasion Dress, Silk Slip Occasion Dress, Embroidered Tulle Occasion Dress,
  Cape-Back Occasion Dress. Trace confirmed the full production pipeline ran (`search_products` tool call
  → `retrieve` (20 hits) → `retrieve.narrow` (18 survivors, 13 truncated, returnLimit 5, all 5 returned
  ids from 5 different product families) → `render`). This closes the gap the design spec's own "What
  this cannot test" section had flagged as outstanding: the fix works against the real DAL ranking, not
  just the fixture.
- `fashion_many_matches`'s `rendered_family_spread` assertion: `min: 4` — strictly above the measured
  pre-fix baseline of 3 (reconstructed via the retrieve trace against the unmodified `array_slice`
  narrowing), comfortably below the measured ceiling of 5 (5-of-5 across all 6 fixture runs, zero
  variance), so the assertion actually distinguishes fixed from broken instead of passing either way.

---

# What the spec got wrong

Do not treat `docs/superpowers/specs/2026-08-26-occasion-queries-at-fashion-scale-design.md` as
current. Measurement refuted several of its decisions, and the reports carry the corrections.

| Spec said | Measured |
|---|---|
| The model cannot answer an occasion query without a view of the category tree | **Largely false.** It reformulates "wedding" into "occasion dress" unaided. What it cannot do is orient a shopper whose search found **nothing** — that is what `CategoryTreeReader` was eventually built for |
| O18: ask when the tree shows the answer would differ materially | **Superseded.** The tree split needs no question — cards can show both branches, which is what multi-term search now does. What needs a question is **result-set size**, which is measurable |
| O17 needs a list of category ids on `ProductQuery` | **Not built, and not needed.** Multiple search *terms* achieved it; the model already produces the right words |
| Four assertion rows, three classes | Built as `questions_at_most`, `renders_at_least`, `rendered_ids_from_each` — plus `terms_without_results` in the tool reply, which the spec did not anticipate |

## Two things about method, learned the hard way

**A probe that builds its own toolbox measures its own toolbox.** Three of four apparent product
failures last session were the harness: an empty `salesChannelId` (shop-info filters on the tenant,
spec R12), and twice `ProbeTurnRunner` using `AssistantAgentFactory::withCoreToolsOnly()` while
`SearchShopInfoToolFactory` is container-tagged — so the probe **cannot** see the RAG tool. Use the HTTP
endpoint for anything involving shop information.

**An assertion that fires on correct behaviour is worse than no assertion.** Written twice into this
codebase before, and done again last session: `questions_at_most: 0` failed 0/3 because the assistant
showed four products and *then* offered to refine — which is the behaviour that was wanted. When an
eval goes red, read the prose before believing the assertion.

# Known red, on purpose

- `no_match_not_absence · expert` — 2/3 on sonnet-5, documented in the journey as KNOWN RED with the
  measurement. **Passes 4 runs / 24 samples on gemini-3.7-flash**, which is new information: the
  weakness looks model-specific rather than a limit of the prompt. Not proof — that file records a 3/3
  run followed by 2/3 on unchanged code.
- `fashion_wedding_occasion · rendered_ids_from_each` — 0–1/3 on gemini against
  `FixtureCommerceGateway`, for the `FixtureTermMatcher` reason above. Left red because the assertion is
  right and the fixture is wrong, and it will stay red against the fixture — the fixture itself was not
  changed. **Task A has since settled it**: the seeded-shop report confirms real Shopware's `Occasion
  Suits` search correctly returns suits, so the assertion's expectation is validated against real search,
  not just argued for.

# Not merged

`main` carries only `c690c1e`, the migration fix — which was urgent because `plugin:update` was **dead**
on main for every merchant, and the kill-switch rename had therefore never run on any shop.

Everything else is on `integration/fashion-scale-sweep`, including the `feat/shop-info-retrieval` merge
(32 commits). Before merging that to main, `docs/superpowers/reports/2026-08-27-end-to-end-on-the-real-shop.md`
lists what the resolution needs reviewing for — chiefly that shop-info branched before the kill-switch
inversion, so `AssistantConfig` conflicts were resolved in main's favour deliberately.
