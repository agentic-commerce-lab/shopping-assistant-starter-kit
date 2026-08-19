<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Split out of {@see FactRendererTest}: covers validate()/render()'s case-insensitive
 * id matching specifically (fix-run3-brief Defect 2 — a correctly-named product in the
 * wrong case must not be reported as invented). Both files test {@see FactRenderer};
 * the split exists only because mago's too-many-methods rule (threshold 10) fired once
 * FactRendererTest would otherwise have grown past nine test methods, the same reason
 * {@see FactRendererUnbackedPricesTest} was split out earlier.
 */
final class FactRendererCaseInsensitiveIdTest extends TestCase
{
    private function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }

    public function testValidateAcceptsAnUpperCasedCandidateForALowerCasedRetrievedIdAndReturnsTheCanonicalId(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $product = $this->gateway()->product('fx-021', new CatalogScope());
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);

        // The model wrote the real id in the wrong case — this must still be accepted,
        // and accepted must carry the CANONICAL (lowercase) id, never "FX-021": render()
        // looks candidates up by exact key, and rendered_ids_exactly compares against
        // the canonical id too.
        $result = $renderer->validate(['FX-021']);

        self::assertSame(['fx-021'], $result->accepted);
        self::assertSame([], $result->invented);
    }

    public function testRenderOnACaseInsensitivelyAcceptedIdProducesTheCardUnderItsCanonicalId(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $product = $this->gateway()->product('fx-021', new CatalogScope());
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);

        $result = $renderer->validate(['FX-021']);
        $cards = $renderer->render($result->accepted);

        self::assertCount(1, $cards);
        $card = $cards[0] ?? null;
        self::assertNotNull($card);
        self::assertSame('fx-021', $card->id);
        self::assertSame($cards, $renderer->renderedCards());
    }

    public function testAGenuinelyInventedIdIsStillReportedAsInventedInTheCasingTheCallerPassed(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $product = $this->gateway()->product('fx-021', new CatalogScope());
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);

        // "FX-999" matches nothing in any casing — it must still be reported as invented,
        // in the exact casing the caller passed, not normalised.
        $result = $renderer->validate(['FX-999']);

        self::assertSame([], $result->accepted);
        self::assertSame(['FX-999'], $result->invented);
    }

    public function testRetrievedIdsIsUnaffectedByCaseInsensitiveMatching(): void
    {
        $renderer = new FactRenderer(new TraceRecorder());

        $product = $this->gateway()->product('fx-021', new CatalogScope());
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);

        $renderer->validate(['FX-021']);

        self::assertSame(['fx-021'], $renderer->retrievedIds());
    }
}
