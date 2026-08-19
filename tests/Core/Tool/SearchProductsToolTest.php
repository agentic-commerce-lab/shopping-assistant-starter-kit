<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class SearchProductsToolTest extends TestCase
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

    private function tool(CatalogScope $scope = new CatalogScope()): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $this->trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $this->trace),
            new BlocklistFilter(),
            $this->renderer,
            $this->trace,
            new AssistantConfig(scope: $scope),
        );
    }

    public function testReturnsIdsOnlyAndNeverCards(): void
    {
        $result = $this->tool()(term: 'bottle');

        self::assertArrayHasKey('productIds', $result);
        self::assertArrayNotHasKey('cards', $result);
        self::assertArrayNotHasKey('price', $result);
        foreach ($result['productIds'] as $id) {
            self::assertIsString($id);
        }
    }

    public function testHonoursAPriceCeiling(): void
    {
        $result = $this->tool()(term: 'bottle', priceMax: 15.00);

        self::assertNotEmpty($result['productIds']);
        foreach ($this->renderer->render($result['productIds']) as $card) {
            self::assertLessThanOrEqual(15.00, $card->price);
        }
    }

    public function testNeverReturnsABlockedProduct(): void
    {
        $tool = $this->tool(new CatalogScope(blockedProductIds: ['fx-014']));

        $result = $tool(term: 'CO2');

        self::assertNotContains('fx-014', $result['productIds']);

        $payload = $this->trace->payload('blocklist.filter');
        self::assertNotNull($payload);
        $removedIds = $payload['removedIds'] ?? null;
        self::assertIsArray($removedIds);
        self::assertContains('fx-014', $removedIds);
    }

    public function testRegistersEveryReturnedIdWithTheFactRenderer(): void
    {
        $result = $this->tool()(term: 'mudguard');

        self::assertNotEmpty($result['productIds']);
        self::assertSame($result['productIds'], $this->renderer->validate($result['productIds'])->accepted);
    }

    public function testRecordsTheUnderstandStageFromItsOwnArguments(): void
    {
        $this->tool()(term: 'brake pads', priceMax: 40.0, brand: 'Shimano');

        $payload = $this->trace->payload('understand');
        self::assertSame('brake pads', $payload['term']);
        self::assertSame(40.0, $payload['priceMax']);
        self::assertSame('tool_arguments', $payload['source']);
    }

    public function testRejectsAnOversizedTermInsteadOfCoercingIt(): void
    {
        $this->expectException(ToolArgumentException::class);
        $this->expectExceptionMessage('term');

        $this->tool()(term: str_repeat('a', 500));
    }

    public function testRejectsAnOversizedOptionList(): void
    {
        $this->expectException(ToolArgumentException::class);
        $this->expectExceptionMessage('options');

        $this->tool()(term: 'jersey', options: array_fill(0, 30, ['option' => 'Blue']));
    }
}
