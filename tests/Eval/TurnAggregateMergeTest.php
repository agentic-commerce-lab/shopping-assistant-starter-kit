<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Eval\TurnAggregate;

/**
 * {@see TurnAggregate}'s three merge rules — later-card-wins, prose-join, last-turn's
 * outcome wins rather than being unioned — are, per its own docblock, "currently an
 * unexercised judgement call". This pins all three against one pair of turns that
 * deliberately disagree about the same card id, so the rule is fixed by a test rather
 * than left incidental to whatever {@see \Swag\AssistantStarterKit\Eval\JourneyAttempt}
 * happens to produce.
 */
final class TurnAggregateMergeTest extends TestCase
{
    public function testALaterCardSupersedesAnEarlierCardWithTheSameId(): void
    {
        $earlyCard = self::card(id: 'fx-shared', price: 10.0, stock: 5);
        $laterCard = self::card(id: 'fx-shared', price: 20.0, stock: 1);

        $turnOne = new AssistantTurn(
            prose: 'Turn one prose.',
            cards: [$earlyCard],
            outcome: 'product_shown',
            unbackedPrices: ['9.99'],
        );
        $turnTwo = new AssistantTurn(
            prose: 'Turn two prose.',
            cards: [$laterCard],
            outcome: 'cart_added',
            unbackedPrices: ['9.99', '5.00'],
        );

        $merged = TurnAggregate::of([$turnOne, $turnTwo]);

        // Later-card-wins: exactly one card survives for the shared id, and it is
        // turn two's version, not turn one's — proven by a field only the later card
        // carries (price 20.0, stock 1), not merely by re-checking the id.
        self::assertCount(1, $merged->cards);
        $survivor = $merged->cards[0] ?? null;
        self::assertNotNull($survivor);
        self::assertSame('fx-shared', $survivor->id);
        self::assertSame(20.0, $survivor->price);
        self::assertSame(1, $survivor->stock);

        // Prose-join: every turn's prose survives, newline-joined, in turn order.
        self::assertSame("Turn one prose.\nTurn two prose.", $merged->prose);

        // Last-turn-outcome: the LAST turn's outcome wins outright — it is not unioned
        // or concatenated with the earlier turn's outcome.
        self::assertSame('cart_added', $merged->outcome);

        // Unbacked prices are unioned across every turn, de-duplicated, first
        // occurrence order preserved.
        self::assertSame(['9.99', '5.00'], $merged->unbackedPrices);
    }

    public function testAggregatingASingleTurnIsTrivial(): void
    {
        $turn = new AssistantTurn(prose: 'Only turn.', cards: [self::card('fx-solo')], outcome: 'product_shown');

        $merged = TurnAggregate::of([$turn]);

        self::assertSame($turn->prose, $merged->prose);
        self::assertSame($turn->outcome, $merged->outcome);
        self::assertCount(1, $merged->cards);
        $onlyCard = $merged->cards[0] ?? null;
        self::assertNotNull($onlyCard);
        self::assertSame('fx-solo', $onlyCard->id);
    }

    public function testAggregatingAnEmptyListThrows(): void
    {
        $this->expectException(\LogicException::class);

        TurnAggregate::of([]);
    }

    private static function card(string $id, float $price = 9.99, int $stock = 3): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: 'Test product',
            description: null,
            price: $price,
            currency: 'EUR',
            stock: $stock,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }
}
