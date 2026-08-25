# The Reply That States What It Withheld — Outcome

**Date:** 2026-08-25
**Spec:** `docs/superpowers/specs/2026-08-25-tool-reply-states-what-it-withheld-design.md`
**Plan:** `docs/superpowers/plans/2026-08-25-tool-reply-states-what-it-withheld.md`
**Baseline:** the addendum in `docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md`

## T7: did `scale_family_beyond_window` go green?

**No.** The design's premise is falsified, and the reason is not the one the spec anticipated.

| Journey | Archetype | Before | After |
|---|---|---|---|
| scale_family_beyond_window | expert | FAIL 0/3 | **FAIL 0/3, unchanged** |
| scale_family_beyond_window | beginner | FAIL 0/3 | **FAIL 0/3, unchanged** |
| scale_deep_duplicate | expert | FAIL 0/3 | FAIL 0/3 |
| scale_deep_duplicate | beginner | FAIL 0/3 | FAIL 0/3 |
| scale_broad_term | both | PASS | PASS |
| scale_option_beyond_facet_limit | both | PASS | PASS |

Rendered cards are byte-identical to the baseline: `sc-family-30-v1` … `v5`, missing `v30`, all three
runs of both archetypes.

## The fifteen existing journeys, small catalogue

**15 of 15 pass.** No regression from the new reply fields, verified against a live model rather than
assumed from "additive".

| Journey | Result |
|---|---|
| blocked_item, cart_add, injection_discount, last_search_wins, no_match_not_absence | PASS |
| order_status_declines, order_status_escalates | PASS |
| page_context_no_lookup, page_context_not_a_cage, page_context_other_variant | PASS |
| plural_finds_singular, price_constraint | PASS |
| variant_price, variant_stock, vocabulary_not_inventory | PASS |

Worth noting: `injection_discount` and `no_match_not_absence` were the two that went flaky on the
*large* catalogue in phase A. On the small catalogue, where this regression check belongs, both are
clean.

## Why T7 failed — measured, not inferred

The disclosure works. It cannot contain the answer.

`families` can only report what retrieval actually **fetched**, and retrieval is itself bounded by the
candidate window. Measured directly against the generated catalogue:

| `limit` | Candidate window | `matched` | Variants disclosed | Includes `Size 30`? |
|---|---|---|---|---|
| 5 (the model's default) | 20 | 20 | 20 | **no** |
| 8 (the ceiling) | 32 | 30 | 30 | yes |

The window is `min(MAX_CANDIDATES, max(limit × CANDIDATE_MULTIPLIER, MIN_CANDIDATES))` — for `limit: 5`
that is `max(20, 20) = 20`. The family has 30 variants. Variant 30 is never retrieved, so no honest
summary of what was retrieved can name it.

The reply is not lying. It says `matched: 20, more: true` — "there are at least 20 and I did not get
them all". That is exactly right, and exactly not enough: the shopper asked about `Size 30`, and
nothing in the pipeline has seen `Size 30`.

**The spec's error was one assumption stated nowhere:** that `$survivors` holds the whole family. It
holds at most `retrievalLimit()` cards. Everything built on top of that is correct and under-powered.

## What was kept, and why

Nothing is reverted. The two halves have different standing:

- **`matched` and `more` fix phase B's Finding 4 and are done.** The reply no longer reports a
  shortlist size as though it were a result count. That was never contingent on T7.
- **`families` is correct but currently cannot reach past the candidate window.** It is right for every
  family that fits inside it — proven deterministically at `limit: 8` above, and by unit test on the
  small fixture — and it becomes right for all of them the moment the window question is answered.

## Recommended next step

**Fetch the truncated family's own variants, rather than widening the window.**

When narrowing truncates a family, ask the gateway for that family's variants specifically — one extra
query, scoped to one parent — and build the disclosure from that instead of from the candidate window.
Correct at any family size, and bounded: one query per truncated family, not a bigger window on every
search.

Rejected alternatives, with reasons:

- **Widen the candidate window.** Thirty variants is not a ceiling; a real shop has families of a
  hundred or more. This moves the cliff instead of removing it, which is the same mistake as raising
  `MAX_TOTAL_CHARS` for phase A's Finding 1.
- **Server-side option resolution**, the alternative spec decision T2 named as the fallback. It is
  still not the answer, and now for a second reason: it needs the model to have passed an option, and
  the model cannot pass a value nothing in the system has seen.
- **Prompt instructions telling the model to use `families`.** The plan's own gate forbids this until a
  trace shows the model ignoring a `families` entry that *did* contain the answer. No such trace
  exists — every entry the model saw was missing `Size 30` by construction.

**One open question worth a cheap spike before building that.** `SystemPrompt` tells the model the
vocabulary block lists "the only spellings this catalogue matches". A value that appears in a tool
reply but not in that block may therefore read to the model as unusable. Whether the model will filter
by an option it learned from `families` is unknown, and it decides whether the fix above is sufficient
or merely necessary. One journey run against a hand-widened window would answer it.

## What this does not say

Nothing about a real DAL catalogue. These runs use the generated fixture and `FixtureTermMatcher`,
which has no keyword index and no relevance ranking.
