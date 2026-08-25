# The Tool Reply States What It Withheld — Design

**Date:** 2026-08-25
**Status:** approved in conversation; implementation plan to follow
**Fixes:** phase A *Finding 2* and phase B *Finding 4*

## Purpose

`SearchProductsTool` returns a bounded answer that reads as a complete one.

Two things it withholds, both silently:

| Withheld | Measured | Consequence |
|---|---|---|
| How many products matched | `total => \count($returned)` — reports 5, found 30 | The model cannot tell a complete answer from a sample |
| Which option values a truncated family has | 5 of 30 variants returned, no mention of the other 25 | The model cannot ask for `Size 30`, because nothing told it `Size 30` exists |

The second is the one a shopper feels. Asked *"Endurance Bib Tights, Size 30 — in stock?"* against a
2,149-unit catalogue, the assistant renders variants **1 through 5** — 0/3 runs on both archetypes,
six runs, no exceptions. Never the unit asked about.

Both are already in the trace: `retrieve.narrow` records `truncated`. **The trace knows more about the
answer than the party who has to give it.**

## The broken assumption

`ToolProductSummary`'s docblock justifies returning option values like this:

> Option values are not new information to the model either — since ruling R54 the system prompt
> already carries the catalogue's own vocabulary, including every option value.

**That is no longer true, and scale is what broke it.** `CatalogVocabularyBudget` caps the block at
1,500 characters; measured on a real 10,000-product shop it sends 66 of 106 available values, and on
the generated fixture one value per field. So the component that decided the model already knew every
option value now depends on a prompt that no longer carries them.

Two ways out: make the prompt carry them again, or stop depending on the prompt. This design takes the
second.

## Decisions

| # | Decision | Why |
|---|---|---|
| T1 | **The tool reply becomes self-sufficient about options.** It states the option values of a family it truncated, rather than relying on the prompt to have advertised them | The prompt is explicitly a *sample* — its own disclosure note says the list may be incomplete — so making it authoritative for families fights its stated purpose. And no per-field allocation fits a real shop's ~10³ option values into 1,500 characters, so budget tuning moves the cliff rather than removing it |
| T2 | **The server stays dumb: it reports, the model re-asks.** No auto-resolution of the shopper's intent | Retrieval already returns exactly `sc-family-30-v30` when `options:[["Size","Size 30"]]` is supplied — measured. The failure is that the model never supplied it. Auto-resolution has no input to work from until the model knows the value exists, so it is an optimisation for a case that already works, not a fix for this one |
| T3 | **Additive only. No existing field changes meaning.** `total` keeps meaning `count($products)` | The model has learned what `total` means. Redefining a number in place is the change that silently breaks something else; the honest count goes beside it under a new name |
| T4 | **`matched` is a floor, not a census.** When the candidate window was saturated, `more: true` says so | A true count needs either a method on `CommerceGatewayInterface` — marked `@api Public extension point`, so a breaking change for every gateway a merchant has written — or a second query per search. "At least 50" and "exactly 500" lead the model to the same behaviour: ask a narrower question |
| T5 | **The family option list is capped at 50 values**, with `options_truncated` when it bites | Otherwise a 30-variant problem is traded for a 3,000-variant one. 50 is `FACET_VALUE_LIMIT`'s existing precedent for "enough to be useful, bounded enough to send" |
| T6 | **No figure enters the reply.** No price, stock, delivery time or URL | `ToolProductSummary` says *"Never widen this"* about exactly that, and it is right: a figure here would let the model quote what it did not earn. Option values and counts about the reply itself are not figures, which is why this stays inside the existing boundary |
| T7 | **Success is `scale_family_beyond_window` going green.** If it does not, the design is wrong and the answer is T2's rejected alternative | Whether the model *uses* a hint is model behaviour and cannot be proven by construction. Naming the falsifier in advance is what keeps this from becoming an unfalsifiable improvement |

## The reply shape

Before:

```php
['products' => [...], 'total' => 5, 'note' => '…']
```

After — every existing key unchanged:

```php
[
    'products' => [...],   // unchanged: id, name, options
    'total'    => 5,       // unchanged: how many are in `products`
    'matched'  => 30,      // NEW: how many survived retrieval before narrowing
    'more'     => true,    // NEW: the candidate window was saturated, so `matched` is a floor
    'families' => [        // NEW: only when narrowing truncated a family
        [
            'name'     => 'Endurance Bib Tights',
            'shown'    => 5,
            'variants' => 30,
            'options'  => ['Size' => ['Size 1', '…', 'Size 30']],
            // 'options_truncated' => true, when the 50-value cap bites
        ],
    ],
    'note'     => '…',     // unchanged
]
```

