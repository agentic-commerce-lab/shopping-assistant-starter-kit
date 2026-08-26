<?php

declare(strict_types=1);

// The customer case, as a journey. A fashion shop with ~15,200 sellable units across 1,043 category
// nodes, and a shopper who says a word the catalogue does not contain: trap `fw-occasion-word`.
//
// Measured before this journey was written, through the real tool chain and with no model involved:
// `search_products(term: "wedding")` against this catalogue returns ONE product — `fw-false-friend`,
// a "Wedding Cake Topper Charm" in `Gifts & Novelty > Keepsakes`. Six occasion dresses and six
// occasion suits sit in the catalogue and are unreachable by the shopper's own word.
//
// At baseline this journey carries ONLY grounding assertions, deliberately. What the assistant SHOULD
// do here is the subject of the behaviour assertions added later; what it must never do — invent a
// product, state a price the cards do not back, or tell the shopper the shop has no wedding wear — is
// already settled policy, and that is what this measures first.
return [
    'id' => 'fashion_wedding_occasion',
    'catalog' => 'fashion',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'I need an outfit for a wedding in September — what do you have?',
        'beginner' => 'what to wear to a wedding',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'no_absence_claim_in_prose' => [],
    ],
];
