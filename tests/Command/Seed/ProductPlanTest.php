<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Swag\AssistantStarterKit\Command\Seed\CategoryTreePlan;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTraps;
use Swag\AssistantStarterKit\Command\Seed\ProductPlan;
use Swag\AssistantStarterKit\Command\Seed\PropertyGroupPlan;
use Swag\AssistantStarterKit\Command\Seed\SeedId;

/**
 * The counts here are the seeder plan's Context table made executable: 3,617 top-level products,
 * 15,201 sellable units (720 variant-free filler + 2,880×5 sized filler + (6+6+4)×5 sized traps + 1
 * variant-free false friend). A change to either number must come with a change to that table.
 */
final class ProductPlanTest extends TestCase
{
    private const TAX_ID = 'tax00000000000000000000000000000';

    private const SALES_CHANNEL_ID = 'saleschannel0000000000000000000';

    /**
     * @return list<array<string, mixed>>
     */
    private function plan(): array
    {
        $categories = CategoryTreePlan::build('root0000000000000000000000000000')['idsByPath'];
        $properties = PropertyGroupPlan::build();

        return ProductPlan::build(
            $categories,
            $properties['optionIds'],
            $properties['sizeOptionIds'],
            self::TAX_ID,
            self::SALES_CHANNEL_ID,
        );
    }

    public function testProductCountMatchesTheMeasuredConstant(): void
    {
        self::assertSame(ProductPlan::PRODUCT_COUNT, \count($this->plan()));
        self::assertSame(3_617, ProductPlan::PRODUCT_COUNT);
    }

    public function testSellableUnitCountMatchesTheMeasuredConstant(): void
    {
        $units = 0;
        foreach ($this->plan() as $product) {
            /** @var list<array<string, mixed>> $children shape written by `SizeFamily::build()` */
            $children = $product['children'] ?? [];
            $units += $children !== [] ? \count($children) : 1;
        }

        self::assertSame(ProductPlan::SELLABLE_UNITS, $units);
        self::assertSame(15_201, ProductPlan::SELLABLE_UNITS);
    }

    public function testEveryTrapProductIsPresentWithItsFixtureId(): void
    {
        $ids = array_column($this->plan(), 'id');

        foreach (FashionSeedTraps::all() as $trap) {
            self::assertContains(SeedId::forPath('product', $trap['id']), $ids, $trap['id']);
        }
    }

    public function testEveryProductIsAssignedToAResolvedCategory(): void
    {
        foreach ($this->plan() as $product) {
            /** @var list<array{id: string}> $categories shape written by `ProductPlan`/`ProductFillerBuilder` */
            $categories = $product['categories'];
            self::assertNotEmpty($categories);
            /** @var array{id: string} $category every seeded product carries exactly one category, asserted non-empty above */
            $category = $categories[0];
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $category['id']);
        }
    }

    public function testEveryTopLevelProductIsVisibleInTheSelectedSalesChannel(): void
    {
        foreach ($this->plan() as $product) {
            self::assertSame(
                [[
                    'salesChannelId' => self::SALES_CHANNEL_ID,
                    'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
                ]],
                $product['visibilities'] ?? null,
            );
        }
    }

    public function testTheFalseFriendCarriesNoSizeVariants(): void
    {
        $products = $this->plan();
        $falseFriendId = SeedId::forPath('product', FashionSeedTraps::FALSE_FRIEND_ID);
        /** @var array<string, mixed> $falseFriend the false-friend trap is always present in the plan */
        $falseFriend = array_values(array_filter(
            $products,
            static fn(array $p): bool => $p['id'] === $falseFriendId,
        ))[0];

        self::assertArrayNotHasKey('children', $falseFriend);
    }
}
