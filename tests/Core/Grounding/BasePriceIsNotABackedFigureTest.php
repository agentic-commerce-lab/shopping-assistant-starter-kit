<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\BasePrice;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\BackedFigures;

/**
 * A base price is shown to the shopper and never licensed to the model.
 *
 * {@see BackedFigures} builds the set of figures a reply may contain out of the rendered cards'
 * prices — a number that appears nowhere in it makes `no_unbacked_price_in_prose` fire. Every figure
 * added to that set is one more a model could hallucinate without being caught, so the set must
 * contain exactly what the shop has told the model and nothing else.
 *
 * The model is told no prices at all: {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary}
 * carries no figure of any kind. A base price is a price, computed for the CARD, and the model has
 * no legitimate reason to say one.
 *
 * **The concrete risk.** Four chain oils in the seeded catalogue cost €10.00 each and hold 50 ml,
 * 100 ml, 100 ml and 500 ml — base prices of €200.00, €100.00, €100.00 and €20.00. Were those in
 * the set, a reply inventing "€20.00" for a product costing €10.00 would pass the audit, because
 * some other card happened to work out at that figure per litre.
 */
final class BasePriceIsNotABackedFigureTest extends TestCase
{
    private function oil(float $price, float $litres): ProductCard
    {
        return new ProductCard(
            id: md5($price . '/' . $litres),
            parentId: null,
            name: 'Kettenöl',
            description: null,
            price: $price,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/oil',
            imageUrl: null,
            basePrice: BasePrice::of($price, $litres, 1.0, 'Liter'),
        );
    }

    /**
     * The card's own price is backed, as it always was.
     */
    public function testTheCardPriceIsStillBacked(): void
    {
        $backed = BackedFigures::inCents([$this->oil(10.00, 0.05)], '', []);

        self::assertArrayHasKey(1000, $backed, '10.00 EUR is on the card and may be said');
    }

    /**
     * The base price is not, even though the shopper can read it on the same card.
     */
    public function testTheBasePriceIsNotBacked(): void
    {
        $card = $this->oil(10.00, 0.05);

        self::assertNotNull($card->basePrice);
        self::assertSame(200.00, $card->basePrice->price, 'the card does show 200.00 per litre');

        $backed = BackedFigures::inCents([$card], '', []);

        self::assertArrayNotHasKey(20000, $backed, 'but the model may not state it as a price');
    }

    /**
     * The whole set, on the four oils that made this worth pinning: one figure in, three out.
     */
    public function testOnlyTheShelfPricesOfAWholeShortlistAreBacked(): void
    {
        $cards = [
            $this->oil(10.00, 0.05),
            $this->oil(10.00, 0.10),
            $this->oil(10.00, 0.50),
        ];

        $backed = BackedFigures::inCents($cards, '', []);

        self::assertSame([1000], array_keys($backed));
    }
}
