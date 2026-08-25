<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\BatchProductLookup;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * @internal counts which path the resolver took, so "one call instead of twelve" is asserted rather
 *           than assumed
 */
final class CountingBatchGateway implements CommerceGatewayInterface, BatchProductLookup
{
    public int $batchCalls = 0;

    public int $singleCalls = 0;

    public function __construct(
        private readonly CommerceGatewayInterface $inner,
    ) {}

    /**
     * @param list<string> $productIds
     *
     * @return list<ProductCard>
     */
    public function products(array $productIds, CatalogScope $scope): array
    {
        ++$this->batchCalls;

        $cards = [];
        foreach ($productIds as $id) {
            $card = $this->inner->product($id, $scope);

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    public function facets(CatalogScope $scope): FacetSet
    {
        return $this->inner->facets($scope);
    }

    /** @return list<ProductCard> */
    public function search(ProductQuery $query, CatalogScope $scope): array
    {
        return $this->inner->search($query, $scope);
    }

    public function product(string $productId, CatalogScope $scope): ?ProductCard
    {
        ++$this->singleCalls;

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
