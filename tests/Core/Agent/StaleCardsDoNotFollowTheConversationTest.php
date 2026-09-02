<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\PreGrounding;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

/**
 * A card from the previous turn must not be rendered beside an answer that is not about it.
 *
 * **Reported from the staging shop, 2026-09-02.** Turn one: *"Do you sell bikes?"* — the reply
 * correctly said no and mentioned Bike Wash 1L, so that card rendered. Turn two: *"what is the return
 * policy"* — a correct 30-day-returns answer out of the shop's own documents, with the **Bike Wash
 * card rendered underneath it again**. Read off the trace:
 *
 * ```
 * 45 tool.call         {"name":"search_shop_info","stage":"dispatch"}
 * 48 grounding.select  {"source":"last_tool_batch","selectedIds":["c65a030e…"]}
 * 51 turn.end          {"cards":["c65a030e…"],"outcome":"product_shown"}
 * ```
 *
 * The turn called one tool and it was not a product tool, so nothing registered a product batch —
 * and `RecentCardsContext`'s pre-grounding seed was still standing in as the default. The whole
 * conversation was then filed as `outcome: product_shown`.
 *
 * The seed's job is to make "that one" *nameable*, which is the defect
 * {@see \Swag\AssistantStarterKit\Core\Prompt\RecentCardsContext} exists for. Being nameable does not
 * make it the answer to the next question, so it is registered for naming only — see
 * {@see FactRenderer::registerRecalled()}. A follow-up that does talk about those products still
 * renders them, by naming them; that is the second test here.
 */
final class StaleCardsDoNotFollowTheConversationTest extends TestCase
{
    public function testAShopInfoAnswerRendersNoProductCard(): void
    {
        $renderer = $this->rendererRecalling('fx-001');

        // What the live turn actually replied, and it names no product.
        $this->process(
            $renderer,
            'You have a 30-day return period starting from the day your order arrives. '
            . 'Items must be unused and in their original packaging.',
        );

        self::assertSame([], self::renderedIds($renderer));
    }

    /** The seed's own purpose, unbroken: a reply that names a recalled product still renders it. */
    public function testAFollowUpThatNamesARecalledProductStillRendersIt(): void
    {
        $renderer = $this->rendererRecalling('fx-001');

        $this->process($renderer, 'The Unnamed Chain Lube is the one I would keep for winter.');

        self::assertSame(['fx-001'], self::renderedIds($renderer));
    }

    /** Registered for naming, and deliberately not as the turn's default card set. */
    public function testARecalledCardIsNameableButIsNotTheDefaultBatch(): void
    {
        $renderer = $this->rendererRecalling('fx-001');

        self::assertContains('fx-001', $renderer->retrievedIds(), 'must stay nameable');
        self::assertSame([], $renderer->lastRetrievedBatch(), 'must not be the default rendered set');
    }

    /** A turn seeded exactly as a real one is: the previous reply's cards, and nothing on screen. */
    private function rendererRecalling(string $id): FactRenderer
    {
        $renderer = new FactRenderer(new TraceRecorder());
        PreGrounding::seed($renderer, [$this->card($id)], viewing: null);

        return $renderer;
    }

    /** @return list<string> */
    private static function renderedIds(FactRenderer $renderer): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $renderer->renderedCards());
    }

    private function card(string $id): ProductCard
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $card = $gateway->product($id, new CatalogScope());
        self::assertNotNull($card, $id . ' is missing from the fixture catalogue');

        return $card;
    }

    private function process(FactRenderer $renderer, string $prose): void
    {
        $output = new Output('gpt-x', new TextResult($prose), new MessageBag());
        (new GroundingOutputProcessor($renderer, new TraceRecorder()))->processOutput($output);
    }
}
