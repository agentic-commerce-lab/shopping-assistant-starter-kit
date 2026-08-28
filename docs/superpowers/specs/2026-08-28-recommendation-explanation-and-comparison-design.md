# Recommendation explanation & product comparison — design

**Date:** 2026-08-28
**Status:** proposed
**Author:** Robin Schulte + Claude (brainstorming session)

## Why

The PM proposed two new shopper journeys:

- **Journey 2:** the assistant explains *why* a product was recommended, so the shopper can decide with confidence.
- **Journey 3:** the assistant compares relevant products — price, material, quality, other attributes — so the shopper can choose between alternatives quickly.

Neither is possible today. This assistant's tools (`search_products`, `get_product`) hand the model only `id`, `name`, and `options` per product — see `ToolProductSummary`. Price, stock, description, and `properties` (material, etc.) live on `ProductCard` but are stripped before the model ever sees them; they are rendered server-side by `FactRenderer` after the model replies, and audited against invention by `ProseAudit`. This is deliberate (rulings D3/D4): the model must never state a figure or fact it wasn't given, because prose is the one channel that can't be validated by construction.

Both journeys need the model to reason and narrate about product substance it currently has zero visibility into. Building either one directly means widening the model's view and, in the same motion, widening the invention surface that `ProseAudit` exists to close. That shared risk is why this spec covers both journeys as two phases: a shared foundation, then two thin journey-specific slices on top of it.

## Non-goals

- **Personalization.** "Why recommended" is scoped to *why this product was retrieved for this query* — matched term, in-stock standing, option match. This starter kit has no shopper profile, purchase history, or preference model, and building one is a materially larger project than this spec covers. If the PM wants personalized recommendations later, that's a separate spec.
- **Free-text description.** `ProductCard::$description` stays out of the model's view in both phases. It's open-ended text with no closed vocabulary to audit against — exposing it would need real NLP fact-checking, a different kind of project than anything in `Core/Grounding` today.
- **A "quality" field.** No such field exists in the data model, and it's inherently subjective. Both journeys ground themselves in objective, closed-vocabulary attributes (material, and whatever other property groups the shop defines) — never a fabricated quality judgment.
- **A side-by-side comparison table.** The assistant panel defaults to 420px wide (`$swag-assistant-panel-width`, `_tokens.scss`) with 176px cards in a horizontally-scrolling row (`_card.scss`) — there isn't comfortable room for an N-column, M-row table, and the panel's own design language is deliberately minimal (no structural component besides the card). Comparison happens through prose plus compact per-card spec chips, not a new table component.

## Phase 1 — shared foundation

Both journeys need the model to have real product substance to reason from, and need any resulting claim to be checked the way price and availability already are. This phase builds that once, so neither journey has to invent its own version of it.

### 1.1 Widen the model's view: `properties`

`ToolProductSummary::of()` gains a `properties` key (`array<string, list<string>>`, e.g. `"Material" => ["Merino"]`) sourced from `ProductCard::$properties`, returned by both `search_products` and `get_product`. `properties` is already populated end-to-end — `DalProductCardMapper` reads it from Shopware's own property groups via `PropertyGroupOptionReader`, and `FixtureIndex` populates it for the eval/fixture catalogue — so this is a plumbing change, not a new data source.

Bounded the same way `CatalogVocabulary`/`CatalogVocabularyBudget` already bounds the facet vocabulary block: a cap on property groups and values rendered per product, so a product with a sprawling attribute list doesn't blow up context the way an unbounded facet list would have. Reuse `CatalogVocabularyBudget`'s bounding approach rather than inventing a second one.

### 1.2 Audit attribute claims against the shop's own vocabulary

`properties` values are closed-vocabulary by construction — every value is drawn from the shop's own property groups, the same universe `FacetProbe`'s `FacetSet` already collects for `CatalogVocabulary`'s prompt block. That closed universe is what makes this auditable the same way price is: a claimed value either matches a known term or it doesn't, no open-ended NLP required.

New components, mirroring the existing `CurrencyFigureExtractor` / `ProseAudit::unbackedPrices()` pair:

