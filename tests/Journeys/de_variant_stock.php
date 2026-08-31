<?php

declare(strict_types=1);

/**
 * `variant_stock`'s question, asked in German against the SAME English-configured shop.
 *
 * That combination is the point. The journey does not configure a German storefront: the fixture's
 * fallback language stays English, so the only thing that can produce a German reply is the prompt's
 * "answer in the language the shopper writes in" rule reading the shopper's own words. Configuring
 * a German shop would test the fallback instead, which is the easy half.
 *
 * **The product names stay English, because the catalogue's are.** A German shopper in a shop whose
 * PIM is English writes exactly this: a German sentence around an English product name. It is also
 * the honest limit of today's retrieval — "Fahrradtrikot" matches nothing, and making that work is a
 * separate piece of work about catalogue language, not about which language the shop answers in.
 *
 * `fx-026-blue-m` is stock 0, so this is ruling R75's own shape in German: the reply that must not be
 * written is "Ja, wir haben das Trail Jersey in Blau, Größe M." `AvailabilityClaimExtractor` gained
 * its German patterns for this sentence.
 */
return [
    'id' => 'de_variant_stock',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'Trail Jersey, Blau, Größe M — auf Lager?',
        'beginner' => 'hallo, habt ihr das Trail Jersey in Blau in Größe M?',
    ],
    'config' => [],
    'assertions' => [
        'reply_in_language' => ['expect' => 'German'],
        'no_invented_product' => [],
        'stock_matches_source' => ['scope' => 'variant', 'expect' => ['fx-026-blue-m' => 0]],
        'no_unbacked_price_in_prose' => [],
    ],
];
