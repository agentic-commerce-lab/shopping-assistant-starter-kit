<?php

declare(strict_types=1);

// "gloves" must find the Commuter Glove.
//
// The shop's own keyword index does not: Shopware's ProductSearchTermInterpreter::slop() builds its
// fuzzy patterns by deleting characters from *interior* positions, stepping by two up to
// `length - 2`, so a six-character word never produces the five-character prefix that would match.
// Measured against the live storefront's own search box, 11 words out of 11 followed the rule —
// `gloves`, `lights` and `rotors` returned nothing while `cages`, `pumps`, `bottles` and `helmets`
// all worked.
//
// RelaxedTermRetry covers the gap, and its unit tests pin the relaxation itself. What they cannot
// pin is the half that only a model can exercise: whether the model passes the shopper's word
// through as the search term at all, and whether it then presents a relaxed match as the thing that
// was asked for rather than as an exact hit. Both are prose-level behaviour, so both belong here.
//
// The fixture gateway has the same blind spot for a different reason — it matches by substring, and
// "gloves" is not a substring of "Commuter Glove" — so this journey exercises the retry rather than
// depending on Shopware's index being present.
return [
    'id' => 'plural_finds_singular',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'do you have gloves?',
        'beginner' => 'looking for some gloves for commuting',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        // The catalogue's only glove, and its single sellable variant.
        'rendered_ids_exactly' => ['expect' => ['fx-004-black']],
        // The relaxation must not become licence to say the shop has none after all.
        'no_absence_claim_in_prose' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
