<?php

declare(strict_types=1);

return [
    'id' => 'why_matched_term',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'wet commute coming up — show me gloves or a mudguard set',
        'beginner' => 'it is going to be wet on my ride in, need gloves or a mudguard',
    ],
    'config' => [
        'enableMatchReasons' => true,
    ],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_property_claim_in_prose' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
