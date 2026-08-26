<?php

declare(strict_types=1);

// Trap `sc-rare-option`. One product carries `Colour: Chartreuse`, the sixty-first value in a group
// whose aggregation is capped at fifty buckets (`DalCommerceGateway::FACET_VALUE_LIMIT`).
//
// The fixture gateway has no such cap, so what this measures at fixture scale is narrower and worth
// stating: whether a **rare** option value still resolves when the vocabulary block handed to the
// model is a truncated sample that almost certainly does not contain it. That is the same shape as
// the `gloves` defect — the model searching with a word the catalogue's advertised vocabulary does
// not list.
//
// Verified reachable without a model: the name plus `Colour: Chartreuse` returns exactly
// `sc-rare-option`.
return [
    'id' => 'scale_option_beyond_facet_limit',
    'catalog' => 'large',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'Randonneur Musette in Chartreuse — do you have it?',
        'beginner' => 'im after that randonneur musette bag in chartreuse',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        'rendered_ids_exactly' => ['expect' => ['sc-rare-option']],
        // The vocabulary being incomplete must not become "the shop does not sell it".
        'no_absence_claim_in_prose' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
