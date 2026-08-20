<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

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
 * 1. **Price comes from `getCalculatedPrice()`, never a raw price column.** The DAL has already
 *    resolved inheritance, customer group and rule prices there. Four of the seven seeded
 *    TRAIL-JERSEY variants carry no own price and inherit the parent's, so a raw read returns
 *    null for them — and a null price in front of a shopper is the failure this whole project
 *    is about.
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
 */
final readonly class DalProductCardMapper
{
    public function __construct(
        private ProductUrlResolver $urls,
        private PropertyGroupOptionReader $options = new PropertyGroupOptionReader(),
    ) {}

    /**
     * @param string $currency The sales channel's ISO code, passed per call rather than injected.
     *        The currency is a property of the request's sales-channel context, not of this
     *        service: a shop with two channels in different currencies would otherwise have every
     *        card in one of them labelled with the other's code. A wrong currency beside a right
     *        number is a fabricated fact, which is the one thing this pipeline exists to prevent.
     */
    public function map(SalesChannelProductEntity $product, StockSource $source, string $currency): ProductCard
    {
        return new ProductCard(
            id: $product->getId(),
            parentId: $product->getParentId(),
            name: $this->inherited($product->getTranslation('name'), $product->getName()) ?? '',
            description: $this->inherited($product->getTranslation('description'), $product->getDescription()),
            price: $product->getCalculatedPrice()->getUnitPrice(),
            currency: $currency,
            stock: $product->getStock(),
            stockSource: $source,
            deliveryTime: $product->getDeliveryTime()?->getName(),
            url: $this->urls->urlFor($product->getId()),
            imageUrl: $product->getCover()?->getMedia()?->getUrl(),
            options: $this->options->singleValued($product->getOptions()),
            categoryPath: [],
            properties: $this->options->multiValued($product->getProperties()),
        );
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