`matched` and `more` are present on every reply. `families` appears only when it has something to say.

## Where the numbers come from

Everything needed is already in hand at the narrowing step in `SearchProductsTool::__invoke()`:

| Field | Source |
|---|---|
| `matched` | `\count($survivors)` — the set `retrieve.narrow` already measures its truncation against |
| `more` | `\count($cards) === $query->effectiveLimit()` measured on the cards **as RetrievalPass returned them**, before variant resolution, because that is the step the gateway's limit applied to |
| `families` | The truncated tail (`$survivors` minus `$returned`) grouped by `ProductCard::$parentId`; option values read from `ProductCard::$options`. Both fields already exist on the DTO |

No gateway call is added. No interface changes.

**`matched` deliberately excludes superseded parents.** `RedundantParentFilter` removes a family
parent when its own variants are in the result — an unbuyable aggregate beside the rows that already
answered the question. Counting it back in would report one product twice and invite the model to
treat the parent as a separate thing to offer. `matched` is therefore "distinct buyable candidates
retrieval found", which is also the number `retrieve.narrow` already measures truncation against.

**`more` is measured before variant resolution**, not after. The gateway's limit applied to what
`RetrievalPass` returned; `VariantResolver` can replace a parent card with a variant card afterwards,
so counting at that point would compare a post-resolution size against a pre-resolution bound.

## Architecture

```
SearchProductsTool::__invoke()
        │
        ├── RetrievalPass ──────────► $retrieved     (capped at effectiveLimit)
        ├── VariantResolver
        ├── BlocklistFilter
        ├── RedundantParentFilter ──► $survivors     ─┐
        │                                             ├─► matched, more
        └── array_slice(…, $limit) ─► $returned      ─┘
                     │
                     └── the truncated tail ──► TruncatedFamilies::of()  ──► families
```

One new class, `TruncatedFamilies`, pure: cards in, family summaries out. It exists so the grouping is
testable without the tool and so `SearchProductsTool` — already this project's largest class — does not
grow another responsibility.

## What this does not do

- **It does not tune any constant.** `MAX_LIMIT` stays 8, `DEFAULT_LIMIT` stays 5, `MAX_CANDIDATES`
  stays 50, and the vocabulary budget is untouched. The reply gets honest; the bounds stay.
- **It does not fix the vocabulary block's allocation.** Phase A's Finding 2 has a second candidate
  cause — the block sends one value per field — and this design deliberately does not address it,
  because if T7's criterion is met the block never needed to carry a family's options in the first
  place.
- **It does not count the catalogue.** See T4.
- **It does not change what the shopper sees.** Cards are rendered by `FactRenderer` from server-held
  data, exactly as before.

## Testing

**Unit, deterministic, no model:**

- `TruncatedFamilies`: a truncated family produces a summary; an untruncated result produces none;
  variants from two different families group separately; the 50-value cap sets `options_truncated`.
- The reply shape: `matched` counts survivors plus superseded parents; `more` is true only when the
  window was saturated; `total` still equals `count($products)`.
- **A guard for T6:** the new fields carry no `price`, `stock`, `deliveryTime` or `url` key. Asserted
  directly, because the one thing this pipeline exists to prevent is a figure the model did not earn.

**Against a model:**

- `scale_family_beyond_window` must go green against `ASSISTANT_EVAL_CATALOG=large`. This is T7 and the
  whole point.
- `scale_broad_term` should benefit from `matched`/`more`; it currently passes, so it must not regress.
- The 15 existing journeys must stay green on the small catalogue. The reply shape is model input, and
  `last_search_wins` and `plural_finds_singular` are the likeliest to be surprised by a new field.

Budget: ~5 minutes for the four scale journeys, ~15 for the fifteen existing ones, real model spend
either way.

## Out of scope

- The vocabulary budget's allocation across fields (see *What this does not do*).
- `MAX_FIELDS` below the gateway — phase B's Finding 5, which needs a differently seeded shop.
- The `SystemPrompt` availability tension (one rule forbids stating availability, another requires it
  for unavailable products). Real, cheap, and unrelated to this.
