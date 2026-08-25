<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * @internal counts `facets()` calls so a test can prove the catalogue was NOT asked
 */
final class CountingFacetGateway implements CommerceGatewayInterface
{
    public int $facetCalls = 0;

    public function __construct(
        private readonly CommerceGatewayInterface $inner,
    ) {}

    public function facets(CatalogScope $scope): FacetSet
    {
        ++$this->facetCalls;

        return $this->inner->facets($scope);
    }

    /** @return list<ProductCard> */
    public function search(ProductQuery $query, CatalogScope $scope): array
    {
        return $this->inner->search($query, $scope);
    }

    public function product(string $productId, CatalogScope $scope): ?ProductCard
    {
        return $this->inner->product($productId, $scope);
    }

    /** @param list<VariantSelection> $selections */
    public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
    {
        return $this->inner->resolveVariant($parentId, $selections, $scope);
    }

    public function addToCart(string $variantId, int $quantity): CartSummary
    {
        return $this->inner->addToCart($variantId, $quantity);
    }

    public function cart(): CartSummary
    {
        return $this->inner->cart();
    }
}
