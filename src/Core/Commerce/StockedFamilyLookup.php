<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;

/**
 * Which of these families still have something a shopper could buy.
 *
 * Its own interface rather than a method on {@see CommerceGatewayInterface}, for the reason
 * {@see BatchProductLookup}, {@see FamilyVariantLookup} and {@see MatchCountReader} all give: a
 * gateway that cannot answer should not be forced to pretend, and the caller degrades to the
 * behaviour it had before the capability existed.
 *
 * **A list in, a list out, one round trip.** The caller holds every family in one result and asks
 * about all of them together. Asking per card would put a query on every product of every search,
 * which is the cost that makes a correctness fix not worth having.
 */
interface StockedFamilyLookup
{
    /**
     * @param list<string> $parentIds
     *
     * @return list<string> those with at least one variant in stock, within `$scope`; order and
     *                      completeness of the input are not promised back, only membership
     */
    public function familiesWithStock(array $parentIds, CatalogScope $scope): array;
}
