<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\GetProductTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class GetProductToolTest extends TestCase
{
    private TraceRecorder $trace;
    private FactRenderer $renderer;

    // Initialized here (rather than via a default property value, which cannot nest a
    // "new" inside another "new"'s arguments) purely to satisfy the analyzer's
    // uninitialized-property check; tool() below still replaces both before any
    // assertion runs, so this only matters for the analyzer, not test behaviour.
    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);
    }

    private function tool(CatalogScope $scope = new CatalogScope()): GetProductTool
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);

        return new GetProductTool(
            $gateway,
            new VariantResolver($gateway, $this->trace),
            new BlocklistFilter(),
            $this->renderer,
            $this->trace,
            new AssistantConfig(scope: $scope),
        );
    }

    public function testResolvesTheRequestedVariantWithItsOwnStock(): void
    {
        $result = $this->tool()(productId: 'fx-026', options: [['option' => 'Blue'], ['option' => 'M']]);

        self::assertSame(['fx-026-blue-m'], $result['productIds']);

        $cards = $this->renderer->render($result['productIds']);
        $card = $cards[0] ?? null;
        self::assertNotNull($card);
        self::assertSame(0, $card->stock);
        self::assertSame(StockSource::Variant, $card->stockSource);
    }

    public function testReportsAnUnknownProductInsteadOfInventingOne(): void
    {
        $result = $this->tool()(productId: 'fx-999');

        self::assertSame([], $result['productIds']);
        $note = $result['note'] ?? null;
        self::assertNotNull($note);
        self::assertStringContainsString('No such product', $note);
    }

    public function testNeverReturnsABlockedProduct(): void
    {
        $tool = $this->tool(new CatalogScope(blockedProductIds: ['fx-014']));

        self::assertSame([], $tool(productId: 'fx-014')['productIds']);
    }

    /**
     * Finding R46: the raw model-cased selections went straight into
     * gateway->resolveVariant() and variantResolver->resolve(), both of which match
     * case-sensitively (see FixtureVariantMatcher::matches()) — so a lowercase
     * "black"/"m" selection, exactly what the model is told to send, silently failed
     * to resolve an existing variant.
     */
    public function testResolvesAGroupedSelectionWhoseValueCasingDiffersFromTheCatalog(): void
    {
        $result = $this->tool()(productId: 'fx-026', options: [
            ['option' => 'black', 'group' => 'Colour'],
            ['option' => 'm', 'group' => 'Size'],
        ]);

        self::assertSame(['fx-026-black-m'], $result['productIds']);

        $cards = $this->renderer->render($result['productIds']);
        $card = $cards[0] ?? null;
        self::assertNotNull($card);
        self::assertSame(54.90, $card->price);
        self::assertSame(StockSource::Variant, $card->stockSource);
    }

    public function testResolvesAGrouplessSelectionWhoseValueCasingDiffersFromTheCatalog(): void
    {
        $result = $this->tool()(productId: 'fx-026', options: [
            ['option' => 'black'],
            ['option' => 'm'],
        ]);

        self::assertSame(['fx-026-black-m'], $result['productIds']);

        $cards = $this->renderer->render($result['productIds']);
        $card = $cards[0] ?? null;
        self::assertNotNull($card);
        self::assertSame(54.90, $card->price);
        self::assertSame(StockSource::Variant, $card->stockSource);
    }

    /**
     * Canonicalising the option VALUE must not loosen matching so far that an
     * option value the catalog does not have resolves to something anyway — "purple"
     * is not a Colour value anywhere in the fixture catalogue.
     */
    public function testAnUnknownOptionValueStillReportsNoSuchProduct(): void
    {
        $result = $this->tool()(productId: 'fx-026', options: [
            ['option' => 'purple', 'group' => 'Colour'],
        ]);

        self::assertSame([], $result['productIds']);
        $note = $result['note'] ?? null;
        self::assertNotNull($note);
        self::assertStringContainsString('No such product', $note);
    }
}
