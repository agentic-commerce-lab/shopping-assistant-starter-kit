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
 *
 * ## Why the expert message says "mein Budget: höchstens"
 *
 * It read `brake pads, Budget maximal 40 Euro` until 2026-09-04, and on that wording
 * `reply_in_language` failed **0/3** — *"the reply reads as English (German 0 / English 14)"* on all
 * three runs, deterministically, while the `beginner` archetype beside it passed 3/3.
 *
 * The model was right and the journey was wrong. Not one token in that sentence is unambiguously
 * German: *brake pads* is English, *Budget* and *maximal* are both ordinary English words, and
 * *40 Euro* belongs to no language. An English speaker would write it verbatim. So
 * {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt}'s closing rule applied exactly as
 * written — *"If a message is too short to tell … and it is still unclear, answer in %s"* — and with
 * `'config' => []` that fallback is {@see \Swag\AssistantStarterKit\Core\Prompt\ReplyLanguage::FALLBACK},
 * English. The journey was asserting that the model guess German from a sentence containing no
 * German, which is the opposite of what the prompt tells it to do and is not a behaviour worth
 * having: it would answer an English shopper in German for writing "budget".
 *
 * So the input gained German rather than the assertion losing German. *mein* and *höchstens* are
 * unmistakable and the register stays telegraphic, which is what separates this archetype from the
 * `beginner` one — compare `de_variant_stock`'s expert message, which is equally terse and passes
 * because *Blau*, *Größe* and *auf Lager* leave nothing to infer.
 *
 * **The whole number is untouched, because it is the point.** `höchstens 40 Euro` asks for the same
 * bare integer `maximal 40 Euro` did.
 */
return [
    'id' => 'de_price_constraint',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'brake pads, mein Budget: höchstens 40 Euro',
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
