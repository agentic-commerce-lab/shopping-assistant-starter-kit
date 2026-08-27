<?php

declare(strict_types=1);

// Trap `sc-deep-duplicate`. `fx-007` ("Alloy Water Bottle 750ml") and `fx-008` ("Alloy Water Bottle
// 750 ml", one space apart) are already a deliberate near-duplicate pair with different stock; this
// makes it a trio, with the third one generated late so reaching it needs the candidate window to be
// wide enough at 2,000 units.
//
// A shopper asking about "the 750ml bottle" must be shown all three rather than an arbitrary two —
// the same reasoning that made the original pair a trap. Naming two of three is the failure: the
// shopper cannot tell that a choice was made for them.
//
// Measured before this journey was written, and the reason a red here needs reading carefully: which
// ids come back depends on the term the MODEL chooses, because `FixtureTermMatcher` matches
// substrings token by token.
//   "Alloy Water Bottle"       -> fx-007, fx-008, sc-deep-duplicate   (all three)
//   "Alloy Water Bottle 750ml" -> fx-007, sc-deep-duplicate           (fx-008 missed)
// fx-008 is missed by the verbatim name because its "750 ml" contains no "750ml" substring. That is
// the fixture matcher's tokenisation, not a product defect — spec section *What phase A cannot test*
// names exactly this as the alternative cause to rule out first.
return [
    'id' => 'scale_deep_duplicate',
    'catalog' => 'large',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'which Alloy Water Bottle 750ml options are there?',
        'beginner' => 'hi, what 750ml alloy bottles do you have?',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        'rendered_ids_exactly' => ['expect' => ['fx-007', 'fx-008', 'sc-deep-duplicate']],
        'no_unbacked_price_in_prose' => [],
    ],
];
