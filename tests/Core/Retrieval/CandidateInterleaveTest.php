<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\CandidateInterleave;

/**
 * The merge that makes a multi-term search's limit shared rather than first-come.
 *
 * Narrowing downstream takes a PREFIX of this order, so the balance created here is the balance the
 * shopper sees — which is the whole point. See the class docblock for the measured defect.
 */
final class CandidateInterleaveTest extends TestCase
{
    public function testItTakesOneFromEachTermInTurn(): void
    {
        $merged = CandidateInterleave::of([
            [$this->card('a1'), $this->card('a2'), $this->card('a3')],
            [$this->card('b1'), $this->card('b2')],
        ], cap: 10);

        self::assertSame(['a1', 'b1', 'a2', 'b2', 'a3'], $this->ids($merged));
    }

    /**
     * The defect, as a unit test. A prefix of length four must contain both terms; concatenation would
     * make it `a1 a2 a3 a4` and render the one-sided row the shopper complained about.
     */
    public function testAPrefixOfTheResultStillContainsEveryTerm(): void
    {
        $merged = CandidateInterleave::of([
            [$this->card('a1'), $this->card('a2'), $this->card('a3'), $this->card('a4'), $this->card('a5')],
            [$this->card('b1')],
        ], cap: 10);

        self::assertContains('b1', $this->ids(\array_slice($merged, offset: 0, length: 4)));
    }

    public function testItDeduplicatesByIdKeepingTheFirstOccurrence(): void
    {
        $merged = CandidateInterleave::of([
            [$this->card('shared'), $this->card('a2')],
            [$this->card('shared'), $this->card('b2')],
        ], cap: 10);

        self::assertSame(['shared', 'a2', 'b2'], $this->ids($merged));
    }

    public function testItStopsAtTheCap(): void
    {
        $merged = CandidateInterleave::of([
            [$this->card('a1'), $this->card('a2')],
            [$this->card('b1'), $this->card('b2')],
        ], cap: 3);

        self::assertCount(3, $merged);
    }

    public function testOneTermIsUnchanged(): void
    {
        $cards = [$this->card('a1'), $this->card('a2')];

        self::assertSame(['a1', 'a2'], $this->ids(CandidateInterleave::of([$cards], cap: 10)));
    }

    public function testNoTermsIsEmptyRatherThanAnError(): void
    {
        self::assertSame([], CandidateInterleave::of([], cap: 10));
        self::assertSame([], CandidateInterleave::of([[], []], cap: 10));
    }

    /** @param list<ProductCard> $cards @return list<string> */
    private function ids(array $cards): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $cards);
    }

    private function card(string $id): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: 'Card ' . $id,
            description: null,
            price: 1.0,
            currency: 'EUR',
            stock: 1,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }
}
