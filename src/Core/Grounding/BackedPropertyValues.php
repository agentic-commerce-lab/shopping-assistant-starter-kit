<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Every value a reply is entitled to state as a product attribute, lowercased for a
 * case-insensitive lookup — the {@see ProseAudit::unbackedProperties()} analogue of
 * {@see BackedFigures::inCents()}.
 *
 * Checks both `properties` and `options`, not `properties` alone: a real shop's facet layer
 * (`DalFacetReader::GROUPED_AGGREGATIONS`) merges variant option values (Colour, Size) into the same
 * `properties.<Group>` namespace {@see PropertyClaimExtractor} scans, so a genuine option value is a
 * candidate claim {@see PropertyClaimExtractor} can and will extract. A value the shopper introduced
 * themselves is exempted separately, directly in {@see ProseAudit::unbackedProperties()}, because
 * that check needs the original claimed string rather than a pre-built set.
 *
 * **`$disclosed` is the fourth source of entitlement, and it carries no card.** Retrieval is not the
 * only way the shop hands the model option values: `search_products` returns a `families` block for a
 * truncated family, and the viewing line names the open product's whole family. Both are deliberate
 * disclosures of values the shop itself chose to state, so a reply repeating one is repeating the
 * shop — see {@see DisclosedOptions} for where they are read from, and
 * {@see \Swag\AssistantStarterKit\Tests\Core\Grounding\PropertyClaimsMeasuredAgainstDisclosedTest}
 * for the live turn that made it necessary.
 *
 * **Known limit, deliberately not fixed here:** this set is flat, with no notion of which product a
 * value belongs to. Two variants of two different families contribute to one pool, so an unrelated
 * product can back a claim by coincidence. Attributing each claim to a product means teaching
 * {@see PropertyClaimExtractor} which product a sentence is about, which is a much larger change.
 */
final class BackedPropertyValues
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $rendered
     * @param list<string>      $disclosed option values the shop stated to the model without a card
     *                                     behind them, from {@see DisclosedOptions::from()}
     *
     * @return array<string, true> keyed by lowercased value, so a lookup is array_key_exists
     */
    public static function of(array $rendered, array $disclosed = []): array
    {
        $values = [];

        // Lowercased on the way in, exactly like a card's own values: a disclosure must not be the
        // one source that only works when the model echoes the catalogue's precise casing.
        foreach ($disclosed as $value) {
            if ($value !== '') {
                $values[mb_strtolower($value)] = true;
            }
        }

        foreach ($rendered as $card) {
            foreach ($card->properties as $groupValues) {
                foreach ($groupValues as $value) {
                    $values[mb_strtolower($value)] = true;
                }
            }

            foreach ($card->options as $value) {
                $values[mb_strtolower($value)] = true;
            }
        }

        return $values;
    }
}
