<?php

declare(strict_types=1);

return [
    'id' => 'property_claim_unbacked',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'what compound are the disc brake pads made of — organic or sintered?',
        'beginner' => 'what material are the disc brake pads?',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_property_claim_in_prose' => [],
    ],
];
