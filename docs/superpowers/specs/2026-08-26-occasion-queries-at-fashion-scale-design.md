# Occasion Queries at Fashion Scale — Design

**Date:** 2026-08-26
**Status:** approved in conversation; implementation plan to follow
**Scope:** v1 — the category tree as a retrieval surface, and one bounded clarifying question.

## Purpose

A fashion shop with 15,000 products across 1,000 categories. A shopper opens the assistant and asks:

> What to wear to a wedding?

Every layer of this kit does the right thing and the shopper still gets nothing useful. The word
"wedding" appears in no product name, so `search_products` matches nothing; `SearchProductsTool::NO_MATCH_NOTE`
correctly tells the model that these words found no products and *not* that the shop has none; the
model correctly declines to invent alternatives. The result is an honest dead end in a shop that
sells hundreds of wedding-appropriate garments.

The model has no second move, because **it cannot see that the shop has categories.** The toolbox is
`search_products`, `get_product`, `add_to_cart`, `escalate`. The prompt carries a *property*
vocabulary — colours, sizes, manufacturers — and no category names at any point. A thousand
categories exist and none of them is addressable.

This is a missing capability, not a defect. Nothing here is broken; something here was never built.

## The behaviour this defines

Decided in conversation before any code was looked at, so the measurement below has something to be
measured against:

1. **Ask only when it changes the answer.** At most one question, and only when the catalogue itself
   shows that the answer set would be materially different depending on something the shopper has
   not said. Otherwise recommend immediately. The decision to ask is derived from the shop's own
   structure, never from the model's mood.
2. **When it asks, it still shows something.** One representative from each side of the split — an
   occasion suit and an occasion dress — so the question is concrete, the shopper gets value even if
   they never answer, and it costs one search.
3. **Never more than one question before a recommendation.** A shopper who wanted a form would have
   used the filters.

## What was verified while designing this

Every row read from the tree on 2026-08-26, so the plan can wire rather than investigate.

| Claim | Finding |
|---|---|
| The model can see the category tree | **No.** No tool exposes it, and `CatalogVocabulary` has no category handling of any kind |
| `CommerceGatewayInterface` can read categories | **No** such method. It has `facets`, `search`, `product`, `resolveVariant`, `addToCart`, `cart` |
| A capability can be added without touching the seam | **Yes, and it is the shipped pattern.** `DalCommerceGateway implements BatchProductLookup, CommerceGatewayInterface, FamilyVariantLookup` — two optional interfaces already sit beside the seam |
| `ProductCard::categoryPath` is populated in production | **No.** `DalProductCardMapper::map()` passes `categoryPath: []`. Only `FixtureIndex` fills it |
| The DAL aggregates categories | **No.** `DalCommerceGateway::facets()` registers price (stats), `properties`, `options`, `manufacturer.name`. No category aggregation |
| The fixture aggregates categories | **Yes.** `FixtureFacetBuilder` builds a `categoryPath` Terms facet — a divergence from production, see O3 |
| The category blocklist is enforced | **Yes, but at one layer, not two.** `DalCriteriaBuilder` excludes `scope->blockedCategoryIds` in the criteria; `BlocklistFilter`'s `categoryPath` loop is inert in production. See *Side finding* |
| `ProductQuery::categoryId` can carry a model-chosen category | **No** — it means "where the shopper is standing" and `withoutCategory()` exists so P9 can relax it away |
| `CatalogScope::includeCategoryIds` can | **No** — it is merchant policy, OR-ed, per request, not per query |
| Any clarification mechanism exists | **No.** The only "narrow it down" string in the codebase is `AssistantRunner::INCOMPLETE_TURN_MESSAGE`, the tool-cap degradation message |
| The tool-call budget allows browse-then-search | **Yes.** `maxToolCallsPerTurn` defaults to 20 |
| Shop-information retrieval could supply occasion knowledge | **Not yet.** Its spec and plan exist; nothing is implemented — no `src/Core/Knowledge`, no tool. This design stands alone and composes later |
| The vocabulary block survives a real large shop | Partly: 6 fields and **66 of 106 values** on a seeded 10,000-product shop, `truncated: yes` (phase B report) |

