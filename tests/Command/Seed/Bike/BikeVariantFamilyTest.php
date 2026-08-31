<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeVariantFamily;

/**
 * The cross product of a product's variant axes.
 *
 * `SizeFamily` — the fashion seeder's equivalent — walks one axis, because every sized garment there
 * varies by size and nothing else. A bike shop does not work that way: a jersey comes in four sizes
 * AND three colours, which is twelve sellable units, and a rotor comes in three diameters and one
 * colour. Reusing `SizeFamily` would have meant either one axis per product or a second seeder
 * pretending colour was a size.
 */
final class BikeVariantFamilyTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private const OPTION_IDS = [
        'Size' => ['S' => 'id-s', 'M' => 'id-m', 'L' => 'id-l'],
        'Colour' => ['Black' => 'id-black', 'Blue' => 'id-blue'],
    ];

    /**
     * @param array<string, list<string>> $axes
     *
     * @return array{children: list<array<string, mixed>>, configuratorSettings: list<array<string, mixed>>}
     */
    private function family(array $axes, int $stock = 5): array
    {
        return BikeVariantFamily::build('parent-id', 'bk-thing', 49.0, $stock, $axes, self::OPTION_IDS);
    }

    public function testASingleAxisProducesOneVariantPerValue(): void
    {
        $family = $this->family(['Size' => ['S', 'M', 'L']]);

        self::assertCount(3, $family['children']);
    }

    public function testTwoAxesProduceTheFullCrossProduct(): void
    {
        $family = $this->family(['Size' => ['S', 'M', 'L'], 'Colour' => ['Black', 'Blue']]);

        self::assertCount(6, $family['children']);
    }

    public function testEveryVariantCarriesOneOptionPerAxis(): void
    {
        $family = $this->family(['Size' => ['S', 'M'], 'Colour' => ['Black', 'Blue']]);

        foreach ($family['children'] as $child) {
            $options = $child['options'] ?? null;
            self::assertIsArray($options);
            self::assertCount(2, $options);
        }
    }

    public function testEveryVariantNumberAndIdIsDistinct(): void
    {
        $family = $this->family(['Size' => ['S', 'M', 'L'], 'Colour' => ['Black', 'Blue']]);

        $numbers = array_column($family['children'], 'productNumber');
        $ids = array_column($family['children'], 'id');

        self::assertSame(array_values(array_unique($numbers)), $numbers);
        self::assertSame(array_values(array_unique($ids)), $ids);
    }

    /**
     * Shopware needs every option that appears on any child listed once on the parent, or the
     * storefront's variant switcher has nothing to render.
     */
    public function testTheConfiguratorListsEveryOptionUsedExactlyOnce(): void
    {
        $family = $this->family(['Size' => ['S', 'M'], 'Colour' => ['Black', 'Blue']]);

        $optionIds = array_column($family['configuratorSettings'], 'optionId');
        sort($optionIds);

        self::assertSame(['id-black', 'id-blue', 'id-m', 'id-s'], $optionIds);
    }

    /**
     * **A family out of stock has to be genuinely out of stock.** Stock is otherwise spread across
     * the variants so a shopper sees a realistic mix, but a zero on the parent means the whole family
     * is unavailable — which is the case ruling R75 and the `variant_stock` journey are about, and
     * the one a seeder that always sprinkles a little stock everywhere would make untestable.
     */
    public function testAZeroStockParentProducesVariantsThatAreAllOutOfStock(): void
    {
        $family = $this->family(['Size' => ['S', 'M', 'L']], stock: 0);

        self::assertSame([0, 0, 0], array_column($family['children'], 'stock'));
    }

    public function testAStockedParentProducesVariantsThatAreNotAllIdentical(): void
    {
        $family = $this->family(['Size' => ['S', 'M', 'L'], 'Colour' => ['Black', 'Blue']], stock: 9);

        self::assertGreaterThan(1, \count(array_unique(array_column($family['children'], 'stock'))));
    }

    /**
     * Deterministic, like `SeedId` everywhere else in this feature: the same catalogue must produce
     * the same ids on every machine, or a re-seed of a wiped shop is a different shop.
     */
    public function testTheSameInputProducesTheSameIds(): void
    {
        $first = $this->family(['Size' => ['S', 'M']]);
        $second = $this->family(['Size' => ['S', 'M']]);

        self::assertSame(array_column($first['children'], 'id'), array_column($second['children'], 'id'));
    }
}
