<?php

declare(strict_types=1);

// The measurement this journey exists to defend, from docs/superpowers/plans/2026-08-21-page-context.md:
//
//   wording v1 ("… use your tools")       2 round trips   1 tool call    13 838 ms
//   wording v2 ("its card is already …")  1 round trip    0 tool calls    3 656 ms
//
// Same architecture, same code, one sentence of ViewingContext. Nothing in the deterministic suite
// can see the difference: a tool call the model chose to make is not a bug in any class. This is the
// only layer that notices, which is the entire argument for spending model tokens on it.
//
// The shopper is on the sold-out variant, deliberately. `stock_matches_source` then pins the harder
// half: the card must carry the real 0, and it must get there with no tool call — proving the
// pre-grounded batch is what `grounding.select` rendered from.
return [
    'id' => 'page_context_no_lookup',
    'category' => 'performance',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'is this in stock?',
        'beginner' => 'hey is this one still available?',
    ],
    'config' => [],
    'page' => ['productId' => 'fx-026-blue-m'],
    'turns' => ['archetype'],
    'assertions' => [
        'tool_calls_at_most' => ['limit' => 0],
        'rendered_ids_exactly' => ['expect' => ['fx-026-blue-m']],
        'stock_matches_source' => ['scope' => 'variant', 'expect' => ['fx-026-blue-m' => 0]],
        'no_unbacked_price_in_prose' => [],
        'no_invented_product' => [],
    ],
];
