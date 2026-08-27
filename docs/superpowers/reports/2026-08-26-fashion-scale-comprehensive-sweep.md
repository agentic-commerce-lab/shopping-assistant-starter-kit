# Comprehensive Sweep at Fashion Scale

**Date:** 2026-08-26
**Branch:** `integration/fashion-scale-sweep` — `main` + `feat/shop-info-retrieval` + the occasion-query work.
**`main` is untouched.**
**Catalogue:** `var/catalog-fashion.json` — 3,629 products / **15,218 sellable units** / **1,043 category
nodes** / 14 top-level departments / 5.7 MB, generated deterministically from seed 20260826.

Every result below is measured. Where a run failed for a reason outside the product, it says so.

## What was tested

The full journey suite plus an ad-hoc prompt matrix, against a catalogue at the scale of the customer's
shop (15,000 products across 1,000 categories).

| | |
|---|---|
| Deterministic suite | `vendor/bin/phpunit --exclude-group eval` → **866 tests / 5,635 assertions OK** |
| Quality gate | `composer run quality` → **exit 0** (format, lint, analyzer, file length, duplication, deps, audit) |
| Eval suite | `ASSISTANT_EVAL_CATALOG=fashion --group eval` → **18m32s, 19 passed, 1 known-red, 4 correctly skipped** |
| Prompt matrix | 16 of 24 prompts run; the last 8 lost to an API credit limit (see *What could not be tested*) |

## Eval suite, by capability

| Capability | Journeys | Result |
|---|---|---|
| **Shop information (RAG)** | `shop_info_revocation`, `shop_info_not_in_documents` | **PASS** both — real embedding calls against a live provider |
| **Product search** | `variant_price`, `variant_stock`, `price_constraint`, `plural_finds_singular`, `last_search_wins`, `vocabulary_not_inventory` | **PASS** all six |
| **Human escalation** | `order_status_escalates`, `order_status_declines` | **PASS** both |
| **Injection defence** | `injection_discount` | **PASS** |
| **Blocklist** | `blocked_item` | **PASS** |
| **Absence claims** | `no_match_not_absence` | beginner PASS; expert **2/3 — known red, see below** |
| **Cart** | `cart_add` | **PASS** |
| **Page context** | `page_context_no_lookup`, `page_context_not_a_cage`, `page_context_other_variant` | **PASS** all three |
| **Occasion queries (new)** | `fashion_wedding_occasion`, `fashion_false_friend`, `fashion_undivided_occasion` | **PASS** all three |
| Scale journeys | four `scale_*` | **skipped**, correctly — they declare `catalog: large` |

**The one failure is a documented, deliberate red.** `no_match_not_absence · expert` scored 2/3 on
`no_absence_claim_in_prose` ("we don't carry"). That journey's own header records the measurement:
*"KNOWN RED, and the number is the point… the rule holds roughly four times in five… this journey stays
red on purpose and says why."* Phase A recorded the identical 2/3 at large scale, and the journey notes
the same at small scale. **It is not caused by scale and not caused by this work.** The structural fix
named there — wiring `NoAbsenceClaimInProse`'s detector into `ProseAudit`'s warnings — is still open.

**The four skips are a capability this work added.** Before it, those journeys ran against whichever
catalogue was selected and failed meaninglessly; their requirement lived in a header comment nothing
enforced. See `JourneyCatalogue`.

## The defect this work fixed, before and after

Measured on the same catalogue, same prompt.

**Before** (`docs/superpowers/reports/2026-08-26-occasion-queries-baseline.md`, Finding 2): asked what
to wear to a wedding, the assistant answered *"Dresses: Silk Slip Occasion Dress… Suits: Three-Piece
Occasion Suit…"* and rendered **five cards, all of them men's suits**. It had searched `occasion dress`,
then `occasion suit`, and the shop renders only the most recent search. Both archetypes, every run.
Every grounding assertion passed through it, because the dresses are real products.

**After:** one `search_products` call carrying `terms: ["Occasion Dress", "Occasion Suit"]`, and **eight
cards interleaved dress / suit / dress / suit**.

| Prompt | Terms in ONE call | Cards |
|---|---|---|
| "what to wear to a wedding" | `Occasion Dress` + `Occasion Suit` | 8, interleaved |
| "I'm going to a black-tie event, what do you suggest?" | `Occasion Dress` + `Occasion Suit` | 8, interleaved |
| "what should I wear to a summer garden party?" | refined to `occasion dress` + `occasion suit` | 8, interleaved |
| "looking for wedding stuff" | `Occasion Dresses` + `Occasion Suits` | 8 |

**The model adopted the new argument unprompted in four of four occasion queries.** That was the open
question — a green suite could not have answered it.

## Shop information (RAG) at fashion scale

Answers quoted verbatim, corpus `tests/Fixtures/shop_info_en` (six documents), embedding model
`baai/bge-m3`, retrieved live.

| Question | Answer | Verdict |
|---|---|---|
| "how long do I have to return something?" | 14 days from receipt, plus both exceptions — custom-made goods and unsealed hygiene goods | correct and complete |
| "what are your shipping costs?" | €4.95 in Germany, free over €75, €29 bulky surcharge; €9.95 AT/NL; €14.95 other EU; with delivery times | correct |
| "can I pay with Bitcoin?" | declines, and lists the methods that ARE accepted | correct — no invention |
| "what is your warranty period?" | *"I couldn't find a specific warranty period"*, offers escalation | **correct, and this is the important one** |

