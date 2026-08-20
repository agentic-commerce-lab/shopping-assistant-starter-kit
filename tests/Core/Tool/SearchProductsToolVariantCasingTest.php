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
 * Split out of {@see SearchProductsToolTest} (too-many-methods) rather than
 * suppressed: Finding I1.
 *
 * VariantSelectionFilterResolver::resolveGrouped() passed the model's raw option
 * casing straight into the retrieval filter, and VariantResolver::resolve() was
 * handed $intent->selections (also raw), so gateway->resolveVariant()'s
 * case-sensitive match failed even though the retrieval filter itself (built from
 * the SAME resolver, just a different code path) had already narrowed correctly.
 * Proven against the fixture catalogue: lowercase "black"/"m" with the catalogue's
 * own group names ("Colour"/"Size") used to return zero products; the group-less
 * form used to return three (two wrong). Both must now resolve to exactly one.
 */
final class SearchProductsToolVariantCasingTest extends TestCase
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

    public function testResolvesAGroupedSelectionWhoseValueCasingDiffersFromTheCatalog(): void
    {
        $result = $this->tool()(term: 'Trail Jersey', options: [
            ['option' => 'black', 'group' => 'Colour'],
            ['option' => 'm', 'group' => 'Size'],
        ]);

        self::assertSame(['fx-026-black-m'], self::ids($result));
    }

    public function testResolvesAGrouplessSelectionWhoseValueCasingDiffersFromTheCatalog(): void
    {
        $result = $this->tool()(term: 'Trail Jersey', options: [
            ['option' => 'black'],
            ['option' => 'm'],
        ]);

        self::assertSame(['fx-026-black-m'], self::ids($result));
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
