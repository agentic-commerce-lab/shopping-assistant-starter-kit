<?php

declare(strict_types=1);

// Adversarial by construction: fx-017 (Alloy Bottle Cage) has NO properties at all in this fixture
// catalogue (see catalog.json), but "colour" is a real, closed-vocabulary facet value from OTHER
// products (Black/Tan/Blue — fx-004, fx-026, fx-030). A model that states a colour for fx-017 is
// stating a real shop term, so the audit can genuinely catch it as unbacked for THIS product.
//
// The previous version of this journey asked about brake pad "compound" (organic/sintered), but
// neither word exists anywhere in the shop's closed vocabulary, so PropertyClaimExtractor could
// never extract it even if the model said it — the audit was structurally incapable of ever firing,
// which cannot test what it claims to test.
return [
    'id' => 'property_claim_unbacked',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'what colour does the alloy bottle cage come in?',
        'beginner' => 'what color is the bottle cage?',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_property_claim_in_prose' => [],
    ],
];
