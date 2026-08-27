# Handoff — 2026-08-27

You are picking up two pieces of work on `integration/fashion-scale-sweep`. Read this file, then
the two reports it names. Everything below is measured; where something is a guess it says so.

**Branch:** `integration/fashion-scale-sweep` (33 commits ahead of `main`). Nothing is pushed.
**State:** `vendor/bin/phpunit --exclude-group eval` → **900 tests / 5742 assertions OK**.
`composer run quality` → **exit 0**.

## Read these first, in this order

| Document | Why |
|---|---|
| `docs/superpowers/reports/2026-08-26-occasion-queries-baseline.md` | The original defect, and Finding 1 — why fixture results for this query class cannot be trusted |
| `docs/superpowers/reports/2026-08-27-end-to-end-on-the-real-shop.md` | Everything measured on a real shop, including what could not be measured |
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

# Task A — seed the fashion catalogue into the real shop

**Why this is first.** Every scale number produced so far is a **fixture** number, and the fixture is
demonstrably weaker than real Shopware search in ways that flatter the results. Until this exists,
nothing about 15,000 products across 1,000 categories has been verified.

The two divergences, both measured:

1. **`FixtureFacetBuilder` emits a `categoryPath` Terms facet**, so the prompt's vocabulary block
   literally contains `Occasion Dresses` and `Occasion Suits`. `DalCommerceGateway::facets()` registers
   price, `properties`, `options` and `manufacturer.name` — **no category aggregation at all**. The
   model was reading category names off a list production does not have.
2. **`FixtureTermMatcher` has no stemming.** `Occasion Suits` fails its all-token pass, so the any-token
   pass matches "occasion" alone and returns the **dresses**. That wrong-but-non-empty result also stops
   `RelaxedTermRetry` firing. This is why `fashion_wedding_occasion`'s `rendered_ids_from_each` is red
   on gemini — documented in the journey file.

## What to build

A dev-only console command that writes ~15,000 products across ~1,000 categories into a shop, matching
the fixture's taxonomy.

- The fixture generator is `tests/Fixtures/Fashion/FashionCatalogGenerator.php` and its taxonomy is
  `FashionTaxonomy.php` (3 departments × 14 garment types × 22 cuts, plus Brand / Season / Occasion).
  **Measured output: 3,629 products, 15,218 sellable units, 1,043 category nodes, 5.7 MB.**
- **`tests/` is not autoloaded inside a running Shopware installation**, so the command cannot use the
  generator. Duplicate the word lists and assert the duplication away — a test comparing the two
  classes' constants, or the two catalogues genuinely differ and Task A measures a different shop.
- Read `vendor/shopware/core/Framework/Demodata/Generator/ProductGenerator.php` and `CategoryGenerator.php`
  for the minimal payloads and batch sizes. **Do not guess a payload**; a `create()` that throws halfway
  leaves a shop nobody can describe.
- Guard against running twice. A marker category and a non-zero exit is enough. Do not add `--force`:
  the recovery for "I seeded twice" is a database restore either way.
- `mariadb-dump` before seeding. `APP_ENV=prod` when seeding and when measuring — `dev` runs the
  profiler and inflates every number.

## What to measure once it exists

- Does the vocabulary block still lack category names? (It must — that is the point.)
- **Re-run the whole eval suite against the seeded shop.** The interesting comparison is not pass/fail;
  it is which fixture results do not survive real search. Expect `Occasion Suits` to behave differently.
- The `MatchCountReader` counts, now against a real index — `TOTAL_COUNT_MODE_EXACT` on 15,000 products.
  Report the latency; it runs on every search.
- `DalCategoryTreeReader`'s two queries at 1,000 categories, and whether `MAX_NODES = 40` truncates
  anything. **That bound was chosen for headroom, not from data** — its docblock says so.

---

# Task B — a diverse sample, not the top N

**The finding, in the merchant's words:** *"what if the shop has hundreds of dresses and we just show
them 4 because those are the first 4 results."*

He is right, and it is the half of the shortlist problem that is still open.

## What already exists

`MatchCountReader` (added 2026-08-27) gives the model the **exact** match count instead of a floor
capped at the candidate window. Measured on the fashion fixture:

| query | shown | matched, before → after |
|---|---|---|
| `dress` | 8 | 32 → **1,133** |
| `dress` + `suit` | 8 | 50 → **2,152** |
| `Occasion Dress` | 8 | 25 → **25** |

So the assistant can now tell *"all six occasion dresses"* from *"eight of eleven hundred"*, and
`fashion_many_matches` asserts it shows products and asks at most one question. Observed on gemini:
*"There are many styles available across the collection."*

## What is missing

The eight it shows are still the **top eight by relevance**. On 1,133 near-identical generated dresses
that is eight near-identical cards. Telling the shopper there are many and then showing them eight of
the same thing is a weak answer, and no amount of counting fixes it.

## The shape of the work, and the open question

Interleaving already gives diversity **across** search terms (`CandidateInterleave`). This is the same
idea **within** one term, and the open question is what "different" means:

- **Which dimension?** Price, colour, style, category. The candidate window carries `properties`, so a
  facet-based spread is available without new queries — but which facet matters depends on the product
  kind, and picking one for all of them is the guess to avoid.
- **Where in the pipeline?** Narrowing is `array_slice($survivors, 0, $limit)` in `SearchProductsTool`.
  Diversifying means choosing a spread from `$survivors` instead of a prefix. Note that
  `CandidateInterleave`'s ordering contract says nothing downstream re-sorts — a diversifier would be the
  first thing that does, so read its docblock before changing the order.
- **Do not do this before Task A.** At real scale the ranking is Shopware's, not the fixture's
  insertion order plus an in-stock bias. The problem may look different, or smaller, and building
  against fixture ranking risks solving an artefact.

## How to know it worked

A journey on a large branch asserting the rendered set spans more than one value of some facet.
`rendered_ids_from_each` already does this shape for id prefixes and is the model to copy.

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
- `fashion_wedding_occasion · rendered_ids_from_each` — 0–1/3 on gemini, for the `FixtureTermMatcher`
  reason above. Left red because the assertion is right and the fixture is wrong. **Task A is what
  settles it.**

# Not merged

`main` carries only `c690c1e`, the migration fix — which was urgent because `plugin:update` was **dead**
on main for every merchant, and the kill-switch rename had therefore never run on any shop.

Everything else is on `integration/fashion-scale-sweep`, including the `feat/shop-info-retrieval` merge
(32 commits). Before merging that to main, `docs/superpowers/reports/2026-08-27-end-to-end-on-the-real-shop.md`
lists what the resolution needs reviewing for — chiefly that shop-info branched before the kill-switch
inversion, so `AssistantConfig` conflicts were resolved in main's favour deliberately.
