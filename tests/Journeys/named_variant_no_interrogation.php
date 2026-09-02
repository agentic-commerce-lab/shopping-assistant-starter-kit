<?php

declare(strict_types=1);

// **The shape the suite could not see: a shopper asked three questions and answered none of their
// own.**
//
// Reported from a live bike shop on 2026-09-02, on `openai/gpt-5-mini`: "es fragt sehr viele Fragen
// um wirklich auf Nummer sicher zu gehen, obwohl die Anfrage klar ist." Every other journey is
// single-turn, and `questions_at_most` reads `ProseQuestions::in($turn->prose)` — one reply. A model
// that asks exactly one question per turn, three turns running, passes `max: 1` in every one of them
// while doing precisely what was complained about.
//
// It works here because a multi-turn journey is asserted against `TurnAggregate::of()`, whose prose
// is every turn's prose concatenated: `max: 1` becomes "at most one question in the whole
// conversation".
//
// ## Why this catalogue and not the fashion one
//
// The first version of this journey asked for "a black midi dress in size M" against the fashion
// catalogue and was **wrong**, in the way that matters most: `Midi Dress 3135` — the product the
// model kept naming — carries `variants: []`, so the shop records no size for it at all. Size is a
// property on 2 of 3,629 fashion rows. The model answered
// *"the record does not list any Size options, so I can't confirm from this data whether it's
// offered in M — would you like me to check that?"*, which is exactly the grounded honesty this
// project is built to produce, and the journey marked it red. An assertion that punishes a correct
// answer is worse than no assertion.
//
// So every turn below names a variant this catalogue actually carries, with both of its option
// values, and each one resolves to a single unit:
//
//     fx-026-blue-l    Trail Jersey, Blue / L        stock 12
//     fx-030-tan-700   Gravel Tyre 40c, Tan / 700x40 stock  0   ← must be reported as sold out
//     fx-030-black-650 Gravel Tyre 40c, Black/650x47 stock  4
//
// There is nothing left to clarify in any of them, so a question past the first is the model buying
// certainty the shopper already handed it. One is still allowed, for the reason
// `fashion_small_match_no_question` records: asking must stay a closing offer rather than become a
// gate.
//
// `tool_calls_at_least` is explicit rather than implied: the failure measured beside this one was
// `toolCalls: 0` — a product question answered from the prompt alone. "Rendered nothing" has three
// causes and this separates one out. `stock_matches_source` pins the middle turn: the sold-out
// variant must reach the card carrying its real 0, which is what lets the assistant say so.
return [
    'id' => 'named_variant_no_interrogation',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'do you have the Trail Jersey in blue, size L?',
        'beginner' => 'hi, looking for the trail jersey in blue in size L',
    ],
    'config' => [],
    'turns' => [
        'archetype',
        'and the Gravel Tyre 40c in tan, 700x40?',
        'what about the black one in 650x47?',
    ],
    'assertions' => [
        // Across the whole conversation, not per reply — see above.
        'questions_at_most' => ['max' => 1],
        'tool_calls_at_least' => ['limit' => 1],
        'renders_at_least' => ['count' => 1],
        'no_rejected_tool_arguments' => [],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'stock_matches_source' => ['scope' => 'variant', 'expect' => ['fx-030-tan-700' => 0]],
    ],
];