## Decisions

| # | Decision | Why |
|---|---|---|
| O1 | **The discriminator comes from a category tool, not from the search reply.** The `discriminators` field considered in conversation is dropped from v1 | It would have been computed from `ProductCard::categoryPath`, which the fixture fills and the DAL leaves empty. That is a feature with green evals and no production behaviour — the worst failure shape available here, and the reason this spec re-checked the mapper before writing a line |
| O2 | **A new optional `CategoryTreeReader` interface, beside the seam, never inside it** | `BatchProductLookup` and `FamilyVariantLookup` already do exactly this. `CommerceGatewayInterface` is `@api`; adding a method to it breaks every third-party gateway and all seven implementations in this tree, for a capability that is genuinely optional. Every implementation in this tree, production and test double alike, would need the method |
| O3 | **Task 1 measures against BOTH the fixture and a seeded shop, and the fixture result is never quoted alone** | The fixture exposes a `categoryPath` Terms facet and production exposes no category aggregation at all. So the fixture's vocabulary block can name categories that production's cannot — and a fixture-only baseline for *this* query class would read optimistically in precisely the direction that flatters the feature |
| O4 | **The tool is registered only when the gateway implements the reader; the prompt rule is omitted with it** | A starter kit offering a tool that always fails is worse than one offering no tool (the escalate tool's posture, and R13 of the retrieval spec). And an instruction to call a tool that is not in the toolbox makes a model improvise |
| O5 | **`browse_categories` returns opaque ids as handles, plus name, path, product count and child count** | The model already passes product ids back without understanding them. Returning search *words* instead was considered and rejected: a category name is not a search term, and "Occasion & Party" matches no product name either — which is the whole bug |
| O6 | **The reply carries no price, no stock, no delivery time, no URL — asserted against the encoded output** | Same prohibition as `ToolProductSummary` and `TruncatedFamilies`, and the same enforcement: a test that reads the serialised reply rather than trusting the class to be read |
| O7 | **`MAX_NODES` and every other bound here is measured during implementation, not chosen in this document** | The scale spec opens by naming eight constants calibrated against seventeen sellable units, not one of them ever observed above seventeen; one turned out to be a nine-character cliff that emptied the vocabulary block entirely. A number written into a design before anything has been generated is the same mistake with a nicer font |
| O8 | **`search_products` gains an optional model-facing `category` argument accepting a LIST of ids, on a NEW never-relaxed `ProductQuery` field** | Without it the category tool is decoration: the model learns `Occasion & Party > Dresses` exists and still has to guess words. It cannot reuse `ProductQuery::categoryId`, which P9 requires to be relaxed away — silently dropping a category the model deliberately chose turns a targeted search back into the guess this work exists to remove. **A list rather than one id, because of O17:** the shop renders only the last search, and one item from each of two disjoint branches cannot come from a search scoped to one of them |
| O9 | **The prompt rule states explicitly that general knowledge about an occasion is permitted as reasoning, while every product named must come from a tool** | The existing rule "do not recommend substitutes from general knowledge" is strict enough that a model may refuse to give dress-code advice at all — "I can only help with products in this shop." That refusal is a plausible baseline result and would be caused by the prompt, not by retrieval |
| O10 | **The fashion catalogue contains the twelve `fx-*` products verbatim, in a Sport branch** | S3 of the scale spec, reused: the fifteen existing journeys then run against it unchanged, so a red result is caused by the catalogue rather than by a rewritten expectation. It is the property that made phase A's baseline trustworthy and it costs nothing |
| O11 | **One seeded generator, two sinks: a JSON fixture and a dev-only DAL seeder** | The fixture buys determinism and a place in the eval suite; the seeder buys a real Shopware search, real categories and a real latency. One reviewable artefact rather than two catalogues that drift |
| O12 | **~15,000 sellable units and ~1,000 categories, and the categories are the point** | The scale work already crossed every product-side constant at 2,149 units. Nothing in this kit has ever been run against a category tree of any depth, and 1,000 categories is the number in the customer's shop |
| O13 | **Four named traps, one of them a negative control** | `fw-undivided` is the load-bearing one: without a case where the correct behaviour is *not* to ask, the suite can only assert that the assistant asks, never that it asks selectively — and "asks about everything" passes every other assertion here |
| O14 | **Task 1 changes nothing under `src/`, and reports before Task 2 is planned** | The same gate phase A used. It is also the honest reading of the request: the fix is conditional on the measurement, and a measurement taken after the fix measures the fix |
| O15 | **No shopper profile, no memory of the answer beyond the conversation** | The answer to "menswear or womenswear" lives in the message history the model already receives. Persisting it is a separate feature with its own privacy surface, and v1 does not need it to be correct |
| O16 | **No CMS or lookbook content in v1** | Occasion knowledge from a merchant's own style guide is the right long-term source and belongs to the shop-information-retrieval chain, which is unimplemented. Building a second ingestion path here would be the mistake R10 of that spec was written to avoid |
| O17 | **A turn that asks renders one representative from each side of the split** | The question becomes concrete, the shopper gets value even if they never answer, and it costs one search. It also forces O8's field to be a list: the shop presents only the most recent search, so both branches have to come out of that one call |
| O18 | **"Materially different" is given an operational definition in the Task 2 plan, and that definition must be computable from the tree** — for example, the candidate branches are disjoint and each non-empty — **never a similarity score or a model judgement** | It is the load-bearing phrase of the whole behaviour, and a phrase this vague resolves at implementation time by accident. A criterion computable from the tree can be asserted, traced, and shown to a merchant; a score is another unmeasured constant (O7) wearing a hat |

## The catalogue

`FashionCatalogGenerator` — seeded, pure, committed; its output is not. Generated into `var/`, the
same posture as `LargeCatalogGenerator` (S2), for the same reason: a committed multi-megabyte JSON
makes every regeneration an unreviewable diff, and the generator is the artefact worth reviewing.
Determinism comes from the seed. The generator carries unit tests asserting the counts and the id of
every trap.

Shape: Women / Men / Kids, each × garment type × subtype, plus Occasion, Season and Brand branches to
reach ~1,000 nodes. Fashion property groups — Colour, Size, Material, Fit, Sleeve Length, Neckline,
Pattern, Heel Height — with the value counts a real fashion catalogue carries.

### Traps

| Id | Shape | The behaviour it catches | What a wrong answer looks like |
|---|---|---|---|
| `fw-occasion-word` | "wedding" appears in no product name, description or property value, while the shop plainly sells wedding-appropriate garments | The customer case: a zero-match on the only word the shopper said | An honest dead end — "that search found nothing, shall I try different words?" — in a shop with hundreds of right answers |
| `fw-gender-split` | Those garments sit in disjoint `Women` and `Men` branches with no unisex overlap | That asking is *correct* here, because the answer set genuinely changes | A confident shortlist of guest dresses for a shopper who wanted a suit |
| `fw-false-friend` | One literal "Bridal Hair Clip" in Accessories | The case that is nastier than zero matches: the keyword search *succeeds* | One hair clip presented as the answer to what to wear, with the search having technically worked |
| `fw-undivided` | A second occasion the catalogue does **not** split materially | That the assistant does not ask when asking buys nothing | A question the shopper did not need, before an answer that was already available |

## Architecture

```
   shopper: "what to wear to a wedding?"
              │
              ▼
   ┌──────────────────────────────────────────────────────────┐
   │ system prompt + property vocabulary  (unchanged)          │
   │ + occasion rule (O9)   ── omitted when the tool is absent │
   └──────────────────────────────────────────────────────────┘
              │
              ▼
   browse_categories ──▶ CategoryTreeReader ──┐   optional interface,
      bounded nodes, no figures (O5, O6)      │   beside the seam (O2)
              │                               │
              ▼                               ▼
   search_products(category: <id>) ──▶ CommerceGatewayInterface   unchanged
      new never-relaxed field (O8)                  │
              │                                     ▼
              ▼                            DAL │ Fixture
   FactRenderer ──▶ cards the shop renders
```

Nothing in the grounding pipeline changes. No new figure reaches the model, and the cards are
rendered by the same path that renders them today.

## The gate

**Task 1 touches nothing under `src/`.** It generates the catalogue, seeds a shop, runs the fifteen
existing journeys and four new fashion journeys against the fixture, drives the seeded shop with
`swag:assistant:probe --ask` for each trap, and reports.

What the report must answer, per trap, on both catalogues:

- What does the assistant say today?
- Did it search, and with what term?
- Did it claim absence, refuse to advise (O9), or answer from the false friend?
- What reached the prompt — how many vocabulary fields and values, and did category names appear?
- What did the category read cost, once the reader exists to measure?

If the baseline shows the model already handles `fw-occasion-word` acceptably, this work stops there
and says so. The request was to fix the behaviour *if needed*, and O14 is where "if needed" is
answered.

## Evals

Four journeys, both archetypes, three runs, `ASSISTANT_EVAL_CATALOG=fashion`.

The grounding half reuses existing assertions: `no_invented_product`, `no_unbacked_price_in_prose`,
`no_absence_claim_in_prose`, and `rendered_ids_exactly` where the expected set is determinate.

The behaviour half needs four new assertions:

| Assertion | What it holds |
|---|---|
| at most one question | The reply does not interrogate. Counts questions in prose, not question marks — the exact detection is an implementation decision with a test, not a design one |
| the question names a branch | The thing asked about is a branch the tool actually returned, so the question is grounded in the catalogue rather than invented |
| the rendered set spans both branches | O17: on a turn that asks, at least one card from each side of the split. Asserts the promise the question makes — that both answers are real in this shop |
| no question asked | On `fw-undivided` only. The negative control of O13, and the only assertion here that a model asking about everything cannot pass |

## What this cannot test

Stated in advance, because a measurement whose limits are unclear gets over-read.

`FixtureTermMatcher` is not Shopware's search: no keyword index, no relevance ranking, no `slop()`
prefix generation. So the fixture can say whether the assistant *elicits and plans* correctly, and
can say nothing about whether the garments it recommends are the right garments. That judgement comes
from the seeded shop through `probe --ask`, and its output is prose a human reads — not an assertion,
because "is this a good wedding outfit" is not a pass or a fail.

Latency of a category read against 1,000 categories is a number, reported beside the phase B figures,
never a threshold. The facet probe already costs 558–578 ms live on a 10,000-product shop; whether a
category read joins that cost or hides behind the same shared cache is a measurement this design does
not pre-empt.

## Side finding, recorded not fixed

`BlocklistFilter::isBlocked()` iterates `$card->categoryPath` to remove products in a blocked
category. With the DAL gateway that loop can never match, because `DalProductCardMapper` passes
`categoryPath: []`.

The guarantee itself holds — `DalCriteriaBuilder` excludes `scope->blockedCategoryIds` in the criteria,
so a blocked category's products never reach the filter. But `ARCHITECTURE.md` line 465 describes the
blocklist as closed at two layers, and in production the category half is closed at one. That is a
documentation correction and possibly a defence-in-depth gap; it is not in this scope, and it is
written down here so it is not re-discovered.

## Known gaps

- **No persistence of the shopper's answer** (O15). A shopper who says "menswear" and returns
  tomorrow is asked again.
- **One question, one dimension.** A wedding has at least three — role, dress code, season. v1 asks
  about the one the catalogue splits on and lets the shopper volunteer the rest.
- **Occasion knowledge is the model's, not the merchant's** (O16). A merchant who disagrees with what
  the assistant considers wedding-appropriate has no way to say so until the
  shop-information-retrieval chain lands.
- **`browse_categories` exposes the tree's shape to anyone who can talk to the assistant.** Category
  names are shopper-visible in the storefront navigation already, but an unlisted category is not, and
  the scope filters that hide products from search do not obviously hide categories from this tool.
  The plan must decide whether the reader honours `CatalogScope` and assert whichever way it lands.
