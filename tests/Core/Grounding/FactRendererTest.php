<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class FactRendererTest extends TestCase
{
    private function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }

    public function testDropsIdsThatWereNeverRetrieved(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $product = $this->gateway()->product('fx-017');
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);

        $result = $renderer->validate(['fx-017', 'fx-999']);

        self::assertSame(['fx-017'], $result->accepted);
        self::assertSame(['fx-999'], $result->invented);

        $payload = $trace->payload('validate');
        self::assertNotNull($payload);
        self::assertSame(['fx-999'], $payload['inventedProductIds']);
    }

    public function testRenderReturnsTheAuthoritativeRecordNotModelOutput(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $variant = $this->gateway()->product('fx-026-blue-m');
        self::assertNotNull($variant);
        $renderer->registerRetrieved([$variant]);

        $cards = $renderer->render(['fx-026-blue-m']);

        self::assertCount(1, $cards);
        $card = $cards[0] ?? null;
        self::assertNotNull($card);
        self::assertSame(49.90, $card->price);
        self::assertSame(0, $card->stock);
    }

    public function testFindsCurrencyFiguresInProseThatNoRenderedCardBacks(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $product = $this->gateway()->product('fx-017');
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);
        $renderer->render(['fx-017']);

        $unbacked = $renderer->unbackedPricesInProse('Great news, the cage is just €1.29 today instead of €12.90.');

        self::assertSame(['1.29'], $unbacked);
    }

    public function testAcceptsProseWhoseFiguresAllMatchRenderedCards(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $product = $this->gateway()->product('fx-017');
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);
        $renderer->render(['fx-017']);

        self::assertSame([], $renderer->unbackedPricesInProse('The cage costs €12.90.'));
    }

    public function testRenderedCardsExposesTheLastRenderResult(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $product = $this->gateway()->product('fx-017');
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);

        $cards = $renderer->render(['fx-017']);

        self::assertSame($cards, $renderer->renderedCards());
    }

    public function testUnbackedPricesExposesTheLastUnbackedPricesInProseResult(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $product = $this->gateway()->product('fx-017');
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);
        $renderer->render(['fx-017']);

        $unbacked = $renderer->unbackedPricesInProse('The discounted price is €1.29 now.');

        self::assertSame($unbacked, $renderer->unbackedPrices());
    }
}
