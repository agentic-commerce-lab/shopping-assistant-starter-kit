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

final class AddToCartToolTest extends TestCase
{
    private TraceRecorder $trace;

    private FactRenderer $renderer;

    // Initialized here (rather than via a default property value, which cannot nest a
    // "new" inside another "new"'s arguments) purely to satisfy the analyzer's
    // uninitialized-property check; tool() below still replaces it before any
    // assertion runs, so this only matters for the analyzer, not test behaviour.
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

    public function testAddsTheRequestedVariantAndReportsTheCart(): void
    {
        $result = $this->tool()(variantId: 'fx-026-blue-l', quantity: 2);

        self::assertSame(2, $result['cart']['itemCount']);
        self::assertSame('allowed', $this->trace->payload(AddToCartTool::TRACE_STAGE)['policyReasonCode']);
    }

    public function testBlocksAQuantityAboveMaxItemQuantity(): void
    {
        $result = $this->tool(new AssistantConfig(maxItemQuantity: 5))(variantId: 'fx-026-blue-l', quantity: 99);

        self::assertSame('cart_limit', $this->trace->payload(AddToCartTool::TRACE_STAGE)['policyReasonCode']);
        self::assertArrayNotHasKey('cart', $result);
        self::assertStringContainsString('at most 5', $result['note']);
    }

    /**
     * Finding C3 (RED before the fix): each call individually stayed under the
     * per-call quantity check, so six calls of 5 accumulated to 30 units in one
     * line — the same class of bug maxCartValue was already hardened against
     * (reading the live cart, not just the argument). Repeats a quantity that is
     * itself always within bounds, so only the ACCUMULATED total can be what trips
     * the limit.
     */
    public function testBlocksWhenRepeatedCallsWouldAccumulatePastMaxItemQuantity(): void
    {
        $tool = $this->tool(new AssistantConfig(maxItemQuantity: 5, maxCartValue: 100_000.0));

        $first = $tool(variantId: 'fx-026-blue-l', quantity: 5);
        self::assertSame(5, $first['cart']['itemCount'] ?? null);

        $second = $tool(variantId: 'fx-026-blue-l', quantity: 5);

        self::assertArrayNotHasKey('cart', $second);
        self::assertSame('cart_limit', $this->trace->payload(AddToCartTool::TRACE_STAGE)['policyReasonCode']);
        self::assertStringContainsString('at most 5', $second['note']);
    }

    public function testBlocksWhenTheCartWouldExceedMaxCartValue(): void
    {
        $result = $this->tool(new AssistantConfig(maxCartValue: 100.0))(variantId: 'fx-026-blue-l', quantity: 5);

        self::assertSame('cart_limit', $this->trace->payload(AddToCartTool::TRACE_STAGE)['policyReasonCode']);
        self::assertArrayNotHasKey('cart', $result);
    }

    public function testReportsAnUnknownVariantInsteadOfGuessing(): void
    {
        $result = $this->tool()(variantId: 'fx-999');

        self::assertStringContainsString('No such product', $result['note']);
    }

    /**
     * Finding C1 (RED before the fix): AddToCartTool never consulted the blocklist
     * at all, so a blocked product ("Added 1 to the cart" against fx-014, blocked by
     * id and category) reached the cart unfiltered. Uses {@see scopeIgnoringGateway()}
     * so this test exercises AddToCartTool's OWN check, not FixtureCommerceGateway's
     * separately-tested one.
     */
    public function testRefusesABlockedProduct(): void
    {
        $gateway = $this->scopeIgnoringGateway();
        $config = new AssistantConfig(scope: new CatalogScope(blockedProductIds: ['fx-014']));
        $tool = new AddToCartTool($gateway, new BlocklistFilter(), $this->renderer, $this->trace, $config);

        $result = $tool(variantId: 'fx-014', quantity: 1);

        self::assertArrayNotHasKey('cart', $result);
        self::assertSame('blocked_product', $this->trace->payload(AddToCartTool::TRACE_STAGE)['policyReasonCode']);
        self::assertSame(0, $gateway->cart()->itemCount, 'a blocked product must never mutate the cart');
    }

    public function testRejectsAnOversizedVariantId(): void
    {
        $this->expectException(ToolArgumentException::class);

        $this->tool()(variantId: str_repeat('x', 200));
    }
}
