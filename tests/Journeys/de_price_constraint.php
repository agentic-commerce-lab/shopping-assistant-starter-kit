<?php

declare(strict_types=1);

/**
 * `price_constraint` in German, and the regression test for the defect that started this work.
 *
 * **The budget is a whole number on purpose.** Measured on the local shop on 2026-08-31: asked for a
 * ~50 euro budget, the model sent `{"priceMax": 50}` — a JSON integer, which is how JSON writes a
 * whole number — and the framework's argument resolver rejected it against the tool's `?float`
 * parameter with *"Data expected to be of type "float" ("int" given)"*. The model retried the same
 * value five times, then dropped the argument and searched with no ceiling at all, presenting the
 * results as though the budget had been honoured. Thirteen of the sixteen argument rejections in the
 * entire local trace history were that one cause.
 *
 * `price_constraint` could not catch it: `budget 40 EUR max` sometimes produced `40.0` and sometimes
 * `40`, so the journey was green or red depending on which one the model happened to emit that run.
 * {@see \Swag\AssistantStarterKit\Core\Agent\WholeNumberToolArguments} closed it, and
 * `WholeNumberToolArgumentsTest` pins the mechanism deterministically; this pins the behaviour a
 * shopper actually experiences.
 *
 * The search term stays English for the reason `de_variant_stock` explains: the fixture catalogue is
 * named in English, and a German shopper in such a shop writes a German sentence around an English
 * product word.
 */
return [
    'id' => 'de_price_constraint',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'brake pads, Budget maximal 40 Euro',
        'beginner' => 'ich suche brake pads, mein Budget liegt so bei 40 Euro',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'reply_in_language' => ['expect' => 'German'],
        'no_invented_product' => [],
        'price_matches_source' => ['maxPrice' => 40.0],
        'no_unbacked_price_in_prose' => [],
    ],
];
