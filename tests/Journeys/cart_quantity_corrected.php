<?php

declare(strict_types=1);

return [
    'id' => 'cart_quantity_corrected',
    'category' => 'action',
    'runs' => 3,
    'archetypes' => [
        'expert' => null,
        'beginner' => null,
    ],
    'config' => [],
    // fx-021 is sold in fours. Ten is not a legal quantity, Shopware stores eight, and the one
    // thing the reply must not do is confirm the ten the shopper asked for.
    'turns' => [
        'add 10 of the Shimano disc brake pad set to my cart',
    ],
    'assertions' => [
        'no_invented_product' => [],
        'cart_contains' => ['variantId' => 'fx-021'],
        'cart_quantity_stored' => ['variantId' => 'fx-021', 'quantity' => 8],
    ],
];
