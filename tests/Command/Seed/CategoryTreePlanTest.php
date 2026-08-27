<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\CategoryTreePlan;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTaxonomy;

/**
 * The node-count arithmetic is measured in the seeder plan's Context section (969 + 57 + 5 = 1,031,
 * excluding the 12 nodes the fixture's twelve real products contribute — this seeder never touches
 * those). This test is what makes "1,031" a fact about the code rather than a claim in a markdown file.
 */
final class CategoryTreePlanTest extends TestCase
{
    private const ROOT = 'root0000000000000000000000000000';

    public function testTotalNodeCountIsOneThousandThirtyOne(): void
    {
        $result = CategoryTreePlan::build(self::ROOT);

        self::assertCount(1_031, $result['idsByPath']);
    }

    public function testEveryGarmentLeafPathIsPresent(): void
    {
        $result = CategoryTreePlan::build(self::ROOT);

        for ($index = 0; $index < FashionSeedTaxonomy::leafCount(); ++$index) {
            $leaf = FashionSeedTaxonomy::garmentLeaf($index);
            self::assertArrayHasKey(implode('/', $leaf['path']), $result['idsByPath']);
        }
    }

    public function testEverySideLeafPathIsPresent(): void
    {
        $result = CategoryTreePlan::build(self::ROOT);

        for ($index = 0; $index < FashionSeedTaxonomy::sideLeafCount(); ++$index) {
            $leaf = FashionSeedTaxonomy::sideLeaf($index);
            self::assertArrayHasKey(implode('/', $leaf['path']), $result['idsByPath']);
        }
    }

    public function testTrapLeavesArePresentUnderTheirExistingTypeNodes(): void
    {
        $result = CategoryTreePlan::build(self::ROOT);

        self::assertArrayHasKey('Women/Occasion & Party/Occasion Dresses', $result['idsByPath']);
        self::assertArrayHasKey('Men/Suits & Tailoring/Occasion Suits', $result['idsByPath']);
        self::assertArrayHasKey('Women/Activewear/Yoga', $result['idsByPath']);
        self::assertArrayHasKey('Gifts & Novelty/Keepsakes', $result['idsByPath']);
    }

    public function testTreeHasSevenTopLevelBranches(): void
    {
        $result = CategoryTreePlan::build(self::ROOT);

        // Women, Men, Kids, Brand, Season, Occasion, Gifts & Novelty.
        self::assertCount(7, $result['tree']);

        foreach ($result['tree'] as $node) {
            self::assertSame(self::ROOT, $node['parentId']);
        }
    }

    public function testIdsAreStableAcrossTwoBuilds(): void
    {
        self::assertSame(
            CategoryTreePlan::build(self::ROOT)['idsByPath'],
            CategoryTreePlan::build(self::ROOT)['idsByPath'],
        );
    }
}
