<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
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

    public function testRegisteringTheSameIdTwiceOverwritesTheEarlierCard(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $parent = new ProductCard(
            id: 'fx-X',
            parentId: null,
            name: 'Parent Aggregate',
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: 99,
            stockSource: StockSource::Parent,
            deliveryTime: null,
            url: '/x-parent',
            imageUrl: null,
        );
        $resolvedVariant = new ProductCard(
            id: 'fx-X',
            parentId: 'fx-X-parent',
            name: 'Resolved Variant',
            description: null,
            price: 12.0,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/x-variant',
            imageUrl: null,
        );

        // Two separate registerRetrieved() calls, as happens when a search result is
        // registered first and a later variant resolution supersedes it.
        $renderer->registerRetrieved([$parent]);
        $renderer->registerRetrieved([$resolvedVariant]);

        $cards = $renderer->render(['fx-X']);

        self::assertCount(1, $cards);
        $card = $cards[0] ?? null;
        self::assertNotNull($card);
        self::assertSame($resolvedVariant, $card);
        self::assertSame(3, $card->stock);
        self::assertSame(StockSource::Variant, $card->stockSource);
    }

    public function testAcceptsWholeEuroFigureInProseThatExactlyMatchesARenderedPrice(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        // fx-004-black is priced at a whole 24.00 euros with no cents; the brief's regex
        // extracts a figure like "€24" as "24", which must not be flagged as unbacked just
        // because "24" never string-equals a card price formatted to two decimals ("24.00").
        $variant = $this->gateway()->product('fx-004-black');
        self::assertNotNull($variant);
        self::assertSame(24.0, $variant->price);
        $renderer->registerRetrieved([$variant]);
        $renderer->render(['fx-004-black']);

        self::assertSame([], $renderer->unbackedPricesInProse('The price is €24 today.'));
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
