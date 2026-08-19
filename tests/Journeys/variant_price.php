<?php

declare(strict_types=1);

return [
    'id' => 'variant_price',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'Trail Jersey, black, M — price?',
        'beginner' => 'how much is that jersey in black, medium size?',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'price_matches_source' => ['expect' => ['fx-026-black-m' => 54.90]],
        'no_unbacked_price_in_prose' => [],
    ],
];
