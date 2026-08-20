<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureTermMatcher;

/**
 * The two-pass term rule, stated directly rather than only through the eval journeys it
 * exists for.
 */
final class FixtureTermMatcherTest extends TestCase
{
    /** @return list<ProductCard> */
    private static function catalogue(): array
    {
        return [
            self::card('fx-017', 'Alloy Bottle Cage', 'Lightweight alloy cage.'),
            self::card('fx-007', 'Alloy Water Bottle 750ml', 'Insulated bottle for long rides.'),
            self::card('fx-026', 'Trail Jersey', 'Lightweight merino trail jersey.'),
        ];
    }

    /**
     * The all-word pass wins when anything matches every word: "bottle cage" must not drag in
     * the water bottle, which is what a plain any-word match would do — and
     * `vocabulary_not_inventory` asserts exactly one product.
     */
    public function testPrefersProductsMatchingEveryWord(): void
    {
        self::assertSame(['fx-017'], self::ids(FixtureTermMatcher::filter(self::catalogue(), 'bottle cage')));
    }

    /**
     * The any-word pass is the whole point: no product mentions "cycling", so the shopper's own
     * words must still reach the jersey through "jersey" alone. Under the old whole-phrase test
     * this returned nothing, which is why `variant_stock · beginner` failed.
     */
    public function testFallsBackToAnyWordWhenNothingMatchesAllOfThem(): void
    {
        self::assertSame(['fx-026'], self::ids(FixtureTermMatcher::filter(self::catalogue(), 'cycling jersey')));
    }

    /** A word the catalogue does not contain at all still matches nothing. */
    public function testAnUnrelatedTermStillMatchesNothing(): void
    {
        self::assertSame([], self::ids(FixtureTermMatcher::filter(self::catalogue(), 'saddlebag')));
    }

    /**
     * Words under the minimum length are ignored, or "in" and "a" — which beginner phrasing is
     * full of — would match inside unrelated words ("riding" contains "in") and the fallback
     * pass would return the whole catalogue.
     */
    public function testShortWordsDoNotMatchInsideUnrelatedWords(): void
    {
        self::assertSame([], self::ids(FixtureTermMatcher::filter(self::catalogue(), 'in a')));
    }

    /** Matching stays case-insensitive, as the whole-phrase test was. */
    public function testMatchingIsCaseInsensitive(): void
    {
        self::assertSame(['fx-026'], self::ids(FixtureTermMatcher::filter(self::catalogue(), 'TRAIL JERSEY')));
    }

    private static function card(string $id, string $name, string $description): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: $name,
            description: $description,
            price: 10.0,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }

    /**
     * @param list<ProductCard> $cards
     *
     * @return list<string>
     */
    private static function ids(array $cards): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $cards);
    }
}
