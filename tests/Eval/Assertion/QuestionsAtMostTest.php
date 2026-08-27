<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\QuestionsAtMost;

/**
 * How many questions counts as too many, pinned deterministically.
 *
 * Split from {@see RenderingFloorTest} because the union of both sides' cases crossed mago's
 * per-class method cap — and the two ask different things: this one is about friction, that one about
 * whether the shopper was shown anything at all.
 */
final class QuestionsAtMostTest extends TestCase
{
    public function testOneQuestionSpreadOverTwoSentencesCountsOnce(): void
    {
        // Two marks, one thing asked. Counting marks would fail a turn that behaved correctly.
        $result = (new QuestionsAtMost())->evaluate(
            $this->turn('Here are some options. Menswear or womenswear? Or both?'),
            new TraceRecorder(),
            ['max' => 1],
        );

        self::assertTrue($result->passed, $result->detail);
    }

    public function testTwoDistinctQuestionsFailABoundOfOne(): void
    {
        $result = (new QuestionsAtMost())->evaluate(
            $this->turn('What size are you? What is your budget?'),
            new TraceRecorder(),
            ['max' => 1],
        );

        self::assertFalse($result->passed);
    }

    public function testMaxZeroPassesAStatementAndFailsAQuestion(): void
    {
        $assertion = new QuestionsAtMost();

        self::assertTrue($assertion->evaluate($this->turn('Here are all four.'), new TraceRecorder(), [
            'max' => 0,
        ])->passed);
        self::assertFalse($assertion->evaluate($this->turn('What size?'), new TraceRecorder(), ['max' => 0])->passed);
    }

    public function testAMissingMaxFailsRatherThanPassingVacuously(): void
    {
        self::assertFalse((new QuestionsAtMost())->evaluate($this->turn('Anything.'), new TraceRecorder(), [])->passed);
    }

    /** @param list<ProductCard> $cards */
    private function turn(string $prose, array $cards = []): AssistantTurn
    {
        return new AssistantTurn(prose: $prose, cards: $cards, outcome: 'product_shown');
    }

    private function card(string $id): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: 'Card ' . $id,
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: 1,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }
}
