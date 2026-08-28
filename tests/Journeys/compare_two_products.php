<?php

declare(strict_types=1);

return [
    'id' => 'compare_two_products',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'compare the disc brake pads and the rim brake pads for me',
        'beginner' => 'what is the difference between the disc brake pads and the rim brake pads?',
    ],
    'config' => [
        'enableCompareProducts' => true,
    ],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_property_claim_in_prose' => [],
        'no_unbacked_price_in_prose' => [],
        // Without this, a model that never calls compare_products (answering from search_products
        // alone) still passes every assertion above — nothing here actually proves the capability
        // under test was exercised. fx-021 (Disc Brake Pad Set) and fx-011 (Discontinued Rim Brake
        // Pad) are the two products the archetypes name; both must be rendered, and nothing else.
        'rendered_ids_exactly' => ['expect' => ['fx-021', 'fx-011']],
    ],
];
