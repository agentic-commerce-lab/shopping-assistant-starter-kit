<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * ProductCard is an ALLOWLIST, not a filtered entity.
 *
 * Shopware products carry fields the shopper must never see — `purchasePrices`
 * above all, plus custom fields holding margin or supplier cost. Because nothing
 * crosses the gateway except this DTO, those fields cannot leak by accident.
 *
 * NEVER widen this class with a passthrough array or a raw-entity property.
 */
final readonly class ProductCard
{
    /**
     * @param array<string, string>   $options      e.g. ['Colour' => 'Blue', 'Size' => 'M']
     * @param list<string>            $categoryPath
     * @param array<string, list<string>> $properties
     */
    // @mago-expect lint:excessive-parameter-list
    // The parameter list mirrors the allowlisted shopper-facing fields exactly (see class
    // docblock); splitting it into a sub-object would defeat the point of a flat allowlist.
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $name,
        public ?string $description,
        public float $price,
        public string $currency,
        public int $stock,
        public StockSource $stockSource,
        public ?string $deliveryTime,
        public string $url,
        public ?string $imageUrl,
        public array $options = [],
        public array $categoryPath = [],
        public array $properties = [],
        /**
         * The quantity the `$price` above assumes.
         *
         * A graduated product priced at its `minPurchase` is quoted honestly only if the quantity
         * that unlocks the figure travels with it. One means "per unit, no minimum worth stating".
         */
        public int $priceQuantity = 1,
        /**
         * Whether Shopware calculated more than one price tier for this product.
         *
         * The card says so rather than implying its figure is the only one, and never computes
         * what the other tiers cost.
         */
        public bool $hasVolumePricing = false,
        /**
         * The smallest quantity Shopware will add to the cart, mirroring `ProductEntity::minPurchase`.
         *
         * Not display copy — it exists so {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway::addToCart()}
         * can reproduce the correction the real cart applies instead of storing whatever it was asked for.
         */
        public int $minPurchase = 1,
        /**
         * The multiple a cart quantity must land on above `$minPurchase`, mirroring
         * `ProductEntity::purchaseSteps`. See {@see self::$minPurchase}.
         */
        public int $purchaseSteps = 1,
    ) {}

    public function isInStock(): bool
    {
        return $this->stock > 0;
    }
}
