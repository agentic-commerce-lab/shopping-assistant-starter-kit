<?php

declare(strict_types=1);

// The other half of the rule, and the one that protects the shopper from being asked pointlessly.
//
// Measured on this catalogue: `yoga` matches **20 sellable units** — four products in five sizes, all
// under `Women > Activewear > Yoga`. There is nothing to narrow: the whole set fits in one row of
// cards, and any question is friction with no payoff.
//
// **`questions_at_most: 0` was the first version of this assertion, and it was wrong.** Measured
// 2026-08-27 on gemini-3.7-flash, 0/3 on both archetypes — and reading what it actually did shows the
// assertion was at fault, not the model:
//
//     renders_at_least  3/3   "High-Waist Yoga Legging, Seamless Yoga Bra Top, ..."
//     questions_at_most 0/3   "Are you looking for a particular size or type of piece?"
//
// It showed the products and THEN offered to refine. That question costs the shopper nothing — they can
// ignore it and click a card — and size genuinely narrows something here, because four products carry
// five sizes each. Forbidding it made this a control that fires on correct behaviour, which is the one
// thing `ProseAudit`'s own docblock says is unaffordable.
//
// So the bound is 1: the friction that matters is a question asked INSTEAD of showing products, and
// `renders_at_least` is what catches that. This journey's job is to ensure asking stays a closing offer
// rather than becoming a gate.
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
        'questions_at_most' => ['max' => 1],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
