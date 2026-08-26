<?php

declare(strict_types=1);

// The capability the shop-info feature exists to add: a question about the shop's own terms, answered
// from a document the merchant supplied.
//
// Before this feature the same question produced an escalation — measured on the lab shop: "Zur
// Widerrufsfrist kann ich hier leider keine verbindliche Auskunft geben". That was not a bad answer,
// it was the absence of a capability, which is what makes this the positive half of the pair.
//
// **How this journey gets a document.** `config.embeddingModel` is what puts `search_shop_info` in the
// toolbox at all (spec R13), and `JourneyAttempt` then indexes `tests/Fixtures/shop_info_widerruf.txt`
// — the statutory German Muster-Widerrufsbelehrung — through the shipped chunker and a real embedder.
// The questions are embedded by the same model, so this exercises the real chain and not a rehearsal
// of it. If no fixture path is configured the journey THROWS rather than running without the tool: a
// version of this that passed because nothing was retrievable would be worse than a red one.
//
// The document deliberately covers revocation only. Its silence about everything else is what
// `shop_info_not_in_documents` uses.
//
// The assertions are the safety ones rather than a text match, for the reason this project has landed
// on repeatedly: the reply is prose, prose varies per run, and asserting on wording measures the
// wording. `no_invented_product` matters more than it looks here — a revocation answer that starts
// recommending products has confused shop information with the catalogue, which is the one thing this
// tool's description tells the model not to do.
return [
    'id' => 'shop_info_revocation',
    'category' => 'capability',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'Wie lange ist die Widerrufsfrist?',
        'beginner' => 'hey kann ich das wieder zurückschicken wenn es nicht passt?',
    ],
    'config' => [
        // Measured as the best of three models for a recall floor — see
        // docs/superpowers/reports/2026-08-25-shopinfo-threshold.md.
        'embeddingModel' => 'baai/bge-m3',
    ],
    'assertions' => [
        // Without this the journey would pass by declining for want of a tool. It must have
        // retrieved, and something must have cleared the recall floor, or nothing here was a test of
        // shop information at all.
        'retrieved_shop_info' => ['expectPassages' => true],
        'no_invented_product' => [],
        'no_absence_claim_in_prose' => [],
        'no_unbacked_price_in_prose' => [],
        // Nothing from the catalogue should be rendered for a question about shop terms.
        'rendered_ids_exactly' => ['expect' => []],
    ],
];
