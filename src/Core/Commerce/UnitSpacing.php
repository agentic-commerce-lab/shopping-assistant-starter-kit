<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

/**
 * One spelling for a measurement, whether the catalogue writes `750 ml` or `750ml`.
 *
 * ## Why this is a rule and not a nicety
 *
 * A catalogue of any size is fed by many suppliers and contains both spellings of the same thing.
 * The demo catalogue makes that explicit on purpose: `Alloy Water Bottle 750ml` and `Alloy Water
 * Bottle 750 ml` are two products, one space apart, and a shopper asking for "the 750ml bottle"
 * means both.
 *
 * **Verified against a real Shopware, 2026-09-14**, with two products created for the purpose:
 * searching the glued spelling returns BOTH. So the shop already treats them as one spelling, and
 * anything in this plugin that treats them as two disagrees with the shop it renders for.
 *
 * Two places did. {@see \Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureTermMatcher} could
 * not retrieve the spaced product for a glued search term, which is how `scale_deep_duplicate`
 * reported a grounding failure that did not exist. And
 * {@see \Swag\AssistantStarterKit\Core\Grounding\ProseProductNames} could not resolve a spaced
 * product from a reply that wrote the glued spelling, which is how the card went missing once
 * retrieval was fixed. Both now normalise through here, so retrieval and rendering cannot disagree
 * about what a name refers to.
 *
 * ## Only across a digit/letter boundary
 *
 * Stripping every space would let `waterbottle` match `Water Bottle` and quietly widen every match
 * in the plugin. This closes the gap where a number meets the unit that follows it and leaves
 * ordinary word boundaries alone — which is the measured case and nothing more.
 */
final class UnitSpacing
{
    private function __construct() {}

    /**
     * The text with `<digit> <letter>` closed up: `750 ml` becomes `750ml`.
     *
     * Idempotent, and safe on text that contains no such boundary — most calls change nothing.
     */
    public static function join(string $text): string
    {
        return (string) preg_replace('/(?<=\d)\s+(?=\p{L})/u', '', $text);
    }
}
