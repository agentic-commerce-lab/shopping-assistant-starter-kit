<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval\Filter;

use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;

/**
 * Finds the first Terms facet whose values hold a given option, case-insensitively.
 * Used by {@see VariantSelectionFilterResolver} for group-less variant selections,
 * where the model knows the option value but not which property group it belongs to.
 */
final class TermsFacetFinder
{
    private function __construct() {}

    public static function findContaining(FacetSet $facets, string $option): ?Facet
    {
        $needle = strtolower($option);

        foreach ($facets->facets as $facet) {
            if ($facet->type === FacetType::Terms && self::hasValue($facet, $needle)) {
                return $facet;
            }
        }

        return null;
    }

    private static function hasValue(Facet $facet, string $lowercasedNeedle): bool
    {
        foreach ($facet->values as $value) {
            if (strtolower($value) === $lowercasedNeedle) {
                return true;
            }
        }

        return false;
    }
}
