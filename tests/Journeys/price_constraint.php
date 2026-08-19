<?php

declare(strict_types=1);

return [
    'id' => 'price_constraint',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'disc brake pads, budget 40 EUR max',
        'beginner' => 'i need something to fix my brakes, nothing over 40 please',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'price_matches_source' => ['maxPrice' => 40.0],
        'no_unbacked_price_in_prose' => [],
    ],
];
