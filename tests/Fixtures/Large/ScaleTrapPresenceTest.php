<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

use PHPUnit\Framework\TestCase;

/**
 * That each of the four traps is present, and present in the exact shape its journey assumes.
 *
 * Separate from {@see LargeCatalogGeneratorTest} because it answers a different question: that one
 * checks the generator honours its contract, this one checks the engineered defects survived. A trap
 * that quietly stopped being a trap — the sold-out variant in stock, the rare option only the 40th
 * of its group — is the failure mode that would turn four green scale journeys into four that assert
 * nothing (spec decision S5).
 *
 * @phpstan-import-type LargeProduct from LargeCatalogGenerator
 */
final class ScaleTrapPresenceTest extends TestCase
{
    public function testEveryTrapIdIsDistinct(): void
    {
        $ids = [
            ScaleTrap::FAMILY_PARENT,
            ScaleTrap::RARE_OPTION_PRODUCT,
            ScaleTrap::DEEP_DUPLICATE,
            ScaleTrap::FAMILY_SOLD_OUT_VARIANT,
        ];

        self::assertSame($ids, array_values(array_unique($ids)));
    }

    /**
     * The prefix is load-bearing: a journey asserting `rendered_ids_exactly` on a generated id must
     * be able to tell at a glance that the id is a trap rather than one of the twelve real products.
     */
    public function testEveryTrapIdIsPrefixed(): void
    {
        foreach ([ScaleTrap::FAMILY_PARENT, ScaleTrap::RARE_OPTION_PRODUCT, ScaleTrap::DEEP_DUPLICATE] as $id) {
            self::assertStringStartsWith('sc-', $id);
        }
    }

    public function testTheThirtyVariantFamilyEndsWithTheSoldOutUnit(): void
    {
        $family = LargeCatalogQuery::require(LargeCatalogQuery::built(), ScaleTrap::FAMILY_PARENT);

        self::assertCount(30, $family['variants']);

        // Columns rather than `$variants[29]['stock']`: a literal index into a variable-length list
        // is only provably present to a reader, and the coalesced defaults below fail the assertion
        // loudly if the thirtieth variant ever stops being generated.
        $ids = array_column($family['variants'], 'id');
        $stock = array_column($family['variants'], 'stock');

        self::assertSame(ScaleTrap::FAMILY_SOLD_OUT_VARIANT, $ids[29] ?? 'no thirtieth variant');
        self::assertSame(0, $stock[29] ?? -1, 'the trap is that the sold-out unit is ranked last');

        // Every sibling is in stock, so an answer about the sold-out one cannot be right by accident.
        foreach (\array_slice($stock, offset: 0, length: 29) as $sibling) {
            self::assertGreaterThan(0, $sibling);
        }
    }

    public function testTheRareOptionValueSitsBeyondTheFacetBucketCap(): void
    {
        $large = LargeCatalogQuery::built();
        $product = LargeCatalogQuery::require($large, ScaleTrap::RARE_OPTION_PRODUCT);

        self::assertContains(ScaleTrap::RARE_OPTION_VALUE, $product['properties'][ScaleTrap::RARE_OPTION_GROUP] ?? []);

        // More than 50 distinct values in that group, so a 50-bucket aggregation cannot return all.
        $prefix = ScaleTrap::RARE_OPTION_GROUP . '|';
        $inGroup = array_filter(
            array_keys(LargeCatalogQuery::vocabulary($large)['pairs']),
            static fn(string $pair): bool => str_starts_with($pair, $prefix),
        );

        self::assertGreaterThan(50, \count($inGroup));
    }

    public function testTheDeepDuplicateSharesACommonNameAndComesLate(): void
    {
        $large = LargeCatalogQuery::built();
        $ids = array_column($large['products'], 'id');

        $duplicate = LargeCatalogQuery::require($large, ScaleTrap::DEEP_DUPLICATE);
        self::assertSame(ScaleTrap::COMMON_NAME, $duplicate['name']);

        $position = array_search(ScaleTrap::DEEP_DUPLICATE, $ids, strict: true);
        self::assertIsInt($position);
        self::assertGreaterThan(400, $position, 'the trap is that it sits deep in insertion order');
    }

    public function testTheBroadTermIsSharedByHundredsOfProducts(): void
    {
        $matches = 0;

        foreach (LargeCatalogQuery::built()['products'] as $product) {
            if (str_contains($product['name'], ScaleTrap::BROAD_TERM_WORD)) {
                ++$matches;
            }
        }

        self::assertGreaterThan(400, $matches);
    }
}
