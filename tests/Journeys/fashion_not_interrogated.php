<?php

declare(strict_types=1);

// The annoyance case, made testable. Three turns of ordinary browsing, and the question that matters
// is cumulative: how many times was the shopper asked to narrow before getting an answer?
//
// The rule bounds questions per CONVERSATION, not per turn. Three turns each asking one question is
// three questions, and by the third the shopper has been interrogated rather than helped — which no
// per-turn bound can catch. `TurnAggregate` joins every turn's prose (ruling R42), so `questions_at_most`
// reads the whole conversation here.
//
// The bound is 2 across three turns, not 1. That is a deliberate concession: a shopper who volunteers
// new information ("something lighter") invites a refinement, and a hard 1 would fail the assistant for
// engaging with a changed request. What it forbids is asking on every turn.
//
// `renders_at_least: 1` matters as much: it must never trade a question for the products. In production
// each request builds a fresh bundle, so the tool cannot remember that it asked last turn — only the
// model can, from the message history. That makes this a prompt-level rule and this journey the only
// place it can be checked.
return [
    'id' => 'fashion_not_interrogated',
    'catalog' => 'fashion',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'I need an outfit for a wedding',
        'beginner' => 'what to wear to a wedding',
    ],
    'config' => [],
    'turns' => [
        'archetype',
        'something lighter for a summer wedding',
        'in a size M',
    ],
    'assertions' => [
        'renders_at_least' => ['count' => 1],
        'questions_at_most' => ['max' => 2],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'no_absence_claim_in_prose' => [],
    ],
];
