<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\QuestionsAtMost;
use Swag\AssistantStarterKit\Eval\Assertion\RenderedIdsFromEach;
use Swag\AssistantStarterKit\Eval\Assertion\RendersAtLeast;

/**
 * Whether the shopper was shown anything, and whether both branches reached them.
 *
 * Split from {@see QuestionsAtMostTest} for the per-class method cap. These two assertions belong
 * together: a question is only friction when it arrives INSTEAD of products, so the floor is what gives
 * `questions_at_most` its meaning.
 */
final class RenderingFloorTest extends TestCase
{
    /** The measured anti-friction case: a question with nothing shown. */
    public function testAQuestionWithNoProductsFailsTheFloor(): void
    {
        $result = (new RendersAtLeast())->evaluate(
            $this->turn('Could you tell me more — a dress, a suit, or a gift?'),
            new TraceRecorder(),
            ['count' => 1],
        );

        self::assertFalse($result->passed);
        self::assertStringContainsString('without being shown products', $result->detail);
    }

    public function testTheFloorPassesWhenProductsAreShown(): void
    {
        $result = (new RendersAtLeast())->evaluate(
            $this->turn('Here are a few.', [$this->card('a'), $this->card('b')]),
            new TraceRecorder(),
            ['count' => 2],
        );

        self::assertTrue($result->passed, $result->detail);
    }

    public function testTheFloorIsSafetyBecauseShowingNothingIsNotAMatterOfDegree(): void
    {
        self::assertTrue((new RendersAtLeast())->isSafety());
        self::assertFalse((new QuestionsAtMost())->isSafety());
    }

    public function testBothBranchesRepresentedPasses(): void
    {
        $result = (new RenderedIdsFromEach())->evaluate(
            $this->turn('Both.', [$this->card('fw-occ-dress-1-m'), $this->card('fw-occ-suit-3-l')]),
            new TraceRecorder(),
            ['groups' => [['fw-occ-dress-'], ['fw-occ-suit-']]],
        );

        self::assertTrue($result->passed, $result->detail);
    }

    /** The measured defect: prose described both, cards showed one side. */
    public function testAOneSidedRowFailsAndNamesTheMissingGroup(): void
    {
        $result = (new RenderedIdsFromEach())->evaluate(
            $this->turn('Dresses and suits.', [$this->card('fw-occ-dress-1-m'), $this->card('fw-occ-dress-2-m')]),
            new TraceRecorder(),
            ['groups' => [['fw-occ-dress-'], ['fw-occ-suit-']]],
        );

        self::assertFalse($result->passed);
        self::assertStringContainsString('fw-occ-suit-', $result->detail);
    }

    public function testASingleGroupAssertsNothingAndFails(): void
    {
        self::assertFalse((new RenderedIdsFromEach())->evaluate(
            $this->turn('One.', [$this->card('fw-occ-dress-1-m')]),
            new TraceRecorder(),
            ['groups' => [['fw-occ-dress-']]],
        )->passed);
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
