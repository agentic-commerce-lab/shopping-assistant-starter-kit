<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * Turns a Shopware product into a {@see ProductCard}, and this is the class that makes the
 * DTO's allowlist real rather than aspirational.
 *
 * A Shopware product carries fields a shopper must never see — `purchasePrices` above all,
 * plus custom fields that routinely hold margin or supplier cost. This mapper reads a fixed
 * set of accessors and nothing else, so those fields cannot leak even by accident. **Never
 * add a passthrough here**, and never widen `ProductCard` to accept one.
 *
 * Two reads are deliberate rather than obvious:
 *
 * 1. **Price comes from the tier that applies, not from `getCalculatedPrice()`.** That accessor
 *    returns the product's own price at quantity one. Rule Builder prices, customer-group prices
 *    expressed as rules, and every volume tier live in `calculatedPrices`, and Shopware's own
 *    storefront prefers that collection whenever it is non-empty — the listing card overrides
 *    `calculatedPrice` with `calculatedPrices.last`, the buy widget takes `calculatedPrices.first`
 *    for a single tier. Until 2026-08-28 this class read `getCalculatedPrice()` unconditionally and
 *    claimed the DAL had "already resolved customer group and rule prices there". It had not, and
 *    every rule-priced product was quoted at a figure its own product page contradicted.
 *    `calculatedPrice` remains the fallback, and is correct only when no advanced price exists.
 * 2. **Translated fields are read through `translated`, not through the own-value getter.** A
 *    variant normally has no name or description of its own — Shopware resolves both from the
 *    parent by inheritance and puts the result in `translated`. `getName()` returns the *own*
 *    value, which is null, so reading it hands the shopper a product with no name. Found by the
 *    first probe against the real catalogue; the fixture gateway copies the parent's name onto
 *    each variant, so no fixture test could ever have shown it.
 * 3. **`$source` is passed in, never inferred.** Whether a card may claim variant-level stock
 *    is the caller's knowledge (it knows whether it fetched a variant or a parent), and
 *    labelling a parent's aggregate stock as `variant` is the defect that cancels orders (D4).
 *    This class will not upgrade the source it is handed.
 * 4. **A card with no resolvable price is not built at all — `map()` returns null.** Every field
 *    on {@see ProductCard} is documented as a fact, and a fabricated 0.0 would be the one this
 *    whole pipeline exists to prevent: a shopper reading "free" or a merchant reading a €0 order.
 *    Mirrors {@see DalCommerceGateway::mapAll()}'s own treatment of a row it cannot map — that
 *    method already skips anything that is not a `SalesChannelProductEntity` rather than
 *    inventing a card for it; a product whose price the calculator never touched gets the same
 *    "not built" treatment, not a different one.
 */
