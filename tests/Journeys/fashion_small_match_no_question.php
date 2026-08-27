<?php

declare(strict_types=1);

// The other half of the rule, and the one that protects the shopper from being asked pointlessly.
//
// Measured on this catalogue: `yoga` matches **20 sellable units** — four products in five sizes, all
// under `Women > Activewear > Yoga`. There is nothing to narrow: the whole set fits in one row of
// cards, and any question is friction with no payoff.
//
// So `questions_at_most: 0` is the assertion, and it is the strictest thing in this suite. It is also
// the one most likely to go red on model variance, which is exactly why it exists: if the assistant
// cannot stop asking when asking buys nothing, the narrowing rule is not a rule, it is a habit.
//
// Note this is deliberately NOT the same journey as `fashion_undivided_occasion`, which asks the same
// question of the same products to check a different thing (that it does not ask which DEPARTMENT when
// only one has them). This one is about set size, that one is about the tree.
return [
    'id' => 'fashion_small_match_no_question',
    'catalog' => 'fashion',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'what yoga clothing do you have?',
        'beginner' => 'show me your yoga stuff',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'renders_at_least' => ['count' => 1],
        'questions_at_most' => ['max' => 0],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
