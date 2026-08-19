<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval\Filter;

use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;

/**
 * Finds the first Terms facet whose values hold a given option, case-insensitively,
 * and reports back the catalog's own spelling of that option — never the model's.
 * Used by {@see VariantSelectionFilterResolver} for group-less variant selections,
 * where the model knows the option value but not which property group it belongs to.
 */
final class TermsFacetFinder
{
    private function __construct() {}

    public static function findContaining(FacetSet $facets, string $option): ?TermsFacetMatch
    {
        $needle = strtolower($option);

        foreach ($facets->facets as $facet) {
            if ($facet->type !== FacetType::Terms) {
                continue;
            }

            $canonicalValue = self::matchingValue($facet, $needle);
            if ($canonicalValue !== null) {
                return new TermsFacetMatch($facet->field, $canonicalValue);
            }
        }

        return null;
    }

    private static function matchingValue(Facet $facet, string $lowercasedNeedle): ?string
    {
        foreach ($facet->values as $value) {
            if (strtolower($value) === $lowercasedNeedle) {
                return $value;
            }
        }

        return null;
    }
}
