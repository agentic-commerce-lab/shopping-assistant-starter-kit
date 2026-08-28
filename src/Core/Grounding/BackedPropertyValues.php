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
 */
final class BackedPropertyValues
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $rendered
     *
     * @return array<string, true> keyed by lowercased value, so a lookup is array_key_exists
     */
    public static function of(array $rendered): array
    {
        $values = [];

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
