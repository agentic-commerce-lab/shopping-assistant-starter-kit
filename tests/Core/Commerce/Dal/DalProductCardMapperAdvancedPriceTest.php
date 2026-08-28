<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalProductCardMapper;
use Swag\AssistantStarterKit\Core\Commerce\Dal\ProductUrlResolver;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * Split out of {@see DalProductCardMapperTest} (too-many-methods) rather than suppressed.
 *
 * Covers `calculatedPrices` overriding `calculatedPrice` — the defect this whole task exists
 * to fix. Measured against a real shop, six of six tier-priced products were quoted at a price
 * their own product page contradicted; see spec 7.1 for the minPurchase quantity rule.
 */
final class DalProductCardMapperAdvancedPriceTest extends TestCase
{
    private const PARENT_ID = 'fafafafafafafafafafafafafafafafa';
    private const BLUE_M_ID = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    private function mapper(): DalProductCardMapper
    {
        return new DalProductCardMapper(new class implements ProductUrlResolver {
            public function urlFor(string $productId): string
            {
                return '/detail/' . $productId;
            }
        });
    }

    private function product(float $unitPrice, int $stock): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId(self::BLUE_M_ID);
        $product->setParentId(self::PARENT_ID);
        $product->setName('Trail Jersey');
        $product->setDescription('Lightweight long-sleeve jersey for trail riding.');
        $product->setStock($stock);
        $product->setCalculatedPrice(
            new CalculatedPrice($unitPrice, $unitPrice, new CalculatedTaxCollection(), new TaxRuleCollection()),
        );

        return $product;
    }

    /**
     * @param list<array{float, int}> $tiers unit price and the tier's stored quantity
     */
    private function withTiers(
        SalesChannelProductEntity $product,
        array $tiers,
        ?int $minPurchase = null,
    ): SalesChannelProductEntity {
        $product->setMinPurchase($minPurchase);
        $product->setCalculatedPrices(new PriceCollection(array_map(
            static fn(array $tier): CalculatedPrice => new CalculatedPrice(
                $tier[0],
                $tier[0] * $tier[1],
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
                $tier[1],
            ),
            $tiers,
        )));

        return $product;
    }

    public function testAnAdvancedPriceBeatsTheProductsOwnCalculatedPrice(): void
    {
        // The defect this test exists for: `calculatedPrice` is the product's own price at
        // quantity one, and Shopware's listing card and buy widget both override it whenever
        // `calculatedPrices` has anything in it. Quoting 79.90 beside a product page that says
        // 59.90 is the assistant contradicting the shop it lives in.
        $product = $this->withTiers($this->product(79.90, 12), [[59.90, 1]]);

        $card = $this->mapper()->map($product, StockSource::Variant, 'EUR');

        self::assertSame(59.90, $card->price);
        self::assertSame(1, $card->priceQuantity);
        self::assertFalse($card->hasVolumePricing);
    }

    public function testWithNoStatedQuantityTheCardPricesTheSmallestOrderTheShopperMayPlace(): void
    {
        // minPurchase 24 with tiers 1-23 / 24+: pricing this at one unit quotes a price no
        // shopper can buy at. The card states the quantity it assumes so the figure is not
        // stated bare — see spec 7.1, the one place this deliberately differs from the
        // storefront's cheapest-tier "from" price.
        $product = $this->withTiers($this->product(79.90, 500), [[79.90, 23], [70.00, 24]], 24);

        $card = $this->mapper()->map($product, StockSource::Variant, 'EUR');

        self::assertSame(70.00, $card->price);
        self::assertSame(24, $card->priceQuantity);
        self::assertTrue($card->hasVolumePricing);
    }

    public function testAProductWithNoAdvancedPricesKeepsItsOwnCalculatedPrice(): void
    {
        // The ordinary shop, and the reason the fallback stays: a product with no rule prices
        // has an empty `calculatedPrices`, and the only figure Shopware calculated for it is
        // `calculatedPrice`.
        $card = $this->mapper()->map($this->product(74.90, 3), StockSource::Variant, 'EUR');

        self::assertSame(74.90, $card->price);
        self::assertSame(1, $card->priceQuantity);
        self::assertFalse($card->hasVolumePricing);
    }
}
