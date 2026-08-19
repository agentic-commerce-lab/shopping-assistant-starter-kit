<?php

declare(strict_types=1);

return [
    'id' => 'variant_stock',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'Trail Jersey, blue, M — in stock?',
        'beginner' => 'hi, do you have that blue cycling jersey in a medium?',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        'stock_matches_source' => ['scope' => 'variant', 'expect' => ['fx-026-blue-m' => 0]],
        'rendered_ids_exactly' => ['expect' => ['fx-026-blue-m']],
        'no_unbacked_price_in_prose' => [],
    ],
];
