<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval\Filter;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Retrieval\ShopperIntent;

/**
 * Resolves a {@see ShopperIntent}'s `priceMax`/`priceMin` into a single `price`
 * range filter, only if the catalog demonstrably has a `price` facet.
 */
final class PriceFilterResolver
{
    private function __construct() {}

    public static function resolve(ShopperIntent $intent, FacetSet $facets): FilterResolution
    {
        if ($intent->priceMax === null && $intent->priceMin === null) {
            return FilterResolution::none();
        }

        if (!$facets->has('price')) {
            return FilterResolution::dropped('price');
        }

        $range = [];
        if ($intent->priceMax !== null) {
            $range['lte'] = $intent->priceMax;
        }

        if ($intent->priceMin !== null) {
            $range['gte'] = $intent->priceMin;
        }

        return FilterResolution::applied(new FilterClause('price', FilterOperator::Range, $range));
    }
}
