<?php

declare(strict_types=1);

// Trap `sc-family-30`. Thirty variants, and the one the shopper asks about is the sold-out
// thirtieth — generated last, so reaching it means the retrieval window held the family whole.
//
// `SearchProductsTool`'s limit docblock records this failure having already happened once: with a
// narrow window, "do you have the blue jersey in M?" returned the blue L that happens to be in
// stock, because ranking's in-stock bias sorts a sold-out unit last. The window is 20–50 candidates;
// this family is 30 variants inside a 2,000-unit catalogue.
//
// Verified reachable without a model before this journey was written: searching the family name with
// `Size: Size 30` returns exactly `sc-family-30-v30`. So a red here is about what the model was told
// — the vocabulary block cannot carry 30 sizes — not about whether retrieval can find the unit.
//
// REQUIRES `ASSISTANT_EVAL_CATALOG=large`. Against the small catalogue this product does not exist
// and the journey fails for the wrong reason.
return [
    'id' => 'scale_family_beyond_window',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'Endurance Bib Tights, Size 30 — in stock?',
        'beginner' => 'do you have those endurance bib tights in size 30?',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        // The sold-out unit itself, with its own zero — not a sibling's five.
        'stock_matches_source' => ['scope' => 'variant', 'expect' => ['sc-family-30-v30' => 0]],
        'rendered_ids_exactly' => ['expect' => ['sc-family-30-v30']],
        'no_unbacked_price_in_prose' => [],
    ],
];
