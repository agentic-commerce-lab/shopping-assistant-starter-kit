<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * The single seam of this project.
 *
 * @api Public extension point. Only DTOs from Dto\ may cross this boundary —
 *      never a Shopware entity, never SalesChannelContext.
 */
interface CommerceGatewayInterface
{
    public function facets(CatalogScope $scope): FacetSet;

    /** @return list<ProductCard> */
    public function search(ProductQuery $query, CatalogScope $scope): array;

    public function product(string $productId): ?ProductCard;

    /**
     * Resolve a parent product plus chosen options to the concrete variant, with
     * that variant's own price and stock. Returns null when the selection does not
     * identify exactly one variant — never guess.
     *
     * @param list<VariantSelection> $selections
     */
    public function resolveVariant(string $parentId, array $selections): ?ProductCard;

    public function addToCart(string $variantId, int $quantity): CartSummary;

    public function cart(): CartSummary;
}
