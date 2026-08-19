<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
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
 * 2. **`$source` is passed in, never inferred.** Whether a card may claim variant-level stock
 *    is the caller's knowledge (it knows whether it fetched a variant or a parent), and
 *    labelling a parent's aggregate stock as `variant` is the defect that cancels orders (D4).
 *    This class will not upgrade the source it is handed.
 */
final readonly class DalProductCardMapper
{
    public function __construct(
        private ProductUrlResolver $urls,
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
            name: $product->getName() ?? '',
            description: $product->getDescription(),
            price: $product->getCalculatedPrice()->getUnitPrice(),
            currency: $currency,
            stock: $product->getStock(),
            stockSource: $source,
            deliveryTime: $product->getDeliveryTime()?->getName(),
            url: $this->urls->urlFor($product->getId()),
            imageUrl: $product->getCover()?->getMedia()?->getUrl(),
            options: $this->singleValued($product->getOptions()),
            categoryPath: [],
            properties: $this->multiValued($product->getProperties()),
        );
    }

    /**
     * A variant's options: one value per group, e.g. ['Colour' => 'Blue', 'Size' => 'M'].
     *
     * An option whose group association is not loaded has a null group, and an option can
     * have a null name. Either is dropped rather than keyed by a guess — inventing a group
     * name here would hand VariantResolver a group this catalogue does not have, and a
     * fabricated key is worse than a missing one.
     *
     * @return array<string, string>
     */
    private function singleValued(?PropertyGroupOptionCollection $options): array
    {
        $mapped = [];

        foreach ($options ?? [] as $option) {
            $group = $option->getGroup()?->getName();
            $name = $option->getName();

            if ($group === null || $name === null) {
                continue;
            }

            $mapped[$group] = $name;
        }

        return $mapped;
    }

    /**
     * A product's properties: several values per group, e.g. ['Material' => ['Merino', 'Nylon']].
     *
     * @return array<string, list<string>>
     */
    private function multiValued(?PropertyGroupOptionCollection $properties): array
    {
        $mapped = [];

        foreach ($properties ?? [] as $property) {
            $group = $property->getGroup()?->getName();
            $name = $property->getName();

            if ($group === null || $name === null) {
                continue;
            }

            $mapped[$group][] = $name;
        }

        return $mapped;
    }
}
