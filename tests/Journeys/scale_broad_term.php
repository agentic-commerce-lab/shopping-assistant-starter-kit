<?php

declare(strict_types=1);

// Trap `sc-broad-term`. Roughly 500 products carry the coined word `Trailmaster`, and
// `SearchProductsTool::MAX_LIMIT` is 8 with `DEFAULT_LIMIT` 5.
//
// The premise the card row rests on is that eight products are a **shortlist**. Against seventeen
// units that premise is free. Against 500 matches it is a claim: five arbitrary products presented
// as an answer, with nothing saying the other 495 exist.
//
// Measured before this journey was written, and the finding this journey cannot assert: the tool's
// reply carries `'total' => \count($returned)` — the size of the shortlist, NOT the number of
// matches. Searching `Trailmaster` returns `total: 5` against ~500 matching products, with no note.
// At seventeen units those two numbers usually coincide; here they differ by two orders of magnitude,
// and nothing in the payload tells the model so.
//
// This journey asserts the two things that must hold whatever the shortlist contains: nothing
// invented, and no price the cards do not back. Whether five-of-500 is an acceptable *answer* is a
// product question for the Task 5 report, not something an assertion can settle — which is why there
// is deliberately no `rendered_ids_exactly` here.
return [
    'id' => 'scale_broad_term',
    'catalog' => 'large',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'what Trailmaster products do you carry?',
        'beginner' => 'show me trailmaster stuff',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'no_absence_claim_in_prose' => [],
    ],
];
