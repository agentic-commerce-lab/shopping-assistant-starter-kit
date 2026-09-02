<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class AddToCartToolRenderingTest extends TestCase
{
    private TraceRecorder $trace;

    private FactRenderer $renderer;

    // Initialized here (rather than via a default property value, which cannot nest a
    // "new" inside another "new"'s arguments) purely to satisfy the analyzer's
    // uninitialized-property check; tool() below still replaces it before any
    // assertion runs, so this only matters for the analyzer, not test behaviour.
    /**
     * Split out of {@see AddToCartToolTest} (too-many-methods) rather than suppressed.
     *
     * Covers which card the shopper sees after an add — the defect a real turn exposed, where the
     * confirmation named Black/M at 69.90 while the rendered card was the parent at 79.90 with stock 35.
     */
    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);
    }

    private function tool(AssistantConfig $config = new AssistantConfig()): AddToCartTool
    {
        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);

        return new AddToCartTool(
            FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'),
            new BlocklistFilter(),
            $this->renderer,
            $this->trace,
            $config,
        );
    }

    /**
     * A gateway whose product()/resolveVariant() ignore the scope they are handed —
     * standing in for a gateway that has not (yet) wired CatalogScope enforcement
     * into those two methods, e.g. a not-fully-compliant future DAL implementation.
     * Used to prove AddToCartTool's own explicit {@see BlocklistFilter} check (finding
     * C1, fix half 1) is a real, independently-firing defence, not dead code that only
     * ever "works" because {@see FixtureCommerceGateway} already refuses the product
     * one layer down (finding C1, fix half 2 — see FixtureCommerceGatewayTest for that
     * half's own, separate proof).
     */
    private function scopeIgnoringGateway(): CommerceGatewayInterface
    {
        $inner = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');

        return new class($inner) implements CommerceGatewayInterface {
            public function __construct(
                private readonly CommerceGatewayInterface $inner,
            ) {}

            public function facets(CatalogScope $scope): FacetSet
            {
                return $this->inner->facets($scope);
            }

            public function search(ProductQuery $query, CatalogScope $scope): array
            {
                return $this->inner->search($query, $scope);
            }

            public function product(string $productId, CatalogScope $scope): ?ProductCard
            {
                return $this->inner->product($productId, new CatalogScope());
            }

            public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
            {
                return $this->inner->resolveVariant($parentId, $selections, new CatalogScope());
            }

            public function addToCart(string $variantId, int $quantity): CartSummary
            {
                return $this->inner->addToCart($variantId, $quantity);
            }

            public function cart(): CartSummary
            {
                return $this->inner->cart();
            }
        };
    }

    public function testTheAddedVariantBecomesTheRenderedCard(): void
    {
        // Measured against the real shop before this was fixed: a turn that added Black/M rendered
        // the PARENT product — stock 35 at 79.90 — beside prose correctly saying "Black, size M,
        // €69.90", because FactRenderer's last batch was whatever the previous search left behind.
        // A UI showing stock 35 for a variant with 3 is a shopper-visible lie about availability,
        // which is the failure class D4 exists for.
        $tool = $this->tool();

        // Stand in for an earlier search in the same turn that retrieved a DIFFERENT unit of the
        // same family — which is exactly what happened in the real run: the search left the wrong
        // card as the last batch, and nothing afterwards corrected it.
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $earlier = $gateway->product('fx-026-blue-l', new CatalogScope());
        self::assertNotNull($earlier);
        $this->renderer->registerRetrieved([$earlier]);

        $tool(variantId: 'fx-026-black-m', options: [['Colour', 'Black'], ['Size', 'M']]);

        self::assertSame(['fx-026-black-m'], $this->renderer->lastRetrievedBatch());
    }
}