final readonly class DalProductCardMapper
{
    public function __construct(
        private ProductUrlResolver $urls,
        private PropertyGroupOptionReader $options = new PropertyGroupOptionReader(),
        private DalApplicablePrice $prices = new DalApplicablePrice(),
        private DalBundleItems $bundleItems = new DalBundleItems(),
    ) {}

    /**
     * @param string $currency The sales channel's ISO code, passed per call rather than injected.
     *        The currency is a property of the request's sales-channel context, not of this
     *        service: a shop with two channels in different currencies would otherwise have every
     *        card in one of them labelled with the other's code. A wrong currency beside a right
     *        number is a fabricated fact, which is the one thing this pipeline exists to prevent.
     */
    public function map(SalesChannelProductEntity $product, StockSource $source, string $currency): ?ProductCard
    {
        // The smallest order this shopper may actually place. Pricing a case-of-24 product at one
        // unit quotes a figure nobody can buy at; see spec 7.1 for why this differs from the
        // storefront's cheapest-tier "from" price.
        $quantity = max(1, $product->getMinPurchase() ?? 1);
        $tiers = self::tiers($product);
        $applicable = $this->prices->forQuantity($tiers, $quantity);

        $price = $applicable?->getUnitPrice() ?? self::fallbackPrice($product);
        if ($price === null) {
            return null;
        }

        return new ProductCard(
            id: $product->getId(),
            parentId: $product->getParentId(),
            name: $this->inherited($product->getTranslation('name'), $product->getName()) ?? '',
            description: $this->inherited($product->getTranslation('description'), $product->getDescription()),
            price: $price,
            currency: $currency,
            stock: $product->getStock(),
            stockSource: $source,
            deliveryTime: $product->getDeliveryTime()?->getName(),
            url: $this->urls->urlFor($product->getId()),
            imageUrl: $product->getCover()?->getMedia()?->getUrl(),
            options: $this->options->singleValued($product->getOptions()),
            categoryPath: [],
            properties: $this->options->multiValued($product->getProperties()),
            priceQuantity: $quantity,
            // NOT "more than one tier exists" — that let a case-of-24 product with tiers 1-23 /
            // 24+ and `minPurchase = 24` (quoted at the last, cheapest tier) tell a shopper there
            // were lower prices further up, when there are none. True only when `$applicable` is
            // not the collection's last entry, i.e. a cheaper tier still sits above the one quoted.
            // Both are the same `CalculatedPrice` instance when they agree, and `PriceCollection`
            // has no value equality, so this is an identity comparison on purpose.
            hasVolumePricing: $applicable !== $tiers->last(),
            // Same figure as priceQuantity above, under the name the cart-quantity-correction
            // code reads it by (see ProductCard::$minPurchase's own docblock): left at its
            // default of 1 here, a real product with a minimum of 24 would report 1, which is
            // the DTO lying about a field its class docblock calls a fact.
            minPurchase: $quantity,
            purchaseSteps: max(1, $product->getPurchaseSteps() ?? 1),
            // Empty for every product in a shop without Commercial installed, which is most of
            // them; see DalBundleItems for why the read cannot name a Commercial type.
            bundleItems: $this->bundleItems->of($product),
        );
    }

    /**
     * The product's calculated tiers, or an empty collection.
     *
     * `SalesChannelProductEntity::$calculatedPrices` is a non-nullable typed property with **no
     * default**, so reading it on an entity the price calculator never touched throws an `Error`
     * rather than returning null. In the shop that cannot happen — `ProductSubscriber` calculates
     * on every `sales_channel.product.loaded` — but a partially hydrated entity must degrade to
     * "no advanced prices" instead of ending a shopper's turn (rulings R48, R49).
     */
    private static function tiers(SalesChannelProductEntity $product): PriceCollection
    {
        try {
            return $product->getCalculatedPrices();
        } catch (\Error) {
            return new PriceCollection();
        }
    }

    /**
     * The product's own price at quantity one, or null when it cannot be read.
     *
     * `getCalculatedPrice()` has the identical hazard `tiers()` above guards `getCalculatedPrices()`
     * against — a typed property with no default, so reading it before the calculator runs throws
     * an `Error` — but with the realistic failure the other way round.
     * `ProductPriceCalculator::calculateAdvancePrices()` assigns `calculatedPrices` unconditionally
     * (an empty `PriceCollection` on a product with no advanced prices, never an uninitialised one),
     * while `calculatePrice()` returns early when `price` or `taxId` is null, leaving
     * `calculatedPrice` uninitialised while `calculatedPrices` is a valid empty collection — exactly
     * the product that reaches this fallback. Unguarded, that product ended the shopper's whole turn.
     */
    private static function fallbackPrice(SalesChannelProductEntity $product): ?float
    {
        try {
            return $product->getCalculatedPrice()->getUnitPrice();
        } catch (\Error) {
            return null;
        }
    }

    /**
     * Prefers the inheritance-resolved value over the entity's own.
     *
     * `translated` is where the DAL puts a field after resolving parent inheritance and the
     * language chain; the own-value getter returns null for a variant that inherits. Falling back
     * to the own value keeps a product working when no translation was loaded at all.
     */
    private function inherited(mixed $translated, ?string $own): ?string
    {
        return \is_string($translated) && $translated !== '' ? $translated : $own;
    }
}
