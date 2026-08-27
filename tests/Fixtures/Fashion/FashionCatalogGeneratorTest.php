<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;

/**
 * The generator's contract: the verbatim property, the scale counts, determinism, and that the
 * gateway can actually read what comes out.
 *
 * The generator is test infrastructure, and test infrastructure that is wrong produces a green run
 * that means nothing — spec decision O11 chose a generator over a committed blob precisely so this
 * file could exist.
 *
 * The four traps' own shapes are asserted next door in {@see FashionTrapPresenceTest}.
 *
 * @phpstan-import-type FashionCatalogue from FashionCatalogGenerator
 */
final class FashionCatalogGeneratorTest extends TestCase
{
    /**
     * Spec decision O10, and the most important assertion in this file.
     *
     * Verbatim includes `categoryPath`. An earlier draft of the plan re-pathed these twelve under a
     * `Sport` department, which would have deleted the category names `Jerseys` and `Gloves` — and
     * `page_context_not_a_cage` stands the shopper in `Jerseys` and expects the Commuter Glove out of
     * `Apparel > Gloves`. `FixtureCategoryFilter` matches on those names, so a path is not decoration.
     */
    public function testAllTwelveOriginalProductsSurviveVerbatim(): void
    {
        // The committed fixture is trusted input, so its shape is declared rather than validated: if
        // it stopped matching, every other test here would fail first and more loudly.
        /** @var FashionCatalogue $small */
        $small = json_decode(
            (string) file_get_contents(FashionCatalogQuery::smallCatalogPath()),
            associative: true,
            depth: 512,
            flags: \JSON_THROW_ON_ERROR,
        );
        $fashion = FashionCatalogQuery::built();

        self::assertCount(12, $small['products']);

        foreach ($small['products'] as $original) {
            self::assertSame(
                $original,
                FashionCatalogQuery::find($fashion, $original['id']),
                \sprintf('product %s changed', $original['id']),
            );
        }
    }

    /** They are emitted first, so their insertion order is unchanged too. */
    public function testTheRealProductsComeFirstAndInOrder(): void
    {
        $ids = array_map(
            static fn(array $product): string => $product['id'],
            \array_slice(FashionCatalogQuery::built()['products'], offset: 0, length: 12),
        );

        self::assertSame(
            [
                'fx-001',
                'fx-004',
                'fx-007',
                'fx-008',
                'fx-011',
                'fx-014',
                'fx-017',
                'fx-019',
                'fx-021',
                'fx-026',
                'fx-030',
                'fx-031',
            ],
            $ids,
        );
    }

    /** Spec decision O12: the counts are the contract, because crossing them is the point. */
    public function testItProducesTheDocumentedNumberOfSellableUnits(): void
    {
        self::assertSame(
            FashionCatalogGenerator::SELLABLE_UNITS,
            FashionCatalogQuery::sellableUnits(FashionCatalogQuery::built()),
        );
    }

    public function testItProducesAtLeastAThousandCategoryNodes(): void
    {
        $nodes = FashionCatalogQuery::categoryNodes(FashionCatalogQuery::built());

        self::assertGreaterThanOrEqual(1_000, \count($nodes));
        self::assertSame(FashionCatalogGenerator::CATEGORY_NODES, \count($nodes));
    }

    /**
     * A fixture that differs between runs turns a red eval into a coin toss, which is the whole
     * reason the sequence is a seeded LCG rather than `random_int()`.
     */
    public function testItIsDeterministicForOneSeed(): void
    {
        self::assertSame(FashionCatalogQuery::generator()->toJson(), FashionCatalogQuery::generator()->toJson());
    }

    public function testADifferentSeedProducesADifferentCatalogue(): void
    {
        self::assertNotSame(
            FashionCatalogQuery::generator()->toJson(),
            FashionCatalogQuery::generator(seed: 1)->toJson(),
        );
    }

    /**
     * The generator's output is only useful if the gateway can read it, and nothing else in this
     * directory checks that the two agree about the fixture's shape.
     */
    public function testTheGatewayCanSearchWhatTheGeneratorProduced(): void
    {
        $path = sys_get_temp_dir() . '/fashion-catalogue-generator-test.json';

        try {
            file_put_contents($path, FashionCatalogQuery::generator()->toJson());
            $gateway = FixtureCommerceGateway::fromFile($path);

            $found = $gateway->search(new ProductQuery(term: 'Trail Jersey', limit: 5), new CatalogScope());

            self::assertNotSame([], $found);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
