<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval\Filter;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * Resolves one {@see VariantSelection} into a filter, AND into the same selection
 * re-expressed with the catalog's own spelling of its option value (Ruling R20
 * covered the filter for the group-less path only; this now covers both paths and
 * feeds {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver} too — see
 * {@see VariantSelectionResolution}).
 *
 * A selection with an explicit `group` targets `properties.{group}` directly; the
 * group name is assumed exact (the model typically echoes it back from a facet
 * listing it was already shown), mirroring the exact-field construction used for
 * `price` and `brand` elsewhere in this package. The option VALUE is not assumed
 * exact even here: it is matched against that one facet's own values,
 * case-insensitively, via {@see TermsFacetFinder::matchInFacet()} — the same
 * canonicalisation the group-less path already gets, just scoped to the one facet
 * the group already names instead of searching every facet.
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

    public static function resolve(VariantSelection $selection, FacetSet $facets): VariantSelectionResolution
    {
        return $selection->group !== null
            ? self::resolveGrouped($selection, $facets)
            : self::resolveGroupless($selection, $facets);
    }

    private static function resolveGrouped(VariantSelection $selection, FacetSet $facets): VariantSelectionResolution
    {
        $field = \sprintf('properties.%s', $selection->group);
        $facet = $facets->get($field);
        if ($facet === null) {
            return new VariantSelectionResolution(FilterResolution::dropped($field), $selection);
        }

        $canonicalValue = TermsFacetFinder::matchInFacet($facet, $selection->option) ?? $selection->option;

        return new VariantSelectionResolution(
            FilterResolution::applied(new FilterClause($field, FilterOperator::Equals, $canonicalValue)),
            new VariantSelection($canonicalValue, $selection->group),
        );
    }

    private static function resolveGroupless(VariantSelection $selection, FacetSet $facets): VariantSelectionResolution
    {
        $match = TermsFacetFinder::findContaining($facets, $selection->option);
        if ($match === null) {
            return new VariantSelectionResolution(
                FilterResolution::dropped(\sprintf('properties.%s', $selection->option)),
                $selection,
            );
        }

        return new VariantSelectionResolution(
            FilterResolution::applied(new FilterClause($match->field, FilterOperator::Equals, $match->value)),
            new VariantSelection($match->value, self::groupFromField($match->field)),
        );
    }

    /**
     * `$match->field` is `properties.{group}` for every property facet (see
     * {@see \Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureFacetBuilder}), but
     * a group-less option can also match the unrelated `categoryPath` Terms facet —
     * that is not a property group, so no group is attributed in that case.
     */
    private static function groupFromField(string $field): ?string
    {
        return str_starts_with($field, 'properties.') ? substr($field, \strlen('properties.')) : null;
    }
}
