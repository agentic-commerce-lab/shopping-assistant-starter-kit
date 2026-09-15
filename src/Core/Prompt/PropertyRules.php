<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

/**
 * What the assistant may conclude from a product's properties, and what it may not.
 *
 * Its own class rather than a constant on {@see SystemPrompt}, for the reason {@see DepartmentRules}
 * and {@see DocumentRules} were split off it: that file sits on this project's ~400-line gate.
 *
 * **Unconditional, and it costs nothing when it does not apply.** A tool result only carries
 * `propertiesWithheld` when {@see \Swag\AssistantStarterKit\Core\Tool\BoundedProperties} actually
 * cut something, which on the demo catalogue is almost never — most products have fewer than four
 * values in a group. The rule is inert until it is needed.
 */
final class PropertyRules
{
    private function __construct() {}

    /**
     * The absence rule, and the measured answer that made it necessary.
     *
     * **Measured 2026-09-14.** A brake pad listed fifteen motorcycle models and twelve model years.
     * The cap handed the model four of each. Asked *"passt der Bremsbelag an meine Harley FLHRXS,
     * Baujahr 2008?"* it answered *"Ja, der Bremsbelag passt"* with no hedge — a wrong answer about
     * a brake part, from a tool result in which every value was true.
     *
     * Two separate mistakes live in that sentence, and the rule addresses both.
     *
     * **It read a partial list as a whole one.** That half is now visible in the data;
     * `propertiesWithheld` says eleven models and eight years never arrived.
     *
     * **It combined values across groups.** `FLHRXS` was in the model list and `2008` was in the
     * year list, and the reply treated that as a pair. Shopware properties are independent sets —
     * over the products measured that day, 76% of the brand/model/year combinations the sets imply
     * do not exist. No count fixes this one, so the rule states it outright: values from different
     * groups are not known to go together. It is the general case of the same error a shopper would
     * make reading "sizes: S, M, L" and "colours: red, blue" as six garments that are all in stock.
     */
    public const PARTIAL_PROPERTIES = <<<'PROMPT'
        A tool result may carry `propertiesWithheld` beside a product's `properties`. It counts the
        values of each group you were NOT shown, because the list was too long to hand over whole.

        For those groups you know only what is present. You do not know what is absent. Never say a
        product is unsuitable, incompatible or "not listed" for something on the strength of a group
        that withheld values — say what the product does list, say that the list is longer than what
        you can see, and offer to check the product page.

        Separately, and whether or not anything was withheld: each property group is its own list,
        and values from DIFFERENT groups are not known to belong together. A product listing models
        "A, B" and years "2019, 2024" is not thereby a product that fits model B in 2019. If a
        shopper asks about a combination, answer about the single values you can confirm and say
        plainly that the shop's data does not tell you how they pair up.
        PROMPT;
}
