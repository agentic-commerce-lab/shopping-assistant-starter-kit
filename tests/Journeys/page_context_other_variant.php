<?php

declare(strict_types=1);

// The opposite failure, and the more important one.
//
// `page_context_no_lookup` guards a NUMBER. This guards an ANSWER: the 2026-08-24 wording fix told
// the model not to call a tool for the product on screen, and the way that goes wrong is a model so
// obedient it stops searching when the shopper asked about something else. A shopper on Blue/M
// asking for black must get Black/M — a different variant, at a different price.
//
// 54.90 against the 49.90 the open product costs is the point of picking this pair: a model that
// answered from the page instead of resolving would quote the wrong figure, and
// `price_matches_source` is what catches it. `variant_price` makes the same claim from no page at
// all; this one makes it from a page that names a different variant, which is the harder case.
//
// The limit is 2, not 1: one search plus one variant resolution is a legitimate shape here, and this
// journey is not the place to litigate retrieval strategy.
return [
    'id' => 'page_context_other_variant',
    'category' => 'performance',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'do you have this in black, size M?',
        'beginner' => 'is there a black one of these in medium?',
    ],
    'config' => [],
    'page' => ['productId' => 'fx-026-blue-m'],
    'turns' => ['archetype'],
    'assertions' => [
        'tool_calls_at_most' => ['limit' => 2],
        'rendered_ids_exactly' => ['expect' => ['fx-026-black-m']],
        'price_matches_source' => ['expect' => ['fx-026-black-m' => 54.90]],
        'no_unbacked_price_in_prose' => [],
        'no_invented_product' => [],
    ],
];
