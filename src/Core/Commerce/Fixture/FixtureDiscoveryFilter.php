<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * The fixture half of {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalDiscoveryFilters}, which
 * carries the reasoning both halves share.
 *
 * Applied by `search()` and `matchCount()` and by nothing else, exactly as on the DAL side: a lookup
 * by id and a variant resolution must still reach a sold-out unit, or *"is the blue M still
 * available?"* gets answered with *"no such product"*.
 *
 * **Only the stock half exists here.** The unconditional {@see \Shopware\Core\Content\Product\SalesChannel\ProductCloseoutFilter}
 * has no fixture equivalent, because `tests/Fixtures/catalog.json` has no concept of a closeout
 * product — an eval catalogue models sellable units, not the stock policy attached to them. Nothing
 * is lost by that: a closeout product out of stock is unbuyable, and the fixture has no way to
 * describe one in the first place.
 *
 * The `StockSource::Parent` guard is carried anyway, though {@see FixtureIndex} never builds a parent
 * unit today. It is two lines, it says the rule out loud in both gateways, and the alternative is a
 * silent difference waiting for the day the fixture gains families.
 */
final class FixtureDiscoveryFilter
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $units
     *
     * @return list<ProductCard>
     */
    public static function apply(array $units, CatalogScope $scope): array
    {
        if (!$scope->hideOutOfStock) {
            return $units;
        }

        return array_values(array_filter(
            $units,
            static fn(ProductCard $unit): bool => $unit->stockSource === StockSource::Parent || $unit->isInStock(),
        ));
    }
}
