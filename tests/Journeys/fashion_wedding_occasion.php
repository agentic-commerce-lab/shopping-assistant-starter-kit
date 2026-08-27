<?php

declare(strict_types=1);

// The customer case, as a journey. A fashion shop with ~15,200 sellable units across 1,043 category
// nodes, and a shopper who says a word the catalogue does not contain: trap `fw-occasion-word`.
//
// Measured before this journey was written, through the real tool chain and with no model involved:
// `search_products(term: "wedding")` against this catalogue returns ONE product — `fw-false-friend`,
// a "Wedding Cake Topper Charm" in `Gifts & Novelty > Keepsakes`. Six occasion dresses and six
// occasion suits sit in the catalogue and are unreachable by the shopper's own word.
//
// At baseline this journey carries ONLY grounding assertions, deliberately. What the assistant SHOULD
// do here is the subject of the behaviour assertions added later; what it must never do — invent a
// product, state a price the cards do not back, or tell the shopper the shop has no wedding wear — is
// already settled policy, and that is what this measures first.
return [
    'id' => 'fashion_wedding_occasion',
    'catalog' => 'fashion',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'I need an outfit for a wedding in September — what do you have?',
        'beginner' => 'what to wear to a wedding',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        // O17, and the defect this whole line of work started from: the prose described dresses AND
        // suits while five men's suits rendered. Both branches must reach the shopper.
        //
        // **KNOWN RED on gemini-3.7-flash, for a fixture reason, and the mechanism is worth reading.**
        // Measured 2026-08-27: 1/3 expert, 0/3 beginner, dresses only. Gemini's search chain was
        // `dress|suit` -> `Occasion Dresses|Occasion Suits` -> `Occasion Dresses|Suits & Tailoring` ->
        // `Occasion Dresses`. It tried three times to retrieve suits and failed every time, because
        // `FixtureTermMatcher` has no stemming: the all-token pass needs "occasion" AND "suits", and
        // "suits" appears in no product name, so the any-token pass matches "occasion" alone and hands
        // back the DRESSES. That wrong-but-non-empty result is also why `RelaxedTermRetry` never fires.
        //
        // The prose stayed honest — it named only dresses — so this is an incomplete answer, not the
        // prose/cards mismatch the assertion was written for. `claude-sonnet-5` passes it, having
        // happened to use the singular `Occasion Suit`.
        //
        // The fix belongs in the fixture, not here: a keyword index resolves "suits" to "Suit" and this
        // matcher deliberately does not, so the fixture cannot fairly test the case. Left red rather
        // than relaxed, because the assertion is right and the fixture is what is wrong — the same
        // posture `no_match_not_absence` takes.
        'rendered_ids_from_each' => ['groups' => [['fw-occ-dress-'], ['fw-occ-suit-']]],
        'renders_at_least' => ['count' => 2],
        'questions_at_most' => ['max' => 1],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'no_absence_claim_in_prose' => [],
    ],
];
