<?php

declare(strict_types=1);

return [
    'id' => 'property_grounded_claim',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'who makes the disc brake pad set?',
        'beginner' => 'what brand are the disc brake pads?',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_property_claim_in_prose' => [],
    ],
];
