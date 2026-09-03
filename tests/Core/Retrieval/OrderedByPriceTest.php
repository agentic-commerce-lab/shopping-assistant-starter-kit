<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\OrderedByPrice;
use Swag\AssistantStarterKit\Core\Retrieval\PriceSort;

/**
 * The database threw the ordering away, so it is put back here.
 *
 * Measured 2026-09-03 against a real shop with `sort: price_asc` proven present in the trace: the
 * candidate window came back 37.58 / 267.58 / 33.10 / 118.51 / 42.48. `DalCriteriaBuilder` adds the
 * `FieldSorting` and also calls `Criteria::setTerm()`, and Shopware then sorts by `_score` for the
 * term. `PriceSort` therefore never worked against the DAL — only against `FixtureCommerceGateway`,
 * which sorts in PHP, which is why `SearchPriceSortTest` stayed green while a shopper saw a wrong
 * superlative.
 */
final class OrderedByPriceTest extends TestCase
{
    public function testTheMeasuredWindowIsPutBackInOrder(): void
    {
        $cards = $this->cards([37.58, 267.58, 33.10, 118.51, 42.48]);

        $ordered = OrderedByPrice::apply($cards, $this->query(PriceSort::Ascending));

        self::assertSame([33.10, 37.58, 42.48, 118.51, 267.58], $this->prices($ordered));
    }

    public function testDescendingIsTheOtherDirection(): void
    {
        $ordered = OrderedByPrice::apply($this->cards([37.58, 267.58, 33.10]), $this->query(PriceSort::Descending));

        self::assertSame([267.58, 37.58, 33.10], $this->prices($ordered));
    }

    /** No ordering asked for is this shop's own relevance ranking, untouched. */
    public function testWithoutASortNothingMoves(): void
    {
        $cards = $this->cards([37.58, 267.58, 33.10]);

        self::assertSame($this->prices($cards), $this->prices(OrderedByPrice::apply($cards, $this->query(null))));
    }

    /**
     * Ties keep retrieval's order, because relevance is the honest tiebreaker between two products
     * at the same price — and because a caller reading the first card must get a stable answer.
     */
    public function testTiesKeepTheOrderRetrievalGaveThem(): void
    {
        $cards = [
            $this->card('first at 50', 50.00),
            $this->card('second at 50', 50.00),
            $this->card('third at 10', 10.00),
        ];

        $ordered = OrderedByPrice::apply($cards, $this->query(PriceSort::Ascending));

        self::assertSame(
            ['third at 10', 'first at 50', 'second at 50'],
            array_map(static fn(ProductCard $card): string => $card->name, $ordered),
        );
    }

    public function testAnEmptyWindowIsLeftAlone(): void
    {
        self::assertSame([], OrderedByPrice::apply([], $this->query(PriceSort::Ascending)));
    }

    private function query(?PriceSort $sort): ProductQuery
    {
        return new ProductQuery(term: 'coat', sort: $sort);
    }

    /**
     * @param list<float> $prices
     *
     * @return list<ProductCard>
     */
    private function cards(array $prices): array
    {
        return array_map(fn(float $price): ProductCard => $this->card('coat ' . $price, $price), $prices);
    }

    private function card(string $name, float $price): ProductCard
    {
        return new ProductCard(
            id: str_pad((string) crc32($name), 32, '0'),
            parentId: null,
            name: $name,
            description: null,
            price: $price,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Parent,
            deliveryTime: null,
            url: '/p/' . urlencode($name),
            imageUrl: null,
        );
    }

    /**
     * @param list<ProductCard> $cards
     *
     * @return list<float>
     */
    private function prices(array $cards): array
    {
        return array_map(static fn(ProductCard $card): float => $card->price, $cards);
    }
}
