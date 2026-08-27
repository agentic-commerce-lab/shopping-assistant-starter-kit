<?php

declare(strict_types=1);

// The hundreds-of-dresses case: what happens when the shortlist is really a sample.
//
// Measured on this catalogue: `dress` matches **1,133 sellable units** and the tool returns at most 8.
// Before `MatchCountReader`, the reply said `matched: 32` — the capped candidate window — so the
// assistant could not tell 32 from 1,133 and presented eight arbitrary products as though they were
// the answer.
//
// What must hold now:
//
//  - It still SHOWS products. A turn that answers a browsing question with a question and no cards is
//    the friction shoppers actually resent (`renders_at_least`).
//  - It asks AT MOST ONE narrowing question. One is legitimate here — there are eleven hundred, and
//    dress code or size genuinely narrows them. Two is an interrogation (`questions_at_most`).
//
// What this journey deliberately does NOT assert: that the prose names the scale ("there are many",
// "over a thousand"). Every way of checking that textually is a guess about phrasing, and a flaky
// assertion on a control like this is worse than none — see `NoAbsenceClaimInProse`'s reasoning about
// patterns it refuses to match. Whether the assistant says so is read from the prose in the report.
return [
    'id' => 'fashion_many_matches',
    'catalog' => 'fashion',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'I am looking for a dress',
        'beginner' => 'show me dresses',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'renders_at_least' => ['count' => 1],
        'questions_at_most' => ['max' => 1],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'no_absence_claim_in_prose' => [],
        // Measured 2026-08-27 on google/gemini-3.7-flash: 5 of 5 rendered cards were distinct
        // families in every one of the 3 runs, both archetypes (6/6 runs, zero variance) —
        // comfortably above this floor. See RenderedFamilySpread for what "family" means here.
        'rendered_family_spread' => ['min' => 3],
    ],
];
