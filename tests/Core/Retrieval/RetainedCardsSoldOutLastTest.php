<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\PriceSort;
use Swag\AssistantStarterKit\Core\Retrieval\RetainedCards;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The bias applied where {@see \Swag\AssistantStarterKit\Core\Retrieval\RetrievalPass}'s docblock
 * says post-steps belong, so every one of its four reads gets it and both gateways do — the DAL one
 * never had it at all.
 */
final class RetainedCardsSoldOutLastTest extends TestCase
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

    private static function card(string $id, int $stock, float $price = 10.0): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: 'Gravel Helmet ' . $id,
            description: null,
            price: $price,
            currency: 'EUR',
            stock: $stock,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }

    public function testASoldOutCardDoesNotOutrankOneAShopperCanBuy(): void
    {
        $retained = RetainedCards::of(
            [self::card('gone', 0), self::card('here', 2)],
            new ProductQuery(term: 'Gravel Helmet'),
            'Gravel Helmet',
            new TraceRecorder(),
        );

        self::assertSame(['here', 'gone'], self::ids($retained->cards));
    }

    public function testAStatedPriceOrderStillWinsOutright(): void
    {
        // OrderedByPrice ran first and the shopper asked for the cheapest. Promoting the
        // available one here answers a different question from the one asked; the card still
        // carries its stock, so the reply can say it is sold out.
        $retained = RetainedCards::of(
            [self::card('dearer', 5, 30.0), self::card('cheapest-but-gone', 0, 10.0)],
            new ProductQuery(term: 'Gravel Helmet', sort: PriceSort::Ascending),
            'Gravel Helmet',
            new TraceRecorder(),
        );

        self::assertSame(['cheapest-but-gone', 'dearer'], self::ids($retained->cards));
    }
}
