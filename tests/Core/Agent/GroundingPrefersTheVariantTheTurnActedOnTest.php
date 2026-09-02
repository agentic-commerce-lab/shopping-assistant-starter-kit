<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Reported from a live shop, 2026-09-02: a search showed the Trail Jersey in size M, the shopper
 * asked for size L, size L was added to the cart — and the card rendered beside the confirmation
 * was size M again.
 */
final class GroundingPrefersTheVariantTheTurnActedOnTest extends TestCase
{
    public function testTheAddedVariantIsRenderedRatherThanThePreviousTurnsCard(): void
    {
        $renderer = $this->renderer();

        // Turn start: the card the previous reply rendered, replayed by RecentCardsContext.
        $renderer->registerRetrieved([$this->card('fx-026-blue-m')]);
        // add_to_cart registers what it actually added.
        $renderer->registerRetrieved([$this->card('fx-026-blue-l')]);

        $this->process($renderer, 'Added the Trail Jersey in Blue, size L to your cart.');

        self::assertSame(['fx-026-blue-l'], self::renderedIds($renderer));
    }

    /**
     * The same ambiguity reaches a turn by a second route: the shopper is standing on the size M
     * detail page, so {@see \Swag\AssistantStarterKit\Core\Prompt\ViewingContext} registers that
     * variant at turn start. Asking for size L must still render size L.
     */
    public function testTheAddedVariantIsRenderedRatherThanTheProductOnScreen(): void
    {
        $renderer = $this->renderer();

        $renderer->registerRetrieved([$this->card('fx-026-black-m')]);
        $renderer->registerRetrieved([$this->card('fx-026-blue-l')]);

        $this->process($renderer, 'The Trail Jersey is now in your cart in Blue, size L.');

        self::assertSame(['fx-026-blue-l'], self::renderedIds($renderer));
    }

    /** @return list<string> */
    private static function renderedIds(FactRenderer $renderer): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $renderer->renderedCards());
    }

    private function renderer(): FactRenderer
    {
        return new FactRenderer(new TraceRecorder());
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
