<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval\Filter;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Retrieval\ShopperIntent;

/**
 * Resolves a {@see ShopperIntent}'s `brand` into a `properties.Manufacturer`
 * equals filter, only if the catalog demonstrably has that facet.
 */
final class BrandFilterResolver
{
    private function __construct() {}

    public static function resolve(ShopperIntent $intent, FacetSet $facets): FilterResolution
    {
        if ($intent->brand === null) {
            return FilterResolution::none();
        }

        $field = 'properties.Manufacturer';
        if (!$facets->has($field)) {
            return FilterResolution::dropped($field);
        }

        return FilterResolution::applied(new FilterClause($field, FilterOperator::Equals, $intent->brand));
    }
}
