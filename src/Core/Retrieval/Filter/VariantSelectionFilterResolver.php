<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval\Filter;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * Resolves one {@see VariantSelection} into a filter.
 *
 * A selection with an explicit `group` targets `properties.{group}` directly; the
 * group name is assumed exact (the model typically echoes it back from a facet
 * listing it was already shown), mirroring the exact-field construction used for
 * `price` and `brand` elsewhere in this package — see {@see TermsFacetFinder} for
 * why the group-less path cannot make the same assumption.
 *
 * A group-less selection (the model knows "blue" but not that it belongs to
 * "Colour") is matched against the first {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\Facet}
 * of type Terms whose values hold that option, case-insensitively — resolution
 * is delegated to {@see TermsFacetFinder}, which also reports the catalog's own
 * spelling of the option, so the emitted filter's value comes from the catalog
 * rather than the model's raw casing.
 */
final class VariantSelectionFilterResolver
{
    private function __construct() {}

    public static function resolve(VariantSelection $selection, FacetSet $facets): FilterResolution
    {
        return $selection->group !== null
            ? self::resolveGrouped($selection, $facets)
            : self::resolveGroupless($selection, $facets);
    }

    private static function resolveGrouped(VariantSelection $selection, FacetSet $facets): FilterResolution
    {
        $field = \sprintf('properties.%s', $selection->group);
        if (!$facets->has($field)) {
            return FilterResolution::dropped($field);
        }

        return FilterResolution::applied(new FilterClause($field, FilterOperator::Equals, $selection->option));
    }

    private static function resolveGroupless(VariantSelection $selection, FacetSet $facets): FilterResolution
    {
        $match = TermsFacetFinder::findContaining($facets, $selection->option);
        if ($match === null) {
            return FilterResolution::dropped(\sprintf('properties.%s', $selection->option));
        }

        return FilterResolution::applied(new FilterClause($match->field, FilterOperator::Equals, $match->value));
    }
}
