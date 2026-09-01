<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\StatedBudget;

/**
 * The measured defect this class exists for, reproduced as a unit test.
 *
 * Measured 2026-09-01 on a Commercial shop: a B2B customer with a +50% Individual Pricing surcharge
 * asked for "jerseys under 100 euros" and was shown three cards at 123.17, 74.85 and 105.32 EUR
 * under the sentence "There are quite a few jerseys under 100 euros". The SQL `RangeFilter('price')`
 * had matched on the **list** price, because Commercial applies individual prices to the loaded
 * entity after the query, so no database column carries them.
 */
final class StatedBudgetTest extends TestCase
{
    private static function card(string $id, float $price): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: $id,
            description: null,
            price: $price,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
            options: [],
            categoryPath: [],
            properties: [],
        );
    }

    /** @param array<string, float|int> $range */
    private static function query(array $range): ProductQuery
    {
        return new ProductQuery(term: 'jersey', filters: [new FilterClause('price', FilterOperator::Range, $range)]);
    }

    public function testItDropsTheCardsThatBrokeTheStatedCeiling(): void
    {
        $kept = StatedBudget::keep(
            [self::card('thermal', 123.17), self::card('trail', 74.85), self::card('club', 105.32)],
            self::query(['lte' => 100]),
        );

        self::assertSame(['trail'], array_map(static fn(ProductCard $c): string => $c->id, $kept));
    }

    public function testItDropsTheCardsBelowAStatedFloor(): void
    {
        $kept = StatedBudget::keep([self::card('cheap', 9.99), self::card('mid', 55.0)], self::query(['gte' => 50]));

        self::assertSame(['mid'], array_map(static fn(ProductCard $c): string => $c->id, $kept));
    }

    public function testBoundsAreInclusiveJustLikeTheRangeFilterTheyMirror(): void
    {
        $kept = StatedBudget::keep([self::card('exact', 100.0)], self::query(['lte' => 100]));

        self::assertCount(1, $kept);
    }

    public function testTheExclusiveOperatorsAreHonouredToo(): void
    {
        self::assertSame([], StatedBudget::keep([self::card('exact', 100.0)], self::query(['lt' => 100])));
        self::assertSame([], StatedBudget::keep([self::card('exact', 100.0)], self::query(['gt' => 100])));
    }

    /**
     * The overwhelmingly common case, and the one that must stay free: with no price constraint
     * there is nothing to enforce, and the list is returned untouched rather than rebuilt.
     */
    public function testAQueryWithoutAPriceClauseIsLeftAlone(): void
    {
        $cards = [self::card('a', 1.0), self::card('b', 2.0)];

        self::assertSame($cards, StatedBudget::keep($cards, new ProductQuery(term: 'jersey')));
    }

    /**
     * A non-price range filter must not be read as a budget. Only `price` is a price.
     */
    public function testAnotherFieldsRangeIsNotABudget(): void
    {
        $cards = [self::card('a', 999.0)];
        $query = new ProductQuery(term: 'jersey', filters: [new FilterClause('weight', FilterOperator::Range, [
            'lte' => 100,
        ])]);

        self::assertSame($cards, StatedBudget::keep($cards, $query));
    }

    /**
     * A card whose price could not be read is kept, not dropped.
     *
     * `DalProductCardMapper::fallbackPrice()` returns null for a product the price calculator never
     * touched, and ProductCard reports that as 0.0. Dropping those against a floor would hide a real
     * product over a defect in its price data; showing it lets the shopper see something is wrong.
     * The ceiling still applies, because 0.0 satisfies every ceiling anyway.
     */
    public function testAZeroPricedCardSurvivesAFloor(): void
    {
        $kept = StatedBudget::keep([self::card('unpriced', 0.0)], self::query(['gte' => 50]));

        self::assertCount(1, $kept);
    }
}
