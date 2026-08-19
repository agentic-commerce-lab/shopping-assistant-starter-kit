<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Split out of {@see FactRendererTest}: covers unbackedPricesInProse() and
 * unbackedPrices() specifically. Both files test {@see FactRenderer}; the split exists
 * only because mago's too-many-methods rule (threshold 10) fired once FactRendererTest
 * grew past nine test methods — see the task report for the ruling.
 */
final class FactRendererUnbackedPricesTest extends TestCase
{
    private function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }

    public function testFindsCurrencyFiguresInProseThatNoRenderedCardBacks(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $product = $this->gateway()->product('fx-017', new CatalogScope());
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);
        $renderer->render(['fx-017']);

        $unbacked = $renderer->unbackedPricesInProse('Great news, the cage is just €1.29 today instead of €12.90.');

        self::assertSame(['1.29'], $unbacked);
    }

    public function testAcceptsProseWhoseFiguresAllMatchRenderedCards(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $product = $this->gateway()->product('fx-017', new CatalogScope());
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);
        $renderer->render(['fx-017']);

        self::assertSame([], $renderer->unbackedPricesInProse('The cage costs €12.90.'));
    }

    public function testAcceptsWholeEuroFigureInProseThatExactlyMatchesARenderedPrice(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        // fx-004-black is priced at a whole 24.00 euros with no cents; the brief's regex
        // extracts a figure like "€24" as "24", which must not be flagged as unbacked just
        // because "24" never string-equals a card price formatted to two decimals ("24.00").
        $variant = $this->gateway()->product('fx-004-black', new CatalogScope());
        self::assertNotNull($variant);
        self::assertSame(24.0, $variant->price);
        $renderer->registerRetrieved([$variant]);
        $renderer->render(['fx-004-black']);

        self::assertSame([], $renderer->unbackedPricesInProse('The price is €24 today.'));
    }

    public function testUnbackedPricesExposesTheLastUnbackedPricesInProseResult(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $product = $this->gateway()->product('fx-017', new CatalogScope());
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);
        $renderer->render(['fx-017']);

        $unbacked = $renderer->unbackedPricesInProse('The discounted price is €1.29 now.');

        self::assertSame($unbacked, $renderer->unbackedPrices());
    }
}
