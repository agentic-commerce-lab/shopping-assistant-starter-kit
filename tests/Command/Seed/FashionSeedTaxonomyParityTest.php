<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTaxonomy;
use Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionTaxonomy;

/**
 * `FashionSeedTaxonomy` exists only because `tests/` is not autoloaded inside a running Shopware
 * installation (HANDOFF.md, Task A) — the console command needs the same taxonomy the fixture uses, so
 * it is duplicated into `src/`. This test is what keeps the duplication honest: if the two classes ever
 * diverge, the seeded shop and the fixture eval stop measuring the same catalogue and nobody would know
 * from a green suite alone.
 */
final class FashionSeedTaxonomyParityTest extends TestCase
{
    public function testConstantsAreIdentical(): void
    {
        $fixture = new \ReflectionClass(FashionTaxonomy::class);
        $seed = new \ReflectionClass(FashionSeedTaxonomy::class);

        self::assertSame($fixture->getConstants(), $seed->getConstants());
    }

    public function testEveryGarmentLeafMatches(): void
    {
        self::assertSame(FashionTaxonomy::leafCount(), FashionSeedTaxonomy::leafCount());

        for ($index = 0; $index < FashionTaxonomy::leafCount(); ++$index) {
            self::assertSame(FashionTaxonomy::garmentLeaf($index), FashionSeedTaxonomy::garmentLeaf($index));
        }
    }

    public function testEverySideLeafMatches(): void
    {
        self::assertSame(FashionTaxonomy::sideLeafCount(), FashionSeedTaxonomy::sideLeafCount());

        for ($index = 0; $index < FashionTaxonomy::sideLeafCount(); ++$index) {
            self::assertSame(FashionTaxonomy::sideLeaf($index), FashionSeedTaxonomy::sideLeaf($index));
        }
    }
}
