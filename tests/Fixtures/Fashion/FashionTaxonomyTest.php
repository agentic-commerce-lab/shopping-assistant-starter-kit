<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

use PHPUnit\Framework\TestCase;

/**
 * The taxonomy's three parallel garment lists must stay aligned, and every leaf index must produce a
 * three-segment path.
 *
 * The parallel form exists because mago's analyzer narrows `list<string>` through the bounds-safe
 * `$list[$i] ?? $list[0]` accessor and cannot narrow a nested `array{type, one, many}` shape through
 * it. This file is the cost of that choice, paid once: a misaligned list would otherwise name a
 * product "Maxi Suit" under `Dresses` and nothing would notice.
 */
final class FashionTaxonomyTest extends TestCase
{
    public function testTheThreeGarmentListsAreAligned(): void
    {
        self::assertCount(\count(FashionTaxonomy::GARMENT_TYPES), FashionTaxonomy::GARMENT_SINGULARS);
        self::assertCount(\count(FashionTaxonomy::GARMENT_TYPES), FashionTaxonomy::GARMENT_PLURALS);
    }

    public function testEveryGarmentLeafHasAThreeSegmentPath(): void
    {
        for ($index = 0; $index < FashionTaxonomy::leafCount(); ++$index) {
            self::assertCount(3, FashionTaxonomy::garmentLeaf($index)['path']);
        }
    }

    /** Every leaf index maps to a distinct leaf: that is what makes the node count a constant. */
    public function testEveryLeafIndexIsADistinctLeaf(): void
    {
        $paths = [];

        for ($index = 0; $index < FashionTaxonomy::leafCount(); ++$index) {
            $paths[implode('/', FashionTaxonomy::garmentLeaf($index)['path'])] = true;
        }

        self::assertCount(FashionTaxonomy::leafCount(), $paths);
    }

    public function testEverySideLeafHasATwoSegmentPath(): void
    {
        for ($index = 0; $index < FashionTaxonomy::sideLeafCount(); ++$index) {
            self::assertCount(2, FashionTaxonomy::sideLeaf($index)['path']);
        }
    }
}
