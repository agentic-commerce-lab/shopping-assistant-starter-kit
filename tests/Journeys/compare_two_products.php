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
    ],
];
