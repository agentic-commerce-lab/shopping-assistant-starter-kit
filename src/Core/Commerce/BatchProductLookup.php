<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * A gateway that can resolve many product ids in one round trip.
 *
 * **Deliberately a separate interface rather than a method on {@see CommerceGatewayInterface}.** That
 * one is marked `@api Public extension point`, so adding a method to it would break every gateway a
 * merchant has already written — for an optimisation they did not ask for. Implementing this is
 * opt-in, and {@see CardResolver} falls back to a loop of `product()` calls for any gateway that
 * does not.
 *
 * Why it exists: phase B measured the cards endpoint at one catalogue lookup per id — 12 ids, 12
 * lookups, 143.8 ms, which is also why `CardIdList::MAX_IDS` is 12. The endpoint runs when a
 * returning shopper reopens the widget and a stored conversation has to be rehydrated.
 *
 * @see docs/superpowers/reports/2026-08-25-catalogue-scale-phase-b.md — Finding 3
 */
interface BatchProductLookup
{
    /**
     * **Order and completeness are the caller's contract, not the database's.** An implementation may
     * return cards in any order and may omit ids it cannot resolve; {@see CardResolver} restores the
     * requested order and drops the gaps. Implementations must still honour `$scope` exactly as
     * {@see CommerceGatewayInterface::product()} does — a blocked product is refused at lookup, not
     * fetched and filtered later.
     *
     * @param list<string> $productIds
     *
     * @return list<ProductCard>
     */
    public function products(array $productIds, CatalogScope $scope): array;
}
