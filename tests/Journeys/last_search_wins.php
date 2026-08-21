<?php

declare(strict_types=1);

// The cards must be about the thing that was answered.
//
// Reported from the deployed shop with a screenshot. A shopper asked about a tyre, then about a
// jersey, and got a reply naming both above a card row showing only Gravel Tyre variants — so the
// largest call to action on screen was for the product nobody had just asked about, and the answer
// about jerseys had scrolled out of view above it.
//
// The mechanism is deliberate and stays: the rendered set is the most recent tool batch, because a
// later search is usually a *refinement*. Rendering the union of every batch would show the twelve
// candidates a second search had just narrowed away. What was missing is that the model did not know
// the rule, so it is now stated in the system prompt and in the tool description — as an ordering
// instruction, and explicitly not as something to mention to the shopper.
//
// **Single-turn, deliberately, after a two-turn version failed 0/3 for the wrong reason.**
// `TurnAggregate::of()` merges every turn's cards before assertions run, on purpose: a safety
// assertion reading `$cards` must not miss what an earlier turn leaked. So a two-turn script made
// `rendered_ids_exactly` see turn 1's four tyres beside turn 2's jersey and fail on cards that were
// a correct answer to a question nobody was complaining about. The aggregation is right; the journey
// was wrong. Both topics now live in one message, which is also what the screenshot showed.
return [
    'id' => 'last_search_wins',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        // Both name a tyre first and ask about the jersey second, so the answered thing is the
        // jersey and the tyre is the distraction the ordering rule has to survive.
        'expert' => 'I will want a tan gravel tyre later, but first: Trail Jersey, black, M — available?',
        'beginner' =>
            'i also need a tan gravel tyre at some point, but whats up with that black trail '
                . 'jersey in medium, can i get it?',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        // The jersey was the question. A tyre id here is the reported defect.
        'rendered_ids_exactly' => ['expect' => ['fx-026-black-m']],
        'no_unbacked_price_in_prose' => [],
    ],
];
