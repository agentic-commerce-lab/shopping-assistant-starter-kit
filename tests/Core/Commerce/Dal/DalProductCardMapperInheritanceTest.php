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
 * Split out of {@see DalProductCardMapperTest} (too-many-methods) rather than suppressed.
 *
 * Covers translated fields resolved through parent inheritance — the defect the first probe against
 * the real catalogue found, which no fixture test could have.
 */
final class DalProductCardMapperInheritanceTest extends TestCase
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

    public function testAVariantWithNoOwnNameUsesTheInheritedOneRatherThanAnEmptyString(): void
    {
        // Found by the first probe against the real catalogue, not by a test: in Shopware a
        // variant normally has NO name of its own — the DAL resolves it from the parent into
        // `translated`. Reading getName() returned null for all six seeded variants, so every
        // card rendered a product with no name. The fixture gateway copies the parent's name onto
        // each variant, which is why no fixture test could have caught this.
        $variant = $this->product(74.90, 0);
        $variant->setName(null);
        $variant->setDescription(null);
        $variant->setTranslated(['name' => 'Trail Jersey', 'description' => 'Lightweight jersey.']);

        $card = $this->mapper()->map($variant, StockSource::Variant, 'EUR');

        self::assertSame('Trail Jersey', $card->name);
        self::assertSame('Lightweight jersey.', $card->description);
    }

    public function testAnOwnNameStillWinsWhenNoTranslationWasLoaded(): void
    {
        $product = $this->product(79.90, 35);
        $product->setTranslated([]);

        self::assertSame('Trail Jersey', $this->mapper()->map($product, StockSource::Parent, 'EUR')->name);
    }
}
