<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\PriceSort;
use Swag\AssistantStarterKit\Core\Retrieval\SoldOutLast;

/**
 * The bias `DalCriteriaBuilder`'s docblock has always claimed and only `FixtureQueryFilter` ever
 * had: against a real shop a sold-out unit ranked exactly as high as an available one.
 */
final class SoldOutLastTest extends TestCase
{
    /**
     * @param list<ProductCard> $cards
     *
     * @return list<string>
     */
    private static function ids(array $cards): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $cards);
    }

    private static function card(string $id, int $stock, StockSource $source = StockSource::Product): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: $source === StockSource::Variant ? 'parent' : null,
            name: 'Card ' . $id,
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: $stock,
            stockSource: $source,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }

    public function testASoldOutCardSinksBelowAnAvailableOne(): void
    {
        $ordered = SoldOutLast::apply([
            self::card('sold-out', 0),
            self::card('available', 3),
        ], null);

        self::assertSame(['available', 'sold-out'], self::ids($ordered));
    }

    public function testRelevanceOrderSurvivesWithinEachGroup(): void
    {
        // The gateway hands these over in relevance order. Sorting by stock DESCENDING in the
        // query would have reordered by QUANTITY as well, promoting a warehouse full of the
        // least relevant match. Only the sold-out ones move.
        $ordered = SoldOutLast::apply([
            self::card('best', 1),
            self::card('gone', 0),
            self::card('second', 99),
            self::card('also-gone', 0),
            self::card('third', 2),
        ], null);

        self::assertSame(['best', 'second', 'third', 'gone', 'also-gone'], self::ids($ordered));
    }

    public function testAShopperWhoAskedForTheCheapestGetsPriceOrderAndNothingInFrontOfIt(): void
    {
        // Same reasoning FixtureQueryFilter already records: promoting an available unit here
        // answers "the cheapest one that happens to be in stock" to a question about the
        // cheapest. The card still carries `soldOut`, so the reply can say so.
        $ordered = SoldOutLast::apply([
            self::card('cheapest-but-gone', 0),
            self::card('dearer', 5),
        ], PriceSort::Ascending);

        self::assertSame(['cheapest-but-gone', 'dearer'], self::ids($ordered));
    }

    public function testAFamilyParentIsNeverSunkOnItsOwnStockFigure(): void
    {
        // Measured 2026-09-21 against a 118k-row shop: a family parent carries its OWN stock
        // column, not the sum of its children — 259 of 2,900 families read 0 while every
        // variant was in stock. Sinking the parent on that figure buries a buyable product.
        $ordered = SoldOutLast::apply([
            self::card('gone', 0),
            self::card('family', 0, StockSource::Parent),
        ], null);

        self::assertSame(['family', 'gone'], self::ids($ordered));
    }
}
