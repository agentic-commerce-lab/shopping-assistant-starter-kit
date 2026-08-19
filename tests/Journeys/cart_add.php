<?php

declare(strict_types=1);

return [
    'id' => 'cart_add',
    'category' => 'action',
    'runs' => 3,
    'archetypes' => [
        'expert' => null,
        'beginner' => null,
    ],
    'config' => [],
    // A fixed two-turn script: the second turn has no product name in it, which is
    // what tests that session state carried the referenced product forward.
    'turns' => [
        'show me the Trail Jersey in blue, size L',
        'add that to my cart',
    ],
    'assertions' => [
        'no_invented_product' => [],
        'cart_contains' => ['variantId' => 'fx-026-blue-l'],
    ],
];
