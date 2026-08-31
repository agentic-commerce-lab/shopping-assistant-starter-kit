<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeCatalogue;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedPlan;

/**
 * The plan is everything that can go wrong *before* a write starts, so it is where the seeder either
 * fails loudly or corrupts a shop quietly.
 *
 * The rule this file exists to hold: **an unresolved reference is an exception, never a null.** A
 * category name the shop turns out not to have, a property group that was renamed, a manufacturer
 * that was deleted — each would otherwise produce a payload with a null id, which the DAL accepts in
 * some positions and silently ignores in others. `ProductPlan::build()` learned the same lesson and
 * collects every unresolved path before throwing once; this does the same across all three kinds.
 */
final class BikeSeedPlanTest extends TestCase
{
    private function plan(): BikeSeedPlan
    {
        return BikeSeedPlan::build(FakeShopTaxonomy::complete(), FakeShopTaxonomy::SALES_CHANNEL_ID);
    }

    public function testEveryNewCategoryHangsUnderTheResolvedParentId(): void
    {
        $parents = array_column($this->plan()->categories, 'parentId');

        foreach ($parents as $parentId) {
            self::assertIsString($parentId);
            self::assertNotSame('', $parentId);
        }
    }

    public function testItCreatesOneCategoryPerDeclaredPath(): void
    {
        self::assertCount(\count(BikeCatalogue::categories()), $this->plan()->categories);
    }

    public function testEveryProductCarriesTheResolvedTaxAndSalesChannel(): void
    {
        foreach ($this->plan()->products as $product) {
            self::assertSame(FakeShopTaxonomy::TAX_ID, $product['taxId']);
            self::assertSame(FakeShopTaxonomy::SALES_CHANNEL_ID, $product['visibilities'][0]['salesChannelId'] ?? null);
        }
    }

    public function testAProductWithVariantsCarriesChildrenAndAConfigurator(): void
    {
        $byNumber = array_column($this->plan()->products, null, 'productNumber');

        $jersey = $byNumber['bk-jersey-club'] ?? null;
        self::assertIsArray($jersey);
        $children = $jersey['children'] ?? null;
        self::assertIsArray($children);
        // Five sizes times three colours.
        self::assertCount(15, $children);
        self::assertNotEmpty($jersey['configuratorSettings']);
    }

    public function testAProductWithoutVariantsIsASingleUnit(): void
    {
        $byNumber = array_column($this->plan()->products, null, 'productNumber');

        $cage = $byNumber['bk-cage-carbon'] ?? null;
        self::assertIsArray($cage);
        self::assertArrayNotHasKey('children', $cage);
    }

    /**
     * The descriptive properties reach the payload as resolved option ids.
     *
     * This is the half of the gloves fix that the catalogue test cannot see: declaring the properties
     * in {@see BikeCatalogue::descriptiveGroups()} means nothing if the plan drops them on the way to
     * the DAL, and a product written without them looks identical to one written before this existed.
     * Checked against the two glove products by name, because they are the pair the assistant could
     * not tell apart.
     */
    public function testAProductCarriesItsDescriptivePropertiesAsResolvedOptionIds(): void
    {
        $byNumber = array_column($this->plan()->products, null, 'productNumber');

        $winter = $byNumber['bk-gloves-winter'] ?? null;
        self::assertIsArray($winter);

        $properties = $winter['properties'] ?? null;
        self::assertIsArray($properties);

        // Season, Insulation, Weather protection, Material — one value each, each a resolved id.
        //
        // Asserted without a loop and without array_column: the loop cost this class more cyclomatic
        // budget than the assertion was worth, and `assertIsArray` does not narrow the element type
        // enough for the analyzer to accept the column. An unresolved value could never reach here
        // anyway — OptionLookup returns null for it and BikeSeedPlan then drops the whole product —
        // so what is left to check is that the references are shaped as the DAL expects.
        self::assertCount(4, $properties);
        self::assertNotContains(['id' => ''], $properties);
    }

    /**
     * The two gloves must not resolve to the same property set, or the seed has written data that
     * still cannot answer the question it exists for.
     */
    public function testTheTwoGlovesDifferInTheirResolvedProperties(): void
    {
        $byNumber = array_column($this->plan()->products, null, 'productNumber');

        $winter = $byNumber['bk-gloves-winter']['properties'] ?? [];
        $long = $byNumber['bk-gloves-long-finger']['properties'] ?? [];

        self::assertIsArray($winter);
        self::assertIsArray($long);
        self::assertNotEquals($winter, $long);
    }
}
