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
     * @param list<BundleItem>        $bundleItems  empty for every ordinary product
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
         * Whether a CHEAPER tier than the one `$price` quotes applies at a higher quantity.
         *
         * Not "Shopware calculated more than one price tier" — a product already quoted at its
         * cheapest tier (the ordinary case when `minPurchase` sits at the top of the ladder) has
         * no lower price to promise, and saying so would be a claim Shopware did not calculate.
         * True only when the tier `$price` came from is not the last one in `calculatedPrices`.
         * The card never computes what that other tier costs.
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
        /**
         * What a Shopware Commercial bundle is made of, in the merchant's own item order.
         *
         * **Not a passthrough, and the class docblock's ban still stands.** This is a list of a
         * typed DTO built field by field from `bundle_item` rows, exactly as `options` and
         * `properties` are built from their own associations — not a raw entity and not an
         * arbitrary array. Nothing about a member product crosses the seam except the three facts
         * {@see BundleItem} names.
         *
         * **Empty is the ordinary case.** `shopware/commercial` is not a dependency of this plugin,
         * so in most shops the `bundleItems` extension does not exist at all; a bundle is the
         * exception and every other product reports `[]`.
         *
         * Its reason for existing is measured: asked what was in a bundle, the assistant answered
         * with a "Gear brush" the catalogue has never contained and a "Chain lube 120 ml" that is
         * 100 ml, while the shop held the exact contents in `bundle_item` all along. The card could
         * not say, so the model said instead.
         */
        public array $bundleItems = [],
    ) {}

    public function isInStock(): bool
    {
        return $this->stock > 0;
    }
}
