<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * The fixture half of {@see \Swag\AssistantStarterKit\Core\Commerce\StockedFamilyLookup}.
 *
 * Its own class rather than a method on {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway},
 * for the reason {@see FixtureDiscoveryFilter} is one: that gateway is measured against a
 * cyclomatic-complexity budget summed across its methods, and a rule with three conditions in it
 * pushed it over. The seam is real either way — deciding which families are buyable and serving a
 * catalogue fail for different reasons.
 *
 * The units it reads are already scope-filtered by the caller, so a family held up **only** by
 * blocked variants counts as unbuyable: a blocked variant is not stock the assistant may offer.
 * `FixtureIndex` never builds a parent unit, so only children are ever counted — which is exactly
 * the question being asked.
 */
final class FixtureStockedFamilies
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $units already scope-filtered
     * @param list<string>      $parentIds
     *
     * @return list<string>
     */
    public static function of(array $units, array $parentIds): array
    {
        $stocked = array_filter(
            array_map(static fn(ProductCard $unit): ?string => $unit->isInStock() ? $unit->parentId : null, $units),
            static fn(?string $parentId): bool => $parentId !== null,
        );

        return array_values(array_unique(array_intersect($stocked, $parentIds)));
    }
}
