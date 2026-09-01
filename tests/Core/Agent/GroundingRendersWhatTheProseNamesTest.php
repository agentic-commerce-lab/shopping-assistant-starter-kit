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
 * The cards a turn renders are the products the reply names.
 *
 * **Measured live, 2026-09-01.** Asked for helmets after a conversation about trail riding, the
 * assistant named three products and the widget rendered six — Kids Helmet, Commuter Helmet and
 * Helmet Rain Cover appeared as cards nobody had mentioned. A shopper reading three recommendations
 * beside six cards cannot tell which ones the assistant meant.
 *
 * The cause was not the model. `extractCandidateIds()` looked for product *ids* in the reply, and the
 * prompt forbids the model from writing them, so the id path never matched and the fallback rendered
 * the whole last tool batch. That agreed with the prose only while the model listed everything it had
 * found; asking it to recommend instead of list broke the coincidence.
 *
 * The fallback stays for a reply that names nothing — see the last test.
 */
final class GroundingRendersWhatTheProseNamesTest extends TestCase
{
    public function testOnlyTheProductsTheProseNamesAreRendered(): void
    {
        $renderer = $this->process('I would take the Alloy Bottle Cage for rough ground.', ['fx-017', 'fx-007']);

        self::assertSame(['fx-017'], self::renderedIds($renderer));
    }

    public function testTwoNamedProductsBothRender(): void
    {
        $renderer = $this->process('The Alloy Bottle Cage is my pick; the Alloy Water Bottle 750ml also fits.', [
            'fx-017',
            'fx-007',
        ]);

        self::assertEqualsCanonicalizing(['fx-017', 'fx-007'], self::renderedIds($renderer));
    }

    /**
     * The fallback, unchanged. A reply that names no product at all — a clarifying question, a refusal
     * — must still show the batch it was working from rather than silently dropping every card.
     */
    public function testAReplyNamingNothingStillFallsBackToTheLastBatch(): void
    {
        $renderer = $this->process('Which size are you looking for?', ['fx-017', 'fx-007']);

        self::assertEqualsCanonicalizing(['fx-017', 'fx-007'], self::renderedIds($renderer));
    }

    /**
     * An id in the prose still works. The model is not supposed to write one, but the path predates
     * this change and removing it would narrow what the audit can attribute.
     */
    public function testAnIdInTheProseStillSelectsItsProduct(): void
    {
        $renderer = $this->process('fx-017 is the one.', ['fx-017', 'fx-007']);

        self::assertSame(['fx-017'], self::renderedIds($renderer));
    }

    /**
     * Rendered ids, in the shape the existing `GroundingOutputProcessorTest` reads them.
     *
     * @return list<string>
     */
    private static function renderedIds(FactRenderer $renderer): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $renderer->renderedCards());
    }

    /** @param list<string> $registerIds */
    private function process(string $prose, array $registerIds): FactRenderer
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $cards = [];
        foreach ($registerIds as $id) {
            $card = $gateway->product($id, new CatalogScope());
            self::assertNotNull($card);
            $cards[] = $card;
        }
        $renderer->registerRetrieved($cards);

        $output = new Output('gpt-x', new TextResult($prose), new MessageBag());
        (new GroundingOutputProcessor($renderer, $trace))->processOutput($output);

        return $renderer;
    }
}
