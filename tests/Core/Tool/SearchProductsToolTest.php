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

        self::assertArrayHasKey('products', $result);
        self::assertArrayNotHasKey('cards', $result);
        self::assertArrayNotHasKey('price', $result);
        foreach (self::ids($result) as $id) {
            self::assertIsString($id);
        }
    }

    public function testHonoursAPriceCeiling(): void
    {
        $result = $this->tool()(term: 'bottle', priceMax: 15.00);

        self::assertNotEmpty(self::ids($result));
        foreach ($this->renderer->render(self::ids($result)) as $card) {
            self::assertLessThanOrEqual(15.00, $card->price);
        }
    }

    public function testNeverReturnsABlockedProduct(): void
    {
        $tool = $this->tool(new CatalogScope(blockedProductIds: ['fx-014']));

        $result = $tool(term: 'CO2');

        // fx-014 is never fetched: the full scope (blockedProductIds included) goes to
        // retrieval, so a scope-honouring gateway excludes it before BlocklistFilter ever
        // runs. BlocklistFilter still runs unconditionally as the second line of defence
        // (see the code comment in SearchProductsTool), so its stage is always recorded —
        // but with this gateway it is expected to have nothing left to remove. Whether
        // BlocklistFilter itself removes a blocked card is Task 5's unit tests' job, not
        // this integration test's; this test should not have to disable the first line of
        // defence to observe the second one firing.
        self::assertNotContains('fx-014', self::ids($result));
        self::assertNotNull($this->trace->payload('blocklist.filter'));
    }

    public function testRegistersEveryReturnedIdWithTheFactRenderer(): void
    {
        $result = $this->tool()(term: 'mudguard');

        self::assertNotEmpty(self::ids($result));
        self::assertSame(self::ids($result), $this->renderer->validate(self::ids($result))->accepted);
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

    /**
     * The ids out of a tool result. Tools return id + name + options per product (see
     * ToolProductSummary); these assertions are about which products came back, so they
     * project the ids out rather than restating the whole shape everywhere.
     *
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        $products = $result['products'] ?? [];
        self::assertIsArray($products);

        $ids = [];
        foreach ($products as $product) {
            self::assertIsArray($product);
            $id = $product['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }
}
