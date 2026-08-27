# Family-Diversified Narrowing — Design

**Date:** 2026-08-27
**Status:** approved in conversation; implementation plan to follow
**Scope:** v1 — diversify the narrowed shortlist by product family. No facet-based spread, no config.

## Purpose

A shopper on the seeded fashion shop asks:

> show me dresses

1,355 dresses match. `SearchProductsTool` correctly reports the exact count (`matched: 1355`,
`MatchCountReader`, landed 2026-08-27) and correctly narrows to the model's requested limit — but the
narrowing is a plain relevance-ranked prefix (`\array_slice($survivors, 0, $requestedLimit)`,
`SearchProductsTool.php:295`), and relevance ranking has no notion of "one product vs. its size run."
Same-family variants share a name and description, so they rank adjacently. A realistic `limit: 8` result
is two dress *styles*, five and three sizes deep:

```
1. Wrap Dress 2213 — XS      5. Wrap Dress 2213 — XL
2. Wrap Dress 2213 — S       6. Cami Dress 1876 — XS
3. Wrap Dress 2213 — M       7. Cami Dress 1876 — S
4. Wrap Dress 2213 — L       8. Cami Dress 1876 — M
```

Measured live, not hypothesised: `docs/superpowers/reports/2026-08-27-fashion-catalogue-seeded.md`'s
real-shop turns for *"show me dresses"* and *"show me yoga clothes"* both note the returned cards
*"include multiple variants of the same families."* The assistant tells the shopper there are many —
then shows them two.

This is the second half of the shortlist problem `HANDOFF.md`'s Task A/Task B split named on 2026-08-27:
Task A (`MatchCountReader`) fixed the count; this is the sample.

## What was verified while designing this

| Claim | Finding |
|---|---|
| Where narrowing happens | `SearchProductsTool.php:295`, `\array_slice($survivors, offset: 0, length: $requestedLimit)` — the only place a limit is applied to the candidate set |
| What `$survivors` is at that point | Post-retrieval, post-interleave, post-variant-resolution, post-blocklist, post-`RedundantParentFilter` — `list<ProductCard>` in relevance-ranked order. Nothing between here and the slice re-sorts |
| `CandidateInterleave`'s ordering contract | *"Order is the contract... nothing downstream re-sorts... narrowing takes a prefix."* True today; a diversifier is the first thing to make it false, and the class's own docblock says so is the risk to manage |
| A usable "same family" signal | `ProductCard::$parentId` (`?string`) — already the signal `RedundantParentFilter` and `TruncatedFamilies::groupByFamily()` group by. A standalone product (`parentId === null`) is not a family of one to either of those classes |
| A usable "same facet value" signal | `ProductCard::$properties` (`array<string, list<string>>`) exists, but `categoryPath` is fixture-only — `DalProductCardMapper.php:67` hardcodes it `[]` in production, confirmed by the seeded-shop report's *"the real facet probe still has no category field."* Rejected as v1's dimension (see Decision F1) |
| Whether disclosure needs new code | No. `TruncatedFamilies::of($survivors, $returned, ...)` (`SearchProductsTool.php:346`) already compares survivors against whatever `$returned` turns out to be and discloses exactly what got left out, per family, with option values. A diversifier that changes `$returned`'s contents gets correct disclosure for free — verified by reading `TruncatedFamilies`/`WholeFamilyResolver` in full; neither needs to change |
| Existing pinned behaviour this must not disturb | `SearchProductsToolNarrowingTest::testTheAskedForVariantIsStillTheAnswerAtTheNarrowestLimit` (single-variant, `limit: 1`) and the `Gravel Tyre` 4-variant-family test in `SearchProductsToolWithheldTest` are both single-family scenarios — a family-diversifier is a no-op on a single family by construction, so both should stay green unmodified. Confirmed by reading the algorithm (Decision F2) against both cases, not assumed |
| Whether `fashion_many_matches` asserts diversity today | No. `tests/Journeys/fashion_many_matches.php` asserts only render count, question count, and grounding safety — zero coverage of family variety. `HANDOFF.md`'s Task B notes already named the fix: *"a journey on a large branch asserting the rendered set spans more than one value of some facet... `rendered_ids_from_each` already does this shape for id prefixes and is the model to copy"* |

