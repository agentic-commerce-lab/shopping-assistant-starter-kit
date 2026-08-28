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
        // Proves the multi-term search this journey depends on actually happened: MatchReasons only
        // attributes `matched_term:*` codes when more than one term's candidate window exists, which
        // requires both "gloves" and "mudguard" to have been searched and rendered — not just one of
        // them answered from a single-term search. fx-004 (Commuter Glove) and fx-031 (Winter Mudguard
        // Set) are the only products in either category in this fixture catalogue.
        'rendered_ids_from_each' => ['groups' => [['fx-004'], ['fx-031']]],
    ],
];
