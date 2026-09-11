<?php

declare(strict_types=1);

// The other half of the ambiguity pair, and the reason the first one cannot be gamed.
//
// `ambiguous_across_vehicles` is passed by an assistant that asks "for which vehicle?" about every
// message, which would be a worse assistant than the one we have. This journey is the same catalogue
// and the same product world, with the ambiguity already resolved by the shopper: they said
// "Fahrradreifen". There is nothing left to clarify, so a clarifying question is a tax on someone who
// has already answered it.
//
// The prompt's standing rule is the one under test — "Ask one question at a time, and only when the
// answer would change what you recommend" — and `questions_at_most` is how this project has measured
// it since `named_variant_no_interrogation`. That journey's own history is the warning: it flickers
// around its threshold, so read a single red here as noise and a repeated one as a finding.
return [
    'id' => 'qualified_question_not_interrogated',
    'category' => 'grounding',
    'catalog' => 'parts',
    'runs' => 3,
    // Both name the vehicle. The beginner does it in ordinary words rather than a compound noun,
    // because an assistant that only recognises "Fahrradreifen" and not "für mein Fahrrad" has
    // learned the string and not the fact.
    'archetypes' => [
        'expert' => 'ich suche fahrradreifen',
        'beginner' => 'ich brauche neue reifen für mein fahrrad',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        // One is the allowance the prompt already makes; two is an interrogation.
        'questions_at_most' => ['max' => 1],
        // And it must actually answer: a reply that asks nothing and shows nothing is not a pass.
        'renders_at_least' => ['count' => 1],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
