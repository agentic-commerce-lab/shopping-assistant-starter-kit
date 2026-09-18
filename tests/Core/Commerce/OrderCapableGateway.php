<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Commerce\OrderHistoryReader;

/**
 * @internal a gateway that implements the published interface AND the optional order capability
 *
 * Its counterpart is {@see LoopOnlyGateway}, which implements only the required interface — wrap one
 * of these in one of those to get "a gateway that cannot read orders" without writing the six
 * catalogue methods a second time.
 *
 * The catalogue half is deliberately empty: every consumer of this double is testing the order path,
 * and a fixture that also returned products would invite a test to depend on both at once.
 */
final class OrderCapableGateway implements CommerceGatewayInterface, OrderHistoryReader
{
    /** @param list<OrderSummary> $orders */
    public function __construct(
        private readonly array $orders = [],
    ) {}

    /** @return list<OrderSummary> */
    public function orders(int $limit): array
    {
        return \array_slice($this->orders, 0, $limit);
    }

    public function facets(CatalogScope $scope): FacetSet
    {
        return new FacetSet();
    }

    /** @return list<ProductCard> */
    public function search(ProductQuery $query, CatalogScope $scope): array
    {
        return [];
    }

    public function product(string $productId, CatalogScope $scope): ?ProductCard
    {
        return null;
    }

    /** @param list<VariantSelection> $selections */
    public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
    {
        return null;
    }

    public function addToCart(string $variantId, int $quantity): CartSummary
    {
        return new CartSummary();
    }

    public function cart(): CartSummary
    {
        return new CartSummary();
    }
}