## Decisions

| # | Decision | Why |
|---|---|---|
| F1 | **The diversity dimension is `parentId` (family), not `properties`/facets** | `parentId` is a single signal that works identically for any shop's taxonomy — a fashion shop, a cycling shop, anything. A facet-based dimension (Material, Colour, whichever) needs per-shop-type judgement about which facet matters, which is exactly "the guess to avoid" this project's own retrieval work has repeatedly flagged. `categoryPath` is unavailable in production regardless (F-finding above). No config, no per-merchant choice — v1 is family-only |
| F2 | **Two-pass, order-preserving: one card per family first, then backfill from already-shown families if slots remain** | A shopper never sees fewer cards than before because diversity ran out — confirmed in conversation as the required behaviour. Pass 1 walks `$survivors` in existing relevance order, keeping the first card per distinct family key until `$requestedLimit` cards are kept or `$survivors` is exhausted. Pass 2, only if pass 1 filled fewer than `$requestedLimit`, appends the *remaining* cards (already-represented families' further variants) in their original relative order until the limit is reached or survivors run out. The output is never longer than `$survivors` allows and is drawn from exactly the same set `array_slice` would have used — nothing is added, nothing is invented, only the order/selection changes |
| F3 | **Family key is `$card->parentId ?? $card->id`, computed by the diversifier itself — not `RedundantParentFilter`'s or `TruncatedFamilies`' notion of family** | Those two classes deliberately treat a standalone product (`parentId === null`) as *not* a family, because grouping it would invent a family the catalogue doesn't have for their purposes (superseded-parent removal, truncation disclosure). The diversifier's purpose is different: two different standalone products are still two different products worth showing separately, so each needs its own distinct key. Using the card's own `id` as the key when `parentId` is null gives every standalone product its own singleton "family," which is exactly the right behaviour for *this* class without borrowing or mutating the other two classes' semantics |
| F4 | **A new class, `FamilyDiversifier`, inserted between `RedundantParentFilter::apply()` and the `array_slice()` narrowing — not folded into `CandidateInterleave`** | `CandidateInterleave` runs before variant resolution, the blocklist and `RedundantParentFilter`. Diversifying that early risks selecting a "diverse" set that then loses members to blocklisting or redundant-parent removal, undermining the diversity work before the shopper ever sees it. `FamilyDiversifier` runs on `$survivors` — the fully resolved, fully filtered set — which is the only point where "diverse" and "final" mean the same thing |
| F5 | **`FamilyDiversifier::of()` takes `list<ProductCard> $survivors` and `int $limit`, returns `list<ProductCard>` — same signature shape as `RedundantParentFilter::apply()`** | Matches this codebase's established idiom for a pure, static, testable transform over a card list (see `RedundantParentFilter`, `TruncatedFamilies`). No new DTO, no new interface — the smallest surface that does the job |
| F6 | **`CandidateInterleave`'s class docblock and the `array_slice` neighbouring comment both get corrected in the same change**, not left stale | Both currently assert nothing downstream re-sorts / narrowing takes a plain prefix. Both become false the moment `FamilyDiversifier` runs. A stale claim like this is exactly the kind of thing a later change trusts and breaks on — this codebase's own convention (see the corrections already made to `SeedWriter`'s docblock earlier this session) is to fix the doc in the same commit as the behaviour, not later |
| F7 | **Verification is a new eval assertion measured against the real seeded fashion catalogue, not the fixture** | `FixtureTermMatcher`'s ranking is insertion-order-plus-in-stock-bias, not real relevance — a diversity test against the fixture would prove the diversifier works against an artefact, not against the actual failure mode the real-shop report measured. `ASSISTANT_EVAL_CATALOG=fashion` still only ever constructs `FixtureCommerceGateway` (confirmed, `JourneyAttempt.php:82`) — the eval suite cannot reach the seeded database. So the eval-suite assertion here can only prove the *algorithm* is family-aware, not that it fixes the real-shop symptom; the real-shop confirmation is a manual `swag:assistant:probe`/live-turn check, the same posture as Task A's own Task 10 |

## The algorithm, precisely

```
FamilyDiversifier::of(survivors: list<ProductCard>, limit: int): list<ProductCard>

  seen_families := {}
  first_pass := []
  leftover := []

  for card in survivors:
      key := card.parentId ?? card.id
      if key not in seen_families:
          seen_families[key] := true
          first_pass.append(card)
      else:
          leftover.append(card)

  if length(first_pass) >= limit:
      return first_pass[0:limit]

  needed := limit - length(first_pass)
  return first_pass + leftover[0:needed]
```

Properties worth stating explicitly, because they are what make this safe to insert without breaking the
pinned tests above:

- **Single-family input is a true no-op.** `first_pass` becomes exactly `survivors` (one key, every card
  keeps the same relative order it already had — the loop never reorders within a family, only
  interleaves *across* families that happen to share the prefix), so the output for a single-family
  search is identical to `array_slice(survivors, 0, limit)` today.
- **Never returns more cards than `array_slice` would have, and never fewer than it would have** —
  `first_pass + leftover` together are a permutation of `survivors`, so the same `limit` bound applies,
  and (per F2) the backfill guarantees the count only drops below `limit` when `survivors` itself has
  fewer than `limit` cards, exactly as today.
- **Never invents or drops a card.** Every card in the output was in `survivors`; every card in
  `survivors` not selected remains available to `TruncatedFamilies` via the unchanged `$survivors`
  argument at `SearchProductsTool.php:346`.

## Architecture

```
   ...retrieval, interleave, variant resolution, blocklist...
              │
              ▼
   RedundantParentFilter::apply($filtered['cards'])   unchanged
              │
              ▼  $survivors
   FamilyDiversifier::of($survivors, $requestedLimit)  ← NEW, this design
              │
              ▼  $returned  (same type, same downstream contract)
   registerRetrieved($returned) ─▶ 'products', 'total', 'families', 'matched'   unchanged
```

Nothing downstream of the diversifier changes. `$survivors` keeps meaning "everything that survived
filtering" throughout — the diversifier only changes which subset becomes `$returned`.

## Evals

One new assertion type, matching the shape `rendered_ids_from_each` already established for id-prefix
groups (per F7): a family-spread assertion that groups the rendered set's ids by their known family
prefix (the fixture's trap products already have stable `fw-*` prefixes suitable for this, the same way
`rendered_ids_from_each` uses them) and asserts the render spans more than one family when the match
count is large. Added to `fashion_many_matches` or a new sibling journey — decided at implementation-plan
time, not here.

The load-bearing confirmation is manual, against the real seeded shop, the same posture as Task A's
Task 10: re-run `swag:assistant:probe --search=dress --limit=8` (or the equivalent live turn) before and
after, and confirm the family count in the eight cards actually increases. This is what the real-shop
report's own "duplicate family variants" observation exists to be re-measured against.

## What this cannot test

The eval suite runs entirely against `FixtureCommerceGateway` and cannot touch the seeded database — so
the automated suite can prove the diversifier is family-aware and correctly bounded, and can prove it
does not disturb the existing single-family/single-variant pinned behaviours, but it cannot prove the
real-shop symptom is fixed. Only a manual, live check against the seeded shop can close that loop, and
that check is not a pass/fail the suite can assert — the same limit `docs/superpowers/specs/
2026-08-26-occasion-queries-at-fashion-scale-design.md` already recorded for a different feature on this
same catalogue.

## Known gaps

- **No facet-based dimension in v1** (F1). If family alone proves insufficient once measured against the
  real shop — e.g. a shop with very few families but each carrying enormous colour/material spread — a
  facet dimension is future work, not this design's problem to solve speculatively.
- **No config.** A merchant cannot turn this off or choose a different cap. If that turns out to matter,
  it is a follow-up, not a v1 requirement — nothing in this design blocks adding one later.
- **The eval-suite assertion (F7) cannot confirm the real-shop fix on its own** — recorded above, not
  hidden.