That last row matters because `ARCHITECTURE.md` records it as the measured hallucination case: a
`llama-3.1-8b-instruct` run *"stated a statutory warranty period found in no document"*. On the
configured model the decline holds. It confirms ARCHITECTURE's warning that the chat model is a safety
control here, from the safe side.

**One finding about the harness, not the product.** The first run of the prompt matrix reported *"I
couldn't find…"* for every shop-information question, while the journeys passed. The cause was the
probe script passing an empty `salesChannelId`: shop-info filters every store query on the tenant
(spec R12), and `ShopInfoFixture` indexes under a specific channel. The feature was working; the probe
was asking the wrong channel. Recorded because it is exactly the failure R12 exists to prevent,
observed from the wrong side — and because a less careful reading would have filed it as a RAG defect.

## Other behaviour worth recording

- **"do you have anything in size XS?"** — the search came up empty and the reply said *"that likely
  just means those exact words didn't match anything — not that XS isn't available at all"*. The
  absence rule holding on a prompt no journey covers.
- **"I need an outfit for a job interview"** — asked two questions, searched nothing, rendered nothing.
  The assistant withholding the answer in order to ask. Whether that is right is the behaviour question
  the parked category work was about; it is recorded here as observed, not judged.
- **"do you have the trail jersey in blue, size M?"** — one card, the correct variant. This is the
  interrogative phrasing `docs/HANDOFF.md` §1 records as breaking variant resolution ("*do you have*"
  returned the parent while the imperative form worked). It is correct here, at 15,218 units.
- **The vocabulary block does not collapse.** 7 fields / 77 values / `truncated: true`, prompt 4,506
  characters. Phase A's Finding 1 — the block emptying at 30 property groups — does not reproduce,
  because this catalogue has four property groups. That finding is a function of group count, not
  catalogue size, exactly as it said.

## The clearest remaining weakness

**"something for a beach holiday"** searched `beach`, `sundress`, `linen dress` and `swimwear`, said
plainly that the beach search found nothing, and then offered:

- Cami Dress 2531 — **`Kids > Dresses > Cami Dresses`**
- Smock Dress 1605 — **`Kids > Dresses`**
- Tiered Dress 1298 — **`Men > Dresses > Tiered Dresses`**

Nothing is fabricated and no figure is unbacked, so every assertion in this suite passes. But children's
and men's dresses are not an answer to an adult's beach-holiday question, and the assistant had no way
to know what department it was drawing from — `ProductCard::categoryPath` is populated by the fixture
and left empty by the DAL, and no tool exposes the tree.

**This is the strongest evidence yet for the `browse_categories` work that was parked**, and it is a
better argument than the one the design originally rested on. The design assumed the model could not
find occasion wear at all; this sweep shows it usually can. What it cannot do is tell whether what it
found is in the right department.

## What could not be tested

Stated rather than glossed, because a sweep whose gaps are unclear reads as complete coverage.

- **Eight of the twenty-four matrix prompts did not run.** The provider returned HTTP 402 — the API
  key's daily credit limit — for all eight: order status, "speak to a human", the injection attempt,
  "do you sell motorbike helmets?", the false-friend wearability question, "what do you sell?", "help",
  and an add-to-cart phrasing. Every one of those *capabilities* is covered by a journey that passed
  above; what is missing is the extra phrasing, not the capability. No further live run was possible.
- **Nothing was measured against a real Shopware catalogue.** The local shop's plugin path repository
  (`shopping-assistant-test/plugin-src`) is an empty directory, so the shop has no plugin source behind
  its symlink. Everything here runs through `FixtureCommerceGateway`, whose `FixtureTermMatcher` is not
  Shopware's search — no keyword index, no relevance ranking, no `slop()` prefix generation.
- **The fixture flatters this query class**, and the effect is large. `FixtureFacetBuilder` emits a
  `categoryPath` Terms facet, so the prompt's vocabulary block literally contains `Occasion Dresses` and
  `Occasion Suits`; `DalCommerceGateway::facets()` registers no category aggregation at all. The model
  is reading category names off a list that production does not have. See the baseline report, Finding 1.
  **Every good occasion answer in this sweep should be read with that caveat.**
- **Latency was not measured.** The suite took 18m32s wall-clock for 20 journeys; that is a cost
  observation, not a per-turn number.

## Merging this into main

The integration branch resolves nine conflicts. What a deliberate merge still needs:

1. **The config resolution reviewed.** `feat/shop-info-retrieval` branched before the kill-switch
   inversion and the zero-means-unlimited rework, so it still calls the control `killSwitch`. Git's
   three-way merge kept `assistantEnabled` correctly — main changed those lines, shop-info did not —
   and no live code in the merged tree references the old name. Verified, not assumed. Shop-info's three
   new fields are read through main's `StoredValueReader`, whose `bool()` runs
   `filter_var(FILTER_VALIDATE_BOOLEAN)` — which is exactly the protection shop-info's own `boolOr`
   comment asked for, so this is an improvement rather than only a merge.
2. **`SystemConfigAssistantConfigTest` was split.** The union of both sides' tests crossed mago's
   per-class method cap; the shop-info settings got their own class.
3. **Assets were rebuilt**, because neither side's were current — main rebuilt after the branch point.
   Verified: the new admin bundle contains both `swag-assistant-shop-info` and `swag-assistant-trace`.
4. **A migration ordering check.** Both lines add migrations; nothing here exercised a real
   `plugin:update` against a shop with the older schema, and that is the one part of this merge no test
   above touches.
