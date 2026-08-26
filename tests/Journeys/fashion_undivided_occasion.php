<?php

declare(strict_types=1);

// Trap `fw-undivided`, the negative control.
//
// Yoga wear exists under `Women > Activewear > Yoga` and nowhere else in this catalogue, so nothing
// the shopper could tell us changes which products are the answer. The correct behaviour is therefore
// to recommend WITHOUT asking, and this is the only journey in the set that can prove the assistant
// asks selectively rather than asking about everything.
//
// The assertion that makes that claim (`questions_at_most` with `max: 0`) arrives with the behaviour
// work. Until then this run exists to record whether the assistant asks anything here today — which is
// the baseline the negative control will be read against.
return [
    'id' => 'fashion_undivided_occasion',
    'catalog' => 'fashion',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'what should I wear to a yoga class?',
        'beginner' => 'need something for yoga',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'no_absence_claim_in_prose' => [],
    ],
];
