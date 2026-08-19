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

/**
 * Split out of {@see SearchProductsToolTest} (too-many-methods) rather than
 * suppressed, following {@see SearchProductsToolVariantCasingTest}'s precedent.
 *
 * Live run 3 rendered fx-026-blue-l in answer to questions about blue-M and
 * black-M. Cause, measured against the fixture catalogue: search ranks by
 * in-stock bias, so `limit: 1` on term "Jersey" returns blue-L (stock 12) while
 * blue-M (stock 0) ranks third of three. The bias buries the exact variant the
 * shopper asked about precisely when it is sold out, and nothing downstream can
 * repair it — VariantResolver cannot disambiguate a set of one.
 */
final class SearchProductsToolLimitTest extends TestCase
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

    /**
     * The floor on `limit` is load-bearing, not cosmetic. Ranking applies an in-stock
     * bias, so a sold-out unit sorts LAST — and with `limit: 1` the shopper's own
     * variant is truncated away before VariantResolver ever sees it, leaving the
     * pipeline to render a real price for a variant nobody asked about.
     *
     * Asserting the surviving ids, not just the number: with the floor removed this
     * search returns fx-026-blue-l (stock 12) alone and fx-026-blue-m (stock 0) is
     * gone, so this test fails on the outcome rather than on a bookkeeping figure.
     */
    public function testWidensATooNarrowLimitSoRankingCannotTruncateTheAskedForVariant(): void
    {
        $result = $this->tool()(term: 'Jersey', limit: 1);

        self::assertContains('fx-026-blue-m', $result['productIds']);

        $payload = $this->trace->payload('query.build');
        self::assertIsArray($payload);
        self::assertSame(1, $payload['limitRequested']);
        self::assertSame(5, $payload['limitApplied']);
    }

    /** A limit above the ceiling stays a rejection — that bound is not negotiable. */
    public function testRejectsALimitAboveTheCeiling(): void
    {
        $this->expectException(ToolArgumentException::class);

        $this->tool()(term: 'Jersey', limit: 21);
    }
}
