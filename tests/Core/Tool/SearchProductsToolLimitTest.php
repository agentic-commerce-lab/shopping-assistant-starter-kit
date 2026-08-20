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
 *
 * The floor that first mitigated this is gone. Retrieval now reads a candidate window
 * and narrowing to the model's `limit` happens after variant resolution, which is the
 * repair ARCHITECTURE.md recorded as needed but not done. See
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery::retrievalLimit()}.
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
     * The guarantee this test has always been about: ranking must not be able to truncate
     * the shopper's own variant away *before* VariantResolver sees it. Ranking applies an
     * in-stock bias, so a sold-out unit sorts LAST, and once it is gone nothing downstream
     * can repair it — VariantResolver cannot disambiguate a set of one, and the blocklist
     * only removes.
     *
     * What changed is where the guarantee is enforced, not the guarantee. It used to be a
     * FLOOR on the model's `limit`, which widened retrieval by silently overriding the
     * shopper's bound and then handed back everything it found. Retrieval and narrowing are
     * now separate steps: retrieval reads the wider candidate window, and narrowing to the
     * model's own `limit` happens after resolution. So the assertion moves from the returned
     * ids to the retrieved ones — which is the set resolution actually gets to work with —
     * and `limit: 1` now genuinely returns one product, as asked.
     */
    public function testRankingCannotTruncateTheAskedForVariantBeforeResolutionSeesIt(): void
    {
        $result = $this->tool()(term: 'Jersey', limit: 1);

        $retrieve = $this->trace->payload('retrieve');
        self::assertIsArray($retrieve);

        $retained = $retrieve['retainedIds'];
        self::assertIsArray($retained);
        self::assertContains('fx-026-blue-m', $retained);

        // The model's bound is honoured exactly rather than overridden.
        self::assertCount(1, self::ids($result));

        $payload = $this->trace->payload('query.build');
        self::assertIsArray($payload);
        self::assertSame(1, $payload['limitRequested']);
        self::assertSame(20, $payload['candidateLimit']);
    }

    /** A limit above the ceiling stays a rejection — that bound is not negotiable. */
    public function testRejectsALimitAboveTheCeiling(): void
    {
        $this->expectException(ToolArgumentException::class);

        $this->tool()(term: 'Jersey', limit: 21);
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
