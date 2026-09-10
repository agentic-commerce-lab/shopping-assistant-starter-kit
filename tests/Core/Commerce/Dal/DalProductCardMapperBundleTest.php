<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalProductCardMapper;
use Swag\AssistantStarterKit\Core\Commerce\Dal\ProductUrlResolver;
use Swag\AssistantStarterKit\Core\Commerce\Dto\BundleItem;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * A bundle's composition, read off the product the way Shopware Commercial leaves it.
 *
 * **Every entity here is a core class on purpose.** `shopware/commercial` is not a dependency of
 * this plugin (see `composer.json`), so nothing in `src/` may name `BundleItemEntity`,
 * `BundleItemCollection` or any other of its types — and nothing in `tests/` can either. Commercial
 * registers `bundleItems` through an `EntityExtension`, which lands in the entity's `extensions`
 * bag, so the only contract this plugin may rely on is "an iterable of entities answering to
 * `get('quantity')`, `get('required')` and `get('product')`". `ArrayEntity` satisfies exactly that
 * contract and nothing more, which is why the fixtures below are built from it: a test that used
 * Commercial's own classes would pass against a shape this plugin is not allowed to assume.
 */
final class DalProductCardMapperBundleTest extends TestCase
{
    private const BUNDLE_ID = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    private function mapper(): DalProductCardMapper
    {
        return new DalProductCardMapper(new class implements ProductUrlResolver {
            public function urlFor(string $productId): string
            {
                return '/detail/' . $productId;
            }
        });
    }

    /**
     * One `bundle_item` row as the DAL hydrates it: the member product plus the bundle's own
     * quantity and required flag.
     */
    private function item(string $name, int $quantity, bool $required): ArrayEntity
    {
        $member = new ProductEntity();
        $member->setId(str_pad(substr(md5($name), 0, 32), 32, '0'));
        $member->setName($name);

        return new ArrayEntity([
            // EntityCollection keys its elements by unique identifier, so a row without an id
            // makes the collection itself warn — nothing to do with what is under test.
            'id' => $member->getId(),
            'quantity' => $quantity,
            'required' => $required,
            'product' => $member,
        ]);
    }

    private function bundle(ArrayEntity ...$items): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId(self::BUNDLE_ID);
        $product->setName('Roadside Repair Kit');
        $product->setStock(19);
        $product->setCalculatedPrice(
            new CalculatedPrice(73.08, 73.08, new CalculatedTaxCollection(), new TaxRuleCollection()),
        );

        if ($items !== []) {
            $product->addExtension('bundleItems', new EntityCollection($items));
        }

        return $product;
    }

    public function testItReadsEachBundleItemsNameQuantityAndRequiredFlag(): void
    {
        // The shop knows exactly what is in the kit; before this the card carried a name, a price
        // and nothing about composition, so "what is in it?" could only be answered from priors.
        $card = $this->mapper()->map(
            $this->bundle($this->item('Mini Pump 120psi', 1, true), $this->item('Inner Tube Presta 700c', 2, true)),
            StockSource::Product,
            'EUR',
        );

        self::assertSame(
            [
                ['name' => 'Mini Pump 120psi', 'quantity' => 1, 'required' => true],
                ['name' => 'Inner Tube Presta 700c', 'quantity' => 2, 'required' => true],
            ],
            self::readable($card),
        );
    }

    public function testItKeepsAnOptionalItemDistinctFromARequiredOne(): void
    {
        // Commercial sums EVERY item into the bundle price but derives stock from the required ones
        // only, so a card that cannot tell them apart quotes a maximum as if it were the price.
        $card = $this->mapper()->map(
            $this->bundle($this->item('Dry Chain Lube 100ml', 1, true), $this->item('Bike Wash 1L', 1, false)),
            StockSource::Product,
            'EUR',
        );

        self::assertSame(
            [
                ['name' => 'Dry Chain Lube 100ml', 'quantity' => 1, 'required' => true],
                ['name' => 'Bike Wash 1L', 'quantity' => 1, 'required' => false],
            ],
            self::readable($card),
        );
    }

    public function testAnOrdinaryProductCarriesNoBundleItems(): void
    {
        // The extension is absent in every shop without Commercial installed, which is the
        // ordinary case: an empty list, never a null to be dereferenced downstream.
        $card = $this->mapper()->map($this->bundle(), StockSource::Product, 'EUR');

        self::assertSame([], self::readable($card));
    }

    /**
     * The card's bundle items as plain arrays, so one assertion covers every field of every item.
     *
     * Comparing whole structures rather than indexing into the list is what makes a missing item
     * fail loudly instead of turning the next assertion into an access on nothing.
     *
     * @return list<array{name: string, quantity: int, required: bool}>
     */
    private static function readable(?ProductCard $card): array
    {
        self::assertInstanceOf(ProductCard::class, $card, 'map() must return a card for a priced product.');

        return array_map(static fn(BundleItem $item): array => [
            'name' => $item->name,
            'quantity' => $item->quantity,
            'required' => $item->required,
        ], $card->bundleItems);
    }
}
