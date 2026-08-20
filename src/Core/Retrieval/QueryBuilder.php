<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Retrieval\Filter\BrandFilterResolver;
use Swag\AssistantStarterKit\Core\Retrieval\Filter\FilterResolution;
use Swag\AssistantStarterKit\Core\Retrieval\Filter\PriceFilterResolver;
use Swag\AssistantStarterKit\Core\Retrieval\Filter\VariantSelectionFilterResolver;

/**
 * Enforces the project's central guarantee: the model never supplies a field
 * name. It expresses intent — "under 40 euros", "Shimano", "blue" — and this
 * class decides which catalog field that maps to, choosing only from facets
 * the given {@see FacetSet} demonstrably has. A constraint whose facet does
 * not exist is dropped and recorded in {@see QueryBuildResult::$droppedFields},
 * never invented and never guessed at.
 *
 * Per-constraint resolution is delegated to the resolvers under
 * {@see \Swag\AssistantStarterKit\Core\Retrieval\Filter}, which own the
 * field-mapping rules for that one constraint kind; this class only
 * sequences them and collects filters versus drops.
 */
final class QueryBuilder
{
    public function build(ShopperIntent $intent, FacetSet $facets): QueryBuildResult
    {
        $filters = [];
        $dropped = [];
        $canonicalSelections = [];
        $selectionFilters = [];

        $this->collect(PriceFilterResolver::resolve($intent, $facets), $filters, $dropped);
        $this->collect(BrandFilterResolver::resolve($intent, $facets), $filters, $dropped);

        foreach ($intent->selections as $selection) {
            $resolution = VariantSelectionFilterResolver::resolve($selection, $facets);
            $this->collect($resolution->filter, $filters, $dropped);
            $canonicalSelections[] = $resolution->canonical;

            if ($resolution->filter->filter !== null) {
                $selectionFilters[] = $resolution->filter->filter;
            }
        }

        return new QueryBuildResult(
            new ProductQuery(term: $intent->term, filters: $filters),
            $dropped,
            $canonicalSelections,
            $selectionFilters,
        );
    }

    /**
     * @param list<FilterClause> $filters
     * @param list<string>       $dropped
     */
    private function collect(FilterResolution $resolution, array &$filters, array &$dropped): void
    {
        if ($resolution->filter !== null) {
            $filters[] = $resolution->filter;
        }

        if ($resolution->droppedField !== null) {
            $dropped[] = $resolution->droppedField;
        }
    }
}
