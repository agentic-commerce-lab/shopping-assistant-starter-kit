<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalProductCardMapper;
use Swag\AssistantStarterKit\Core\Commerce\Dal\ProductUrlResolver;
use Swag\AssistantStarterKit\Core\Commerce\Dal\PropertyGroupOptionOrder;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * Option ORDER on a mapped card, split out of {@see DalProductCardMapperTest}
 * (too-many-methods) rather than suppressed.
 *
 * The order is a separate concern from the mapping itself: the mapper was already
 * correct about WHICH options a variant has, and wrong only about the sequence it
 * presented them in. See {@see PropertyGroupOptionOrder} for the reasoning.
 */
final class DalProductCardMapperOptionOrderTest extends TestCase
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

    private function option(string $id, string $name, string $group): PropertyGroupOptionEntity
    {
        $groupEntity = new PropertyGroupEntity();
        $groupEntity->setId(str_pad($group, 32, '0'));
        $groupEntity->setName($group);

        $option = new PropertyGroupOptionEntity();
        $option->setId($id);
        $option->setName($name);
        $option->setGroup($groupEntity);

        return $option;
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
        $product->setOptions(new PropertyGroupOptionCollection([
            $this->option('b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1', 'Blue', 'Colour'),
            $this->option('5d5d5d5d5d5d5d5d5d5d5d5d5d5d5d5d', 'M', 'Size'),
        ]));

        return $product;
    }

    /**
     * The merchant's own group order decides how a card reads, not the DAL's association
     * order.
     *
     * Both 2026-08-20 handoffs recorded the symptom: a Trail Jersey variant rendered as
     * *"M · Blue"* when a shopper says "blue, size M". The order was whatever the query
     * happened to return, so it was neither wrong nor right — it was arbitrary, and
     * arbitrary is the part a demo shows twice and reads differently each time.
     */
    public function testOptionsFollowTheCataloguesOwnGroupPositionRatherThanAssociationOrder(): void
    {
        $size = $this->option('opt-size-m', 'M', 'Size');
        $size->getGroup()?->setPosition(2);
        $colour = $this->option('opt-colour-blue', 'Blue', 'Colour');
        $colour->getGroup()?->setPosition(1);

        $product = $this->product(74.90, 3);
        // Deliberately the wrong way round on the way in — that is the bug's shape.
        $product->setOptions(new PropertyGroupOptionCollection([$size, $colour]));

        $card = $this->mapper()->map($product, StockSource::Variant, 'EUR');

        self::assertSame(['Colour' => 'Blue', 'Size' => 'M'], $card->options);
    }

    /** An unpositioned group sorts after a positioned one rather than winning by accident. */
    public function testAnUnpositionedGroupSortsAfterAPositionedOne(): void
    {
        $size = $this->option('opt-size-m', 'M', 'Size');
        $size->getGroup()?->setPosition(1);
        $colour = $this->option('opt-colour-blue', 'Blue', 'Colour');

        $product = $this->product(74.90, 3);
        $product->setOptions(new PropertyGroupOptionCollection([$colour, $size]));

        self::assertSame(
            ['Size' => 'M', 'Colour' => 'Blue'],
            $this->mapper()->map($product, StockSource::Variant, 'EUR')->options,
        );
    }
}
