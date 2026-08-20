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
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Split out of {@see SearchProductsToolTest} (too-many-methods) rather than suppressed,
 * following {@see SearchProductsToolLimitTest}'s precedent.
 *
 * Covers where the search result is narrowed to the model's `limit`: after variant
 * resolution and the blocklist, never during retrieval. See
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery::retrievalLimit()}.
 */
final class SearchProductsToolNarrowingTest extends TestCase
{
    private TraceRecorder $trace;
    private FactRenderer $renderer;

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

    public function testTheAskedForVariantIsStillTheAnswerAtTheNarrowestLimit(): void
    {
        // Regression guard, NOT a proof of the ordering repair — and the distinction
        // matters. `MIN_LIMIT = 5` already satisfied this assertion before retrieval
        // and truncation were separated, because no product family in
        // tests/Fixtures/catalog.json has more than four variants, so the floor alone
        // keeps the window wider than any family. The ordering defect is therefore not
        // observable through this tool against this catalogue at all.
        //
        // What actually distinguishes mitigated from repaired lives one layer down, in
        // FixtureCommerceGatewayTest::testRetrievalUsesTheCandidateWindowAndNotTheReturnLimit,
        // plus the two assertions below about where narrowing happens.
        $result = $this->tool()(term: 'Jersey', options: [['option' => 'Blue'], ['option' => 'M']], limit: 1);

        self::assertSame(['fx-026-blue-m'], self::ids($result));
    }

    public function testTheToolReturnsNoMoreThanTheModelAskedFor(): void
    {
        // Before the split there was no narrowing step at all: the coerced floor
        // widened retrieval and every survivor was handed back, so `limit: 1` on a
        // three-variant family returned three ids. The model's own bound was silently
        // ignored, which also means it could not bound context size or cost.
        $result = $this->tool()(term: 'Jersey', limit: 1);

        self::assertCount(1, self::ids($result));
        self::assertSame(1, $result['total']);
    }

    public function testNarrowingToTheReturnLimitIsRecordedRatherThanSilent(): void
    {
        // A bounded result that is not recorded reads as complete coverage.
        $this->tool()(term: 'Jersey', limit: 1);

        $payload = $this->trace->payload('retrieve.narrow');
        self::assertIsArray($payload);
        self::assertSame(1, $payload['returnLimit']);
        self::assertIsInt($payload['candidateLimit']);
        self::assertGreaterThan(1, $payload['candidateLimit']);
        self::assertIsInt($payload['truncated']);
        self::assertGreaterThan(0, $payload['truncated']);
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
