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

    /**
     * `$scope` lets an implementation refuse to return a blocked or out-of-scope
     * product at the point of lookup — the same guarantee `search()` already gives,
     * closed here so a direct id lookup (`get_product`, `add_to_cart`) cannot bypass
     * it. Whether an implementation actually enforces `$scope` here is its own
     * choice; callers must still apply their own compliance filtering (e.g.
     * {@see \Swag\AssistantStarterKit\Core\Policy\BlocklistFilter}) rather than
     * assume it did.
     */
    public function product(string $productId, CatalogScope $scope): ?ProductCard;

    /**
     * Resolve a parent product plus chosen options to the concrete variant, with
     * that variant's own price and stock. Returns null when the selection does not
     * identify exactly one variant — never guess.
     *
     * See {@see self::product()} for what `$scope` does and does not guarantee.
     *
     * @param list<VariantSelection> $selections
     */
    public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard;

    public function addToCart(string $variantId, int $quantity): CartSummary;

    public function cart(): CartSummary;
}