- **`PropertyClaimExtractor`** — finds any known facet term (from the turn's `FacetSet`) that appears verbatim (case-insensitive, word/phrase-boundary matched) in the model's prose.
- **`ProseAudit::unbackedProperties(string $prose, list<ProductCard> $rendered, string $shopperMessage, FacetSet $facets): list<string>`** — flags any extracted term that no rendered card's own `properties` actually carries. Same R85-style exemption `unbackedPrices()` already has: a term the shopper introduced themselves is not a claim by the model.
- **`FactRenderer::unbackedPropertiesInProse()`** — new method following the exact shape of `unbackedPricesInProse()`/`unbackedAvailabilityInProse()`.

Known limitation, stated rather than hidden (matching this codebase's existing documentation style): a term that happens to also be an ordinary English word (a property value like "Blue" that could appear as an adjective for something else entirely) can produce a false positive. `CurrencyFigureExtractor` avoids this by requiring a currency-shaped token; there is no equivalent structural marker for an attribute value. Accepted for v1, revisit if it proves noisy in practice.

### 1.3 Plug into the existing seam — no new response contract

`GroundingOutputProcessor::processOutput()` calls `unbackedPropertiesInProse()` right beside the two existing audit calls. The finding travels the same path the other two already do:

```
FactRenderer::unbackedProperties()
  → AssistantRunner::run() reads it into AssistantTurn (new 3rd warning field)
  → controller response: warnings.unbackedPropertyClaims
  → render.js: new warningProperty translation, same priority chain as
    warningAvailability / warningPrice
```

Nothing is stripped or the reply refused — consistent with how the existing two audits behave today (detect + trace + surface as a UI banner, never block).

### 1.4 System prompt

A short addition to the system prompt (`SystemPromptProvider`/`SystemPrompt`) telling the model it now receives `properties` for retrieved products, and that it must state only values it was actually given — never infer, generalize, or use an adjective the shop didn't provide (e.g. never say "durable" unless "durable" is a literal property value). Same tone as the existing D3/D4 language.

### 1.5 Config

No new `AssistantConfig` flag for Phase 1 itself — `properties` exposure and the matching audit ship together as one unit, the same way price rendering and price auditing aren't independently toggleable today.

## Phase 2a — Journey 2: explain why a product was retrieved

### Match-reason signal

The model already fully controls its own retrieval filters (`priceMax`, `priceMin`, `options`) via its own `search_products` call, and `QueryBuilder` applies them as hard filters — so "this fits your stated budget" is true by construction for every result. No new signal is needed for that; it's out of scope.

What's genuinely missing is retrieval mechanics the model doesn't currently see:

- **Which search term** (when `terms` carried more than one) a given product actually matched. `TermContribution` already computes the inverse (which terms found *nothing*) — this is the per-product complement.
- **Stock/rank standing.** `FamilyDiversifier` already biases in-stock items first internally (see its docblock), but that fact never reaches the model today — `ToolProductSummary` excludes stock entirely, Phase 1 doesn't change that.

New component: a small deterministic `MatchReasons` collaborator computed inside `SearchProductsTool`, right after `FamilyDiversifier::of()` narrows the result set. Reuses the **reason-code convention `BlocklistFilter` already established** (`ARCHITECTURE.md`'s `reasonCode` pattern: `blocked_product`, `blocked_category`) rather than inventing a new claim shape. Example codes: `matched_term`, `in_stock`, `only_match`.

Added to `ToolProductSummary`'s per-product shape as `reasons: list<string>`.

Gated behind a new `AssistantConfig` flag (`enableMatchReasons`, off by default), the same capability-control pattern `enableAddToCart`/`enableEscalation` already use: the collaborator is simply never invoked and `reasons` never appears in the tool response when the flag is off, rather than the model being told not to use a capability it can see.

**No new audit needed.** These are closed, code-based signals the pipeline computed and handed over, not open claims the model could misstate — with one exception (an "in stock" narration), which is already covered by the existing `unbackedAvailabilityInProse` audit. Journey 2 needs zero new grounding infrastructure beyond Phase 1.

## Phase 2b — Journey 3: compare products

### `compare_products` tool

New `#[AsTool(name: 'compare_products')]` tool, bounded to 2–4 product ids (mirrors `SearchProductsTool::MAX_LIMIT`'s "a rejection, not a coercion" design — a request for more is refused, not silently truncated). Reuses `VariantResolver`, `BlocklistFilter`, and `FactRenderer::registerRetrieved()` exactly the way `GetProductTool` does today. Returns each product's `id`, `name`, `options`, `properties` — the exact shape Phase 1 already built, so **no new grounding work is required for this tool at all**. That's the payoff of building the foundation first: Journey 3's tool is close to pure plumbing on top of Journey 1's audit.

Gated behind its own `AssistantConfig` flag (`enableCompareProducts`, off by default), same pattern as `enableMatchReasons` above — the factory that constructs `CompareProductsTool` is simply never called when the flag is off, so it never appears in the toolbox the model sees (the same mechanism `AssistantAgentFactory::create()` already uses for `enableAddToCart`/`enableEscalation`).

### Card UI: spec chips, not a table

Every card in the existing horizontally-scrolling row gets a compact spec-chips line under the product name (2–3 short property values, e.g. `Merino · Waterproof`) — shown always, not gated behind a "compare mode." This needs `properties` added to whatever boundary already serializes rendered `ProductCard`s to the browser for price/stock display (a separate boundary from the model-facing tool shape, since price/stock already reach the frontend without going through the model). The exact serialization point is pinned down during implementation planning, not here.

No new comparison table component — see Non-goals.

## Verification strategy

This repo's standing quality bar, unchanged by this work:

| Command | What it checks |
|---|---|
| `composer test` | Unit suite (excludes `eval` group) |
| `composer test:eval` | Eval/journey suite — **makes real LLM calls against `ASSISTANT_LLM_BASE_URL`, costs real money and time; run deliberately, not incidentally** |
| `composer quality` | `format:check`, `lint`, `typecheck` (mago), `quality:filesize`, `quality:dupes` (jscpd), `quality:depcheck`, `quality:security` |

**Baseline captured 2026-08-28, before any change in this spec:**
- `composer test` — **984 tests, 18854 assertions, all green**, 16.4s.
- `composer quality` — clean. jscpd reports 92 pre-existing clones (2.92% duplicated lines) and `composer audit` reports one pre-existing, deliberately-ignored advisory (`CVE-2026-53965`, ruling R57) — both are accepted current state, not regressions to fix here.
- `composer test:eval` was **not** run as part of writing this spec (real API cost) — running it is the first step of the implementation plan below, to capture a real eval baseline before any code changes.

**Red-first, per this repo's own convention** (`tests/Journeys/*.php`, run via `composer test:eval`): new journey fixtures are written *before* the corresponding implementation, so they fail for the right reason first (capability absent) and pass for the right reason after. Proposed new journeys, following the existing naming style:

- `property_grounded_claim.php` — the model states a material/attribute value; asserts the value it stated is actually present on the rendered card's `properties`.
- `property_claim_unbacked.php` — adversarial: a case constructed so a model without Phase 1's guardrail would likely invent an attribute; asserts `unbackedPropertyClaims` fires when it does.
- `why_matched_term.php` — Journey 2: a `terms`-bearing search where the model explains which product matched which term; asserts the explanation only cites reason codes `MatchReasons` actually computed for that product.
- `compare_two_products.php` — Journey 3: a comparison request; asserts every property the reply states about each product is backed by that product's own rendered `properties`, and that no price is asserted outside `FactRenderer::render()`'s own figures.

**Implementation-time sequence** (detailed further by the implementation plan):
1. Run `composer test:eval` once, unmodified, to capture a real eval-suite baseline (pass/fail per existing journey) — deliberate, budgeted API spend.
2. Add the four new journey fixtures above; confirm they fail for the expected reason (capability doesn't exist yet) — the red baseline the user asked for.
3. Implement Phase 1, then Phase 2a/2b.
4. Re-run `composer test`, `composer quality`, and `composer test:eval` (including the new journeys) after each phase. The bar: the new journeys now pass, and every journey that passed in step 1 still passes — no regression in retrieval, grounding, or existing behavior anywhere else in the suite.

## Open questions for the implementation plan (not this spec)

- Exact file that serializes `ProductCard` to the browser for card rendering (needs to carry `properties` for the spec-chip UI).
- Exact bound values for property groups/values per product (mirrors `CatalogVocabularyBudget`'s three bounds — value cap, field cap, character budget).
- Full `MatchReasons` code taxonomy beyond the three examples given here.
