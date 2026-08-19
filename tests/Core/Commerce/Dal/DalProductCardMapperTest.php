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
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * The mapper is where the seeded catalogue's three traps are caught or missed. Its assertions
 * are stated against the real seeded product (TRAIL-JERSEY, Blue/M sold out at its own price),
 * so a failure here names a shopper-visible lie rather than a mapping detail.
 */
final class DalProductCardMapperTest extends TestCase
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

    public function testItReadsTheVariantsOwnCalculatedPriceRatherThanARawColumn(): void
    {
        // Four of the seven seeded variants carry no own price and inherit the parent's, so a
        // raw `price` read returns null for them. getCalculatedPrice() is what the DAL has
        // already resolved against inheritance, customer group and rules.
        $card = $this->mapper()->map($this->product(74.90, 0), StockSource::Variant, 'EUR');

        self::assertSame(74.90, $card->price);
        self::assertSame('EUR', $card->currency);
    }

    public function testASoldOutVariantIsReportedAsSoldOutRatherThanOmitted(): void
    {
        // The parent claims available with stock 35 while Blue/M is sold out. Answering
        // "is the blue M in stock?" with the parent's number is the failure D4 exists for.
        $card = $this->mapper()->map($this->product(74.90, 0), StockSource::Variant, 'EUR');

        self::assertSame(0, $card->stock);
        self::assertFalse($card->isInStock());
        self::assertSame(StockSource::Variant, $card->stockSource);
    }

    public function testOptionsAreKeyedByGroupNameSoAVariantQuestionCanBeAnswered(): void
    {
        $card = $this->mapper()->map($this->product(74.90, 0), StockSource::Variant, 'EUR');

        self::assertSame(['Colour' => 'Blue', 'Size' => 'M'], $card->options);
    }

    public function testTheParentIdSurvivesSoVariantResolutionKnowsWhereToLook(): void
    {
        $card = $this->mapper()->map($this->product(79.90, 12), StockSource::Variant, 'EUR');

        self::assertSame(self::PARENT_ID, $card->parentId);
        self::assertSame('/detail/' . self::BLUE_M_ID, $card->url);
    }

    public function testACardMappedFromAParentNeverClaimsVariantStock(): void
    {
        // The conditional that decides this lives in the gateway, but the mapper must not
        // quietly upgrade the source it is handed. Parent aggregate stock labelled
        // `variant` is exactly the claim that cancels orders.
        $parent = $this->product(79.90, 35);
        $parent->setId(self::PARENT_ID);
        $parent->setParentId(null);

        $card = $this->mapper()->map($parent, StockSource::Parent, 'EUR');

        self::assertSame(StockSource::Parent, $card->stockSource);
        self::assertNull($card->parentId);
    }

    public function testAnOptionWithNoResolvedGroupIsDroppedRatherThanKeyedByGuess(): void
    {
        // An unloaded `options.group` association yields null groups. Inventing a key here
        // would hand VariantResolver a group name the catalogue does not have.
        $product = $this->product(74.90, 3);
        $orphan = new PropertyGroupOptionEntity();
        $orphan->setId('cccccccccccccccccccccccccccccccc');
        $orphan->setName('Wide');
        $product->setOptions(new PropertyGroupOptionCollection([$orphan]));

        self::assertSame([], $this->mapper()->map($product, StockSource::Variant, 'EUR')->options);
    }
}
