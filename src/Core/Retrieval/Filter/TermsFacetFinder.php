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
        foreach ($facets->facets as $facet) {
            if ($facet->type !== FacetType::Terms) {
                continue;
            }

            $canonicalValue = self::matchInFacet($facet, $option);
            if ($canonicalValue !== null) {
                return new TermsFacetMatch($facet->field, $canonicalValue);
            }
        }

        return null;
    }

    /**
     * Matches `$option` against one specific facet's own values, case-insensitively,
     * and reports back the catalog's own spelling. Public so {@see \Swag\AssistantStarterKit\Core\Retrieval\Filter\VariantSelectionFilterResolver}
     * can canonicalise a GROUPED selection's value the same way this class already
     * canonicalises a group-less one — the group is already known there, so there is
     * no need to search every facet, only the one the group names.
     */
    public static function matchInFacet(Facet $facet, string $option): ?string
    {
        $needle = strtolower($option);

        foreach ($facet->values as $value) {
            if (strtolower($value) === $needle) {
                return $value;
            }
        }

        return null;
    }
}
