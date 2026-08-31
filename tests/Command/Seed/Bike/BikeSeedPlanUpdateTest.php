<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedMode;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedPlan;

/**
 * What changes about the plan when the run is an update rather than a first seed.
 *
 * Split from {@see BikeSeedPlanTest} for mago's per-class complexity budget, and the seam is real:
 * that file asks whether the plan resolves against a shop at all, this one asks what it is allowed to
 * write into a shop it has already been run against.
 */
final class BikeSeedPlanUpdateTest extends TestCase
{
    /**
     * An update plan leaves the variant structure out entirely.
     *
     * **Measured, not anticipated.** The first `--update` against the staging shop died at the first
     * variant product with `Configuration option already exists`:
     * {@see \Swag\AssistantStarterKit\Command\Seed\Bike\BikeVariantFamily} emits
     * `configuratorSettings` as bare `['optionId' => …]` with no `id`, so the DAL mints a fresh uuid
     * for a row that `product_configurator_setting` already holds under its unique
     * (product, option) key. Deriving those ids would not help either — the rows already in the shop
     * carry the random ones from the first seed.
     *
     * `visibilities` is the same shape of problem, found the same way on the next run:
     * `product_visibility` is a real entity with its own id and a unique (product, sales channel) key,
     * so a second write mints an id for a row that is already there.
     *
     * `categories` and `properties` are *not* affected and must keep travelling: they are plain
     * many-to-many mapping tables keyed by the pair itself, with no minted id to collide. That is the
     * line this test draws — everything an update exists for still goes, and only the rows the DAL
     * numbers for us stay behind.
     */
    public function testAnUpdatePlanOmitsEveryStructureWhoseRowsCarryMintedIds(): void
    {
        $plan = BikeSeedPlan::build(
            FakeShopTaxonomy::complete(),
            FakeShopTaxonomy::SALES_CHANNEL_ID,
            true,
            BikeSeedMode::Update,
        );

        $jersey = array_column($plan->products, null, 'productNumber')['bk-jersey-club'] ?? null;
        self::assertIsArray($jersey);

        self::assertArrayNotHasKey('children', $jersey);
        self::assertArrayNotHasKey('configuratorSettings', $jersey);
        self::assertArrayNotHasKey('visibilities', $jersey);

        self::assertArrayHasKey('properties', $jersey);
        self::assertArrayHasKey('categories', $jersey);
        self::assertSame('Club Jersey', $jersey['name']);
    }

    /**
     * A first seed still writes all of it, or nothing would ever create the variants and the products
     * would be invisible in the storefront.
     */
    public function testAFirstSeedPlanStillCarriesEveryStructure(): void
    {
        $plan = BikeSeedPlan::build(FakeShopTaxonomy::complete(), FakeShopTaxonomy::SALES_CHANNEL_ID);

        $jersey = array_column($plan->products, null, 'productNumber')['bk-jersey-club'] ?? null;
        self::assertIsArray($jersey);

        self::assertArrayHasKey('children', $jersey);
        self::assertArrayHasKey('configuratorSettings', $jersey);
        self::assertArrayHasKey('visibilities', $jersey);
    }

    /**
     * A product with no variant axes has no children to omit, but it does have visibilities — so the
     * omission cannot be folded into the existing "no variants" early return.
     */
    public function testAnUpdatePlanOmitsVisibilitiesOnAProductWithoutVariants(): void
    {
        $plan = BikeSeedPlan::build(
            FakeShopTaxonomy::complete(),
            FakeShopTaxonomy::SALES_CHANNEL_ID,
            true,
            BikeSeedMode::Update,
        );

        $cage = array_column($plan->products, null, 'productNumber')['bk-cage-carbon'] ?? null;
        self::assertIsArray($cage);

        self::assertArrayNotHasKey('visibilities', $cage);
        self::assertArrayHasKey('properties', $cage);
    }
}
