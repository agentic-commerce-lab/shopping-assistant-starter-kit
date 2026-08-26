<?php

declare(strict_types=1);

// The capability the shop-info feature exists to add: a question about the shop's own terms, answered
// from a document the merchant supplied.
//
// Before this feature the same question produced an escalation — measured on the lab shop: "Zur
// Widerrufsfrist kann ich hier leider keine verbindliche Auskunft geben". That was not a bad answer,
// it was the absence of a capability, which is what makes this the positive half of the pair.
//
// **How this journey gets documents.** `config.embeddingModel` is what puts `search_shop_info` in the
// toolbox at all (spec R13), and `JourneyAttempt` then indexes every file in
// `tests/Fixtures/shop_info_en/` — returns, shipping, payment, privacy, terms, imprint — through the
// shipped chunker and a real embedder. The questions are embedded by the same model, so this
// exercises the real chain and not a rehearsal of it. If no fixture path is configured the journey
// THROWS rather than running without the tool: a version of this that passed because nothing was
// retrievable would be worse than a red one.
//
// Six documents rather than one, because a returns question then has to beat five plausible
// neighbours. Measured: `recall@3` over this corpus is 8/8, so the right document does arrive.
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
    // **English, like every other journey in this suite.** The prose assertions below are
    // English-pattern detectors — `no_absence_claim_in_prose` matches "we don't sell", "we have no",
    // "not part of the shop's catalogue" and nothing in any other language. A German journey
    // therefore passes it without it ever being able to fire, which is the same vacuous pass
    // `retrieved_shop_info` exists to prevent, one layer up. Measured on 2026-08-26: language does not
    // change the retrieval finding either way (recall@3 was 8/8 in both), so there is nothing to be
    // gained by testing in a language the assertions cannot read.
    'archetypes' => [
        'expert' => 'How long do I have to return something?',
        'beginner' => 'hey can i send this back if it doesnt fit?',
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
        // The control, rather than a hope: every deadline the reply states must appear in a passage
        // the server actually retrieved. On the positive journey this is the assertion that catches a
        // right answer for the wrong reason — a correct-sounding fourteen days that came from the
        // model's knowledge of consumer law rather than from this merchant's document.
        'no_unsupported_period_in_prose' => [],
        'no_invented_product' => [],
        'no_absence_claim_in_prose' => [],
        'no_unbacked_price_in_prose' => [],
        // Nothing from the catalogue should be rendered for a question about shop terms.
        'rendered_ids_exactly' => ['expect' => []],
    ],
];
