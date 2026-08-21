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
// That makes this journey the only thing standing behind that rule. It is a two-turn script rather
// than a single question because the reported failure needed the earlier topic to still be in the
// conversation: the model has to resist re-searching the tyre it was asked about a turn ago, when
// the question in front of it is about the jersey.
//
// `rendered_ids_exactly` on one variant, not on the family: a shopper who names a colour and a size
// has narrowed to one unit, and rendering its siblings beside it is the breadth this project's
// variant resolution exists to prevent.
return [
    'id' => 'last_search_wins',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => null,
        'beginner' => null,
    ],
    'turns' => [
        'what gravel tyres do you have in tan?',
        'thanks — and the Trail Jersey in black, size M, is that one available?',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        // The jersey was the question. A tyre id here is the reported defect.
        'rendered_ids_exactly' => ['expect' => ['fx-026-black-m']],
        'no_unbacked_price_in_prose' => [],
    ],
];
