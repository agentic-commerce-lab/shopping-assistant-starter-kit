<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\TruncatedFamilies;

/**
 * The case a candidate window cannot describe: a family bigger than what retrieval fetched.
 *
 * Measured before this existed — asked for `Size 30` of a thirty-variant family at the model's
 * default `limit: 5`, the window was 20, so the disclosure listed `Size 1` … `Size 20` and the one
 * value the shopper had named was unknowable. This is the test that pins the fix: given the family's
 * own variants, the summary describes the family, not the window.
 */
final class TruncatedFamiliesFullFamilyTest extends TestCase
{
    /** @param array<string, string> $options */
    private static function variant(string $id, array $options): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: 'sc-family-30',
            name: 'Endurance Bib Tights',
            description: null,
            price: 99.0,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
            options: $options,
        );
    }

    /** @return list<ProductCard> */
    private static function family(int $size): array
    {
        $variants = [];

        for ($i = 1; $i <= $size; ++$i) {
            $variants[] = self::variant(\sprintf('sc-family-30-v%d', $i), ['Size' => 'Size ' . $i]);
        }

        return $variants;
    }

    public function testTheWindowAloneCannotNameAValueItNeverFetched(): void
    {
        // What the old code had: 20 of 30 variants, because that is the candidate window.
        $window = self::family(20);

        $families = TruncatedFamilies::of($window, \array_slice($window, offset: 0, length: 5));

        self::assertSame(20, $families[0]['variants']);
        self::assertNotContains('Size 30', $families[0]['options']['Size'] ?? []);
    }

    /** Given the family itself, the same call describes all thirty. */
    public function testTheFullFamilyOverridesTheWindow(): void
    {
        $window = self::family(20);
        $whole = self::family(30);

        $families = TruncatedFamilies::of($window, \array_slice($window, offset: 0, length: 5), [
            'sc-family-30' => $whole,
        ]);

        self::assertSame(30, $families[0]['variants']);
        self::assertSame(5, $families[0]['shown']);
        self::assertContains('Size 30', $families[0]['options']['Size'] ?? []);
    }

    /** A family the lookup could not answer for falls back to the window rather than vanishing. */
    public function testAnUnansweredLookupFallsBackToTheWindow(): void
    {
        $window = self::family(20);

        $families = TruncatedFamilies::of($window, \array_slice($window, offset: 0, length: 5), ['sc-family-30' => []]);

        self::assertSame(20, $families[0]['variants']);
    }

    public function testTruncatedParentIdsNamesOnlyTheFamiliesThatLostMembers(): void
    {
        $window = self::family(20);

        self::assertSame(
            ['sc-family-30'],
            TruncatedFamilies::truncatedParentIds($window, \array_slice($window, offset: 0, length: 5)),
        );
        self::assertSame([], TruncatedFamilies::truncatedParentIds($window, $window));
    }
}
