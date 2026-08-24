<?php

declare(strict_types=1);

// P9, as a journey: a category is a helpful default, not a cage.
//
// The shopper is standing in Jerseys and asks for gloves. `SearchProductsTool` constrains the search
// to the category, finds nothing, and retries without it — recording `retrieve.without_category`.
// If that retry ever stops happening, this journey renders nothing and the shopper is told the shop
// has no gloves, which is the exact failure `no_match_not_absence` was written about.
//
// It asserts the OUTCOME rather than the trace stage, deliberately: what matters is that the shopper
// gets their glove, not that a particular row appears. `fx-004-black` is the catalogue's only glove
// and its single sellable variant, so the exact expectation is safe — `plural_finds_singular` rests
// on the same fact.
//
// A grounding journey, not a performance one: nothing here is about speed.
return [
    'id' => 'page_context_not_a_cage',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'do you have gloves?',
        'beginner' => 'looking for some gloves for commuting',
    ],
    'config' => [],
    'page' => ['categoryId' => 'Jerseys'],
    'turns' => ['archetype'],
    'assertions' => [
        'rendered_ids_exactly' => ['expect' => ['fx-004-black']],
        'no_absence_claim_in_prose' => [],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
