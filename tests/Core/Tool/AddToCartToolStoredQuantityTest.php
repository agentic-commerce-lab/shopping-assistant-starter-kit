<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNotice;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Split out of {@see AddToCartToolTest} (too-many-methods) rather than suppressed.
 *
 * Covers the defect this whole task exists to fix: the tool used to format its own `$quantity`
 * argument into the note it returns, so a shopper who asked for 10 of a product sold in fours was
 * told "Added 10 to the cart" while Shopware actually stored 8.
 */
final class AddToCartToolStoredQuantityTest extends TestCase
{
    /**
     * The option values the shopper chose, which `add_to_cart` now requires for any product that
     * has variants — see {@see AddToCartToolVariantChoiceTest} for why a variant nobody named is
     * refused. Every case in this file is about something else (quantity bounds, cart limits, what
     * the note reports), so the choice is stated once here rather than restated at each call.
     */
    private const CHOSEN = [['Colour', 'Blue'], ['Size', 'L']];

    private TraceRecorder $trace;

    private FactRenderer $renderer;

    // Initialized here for the same reason as AddToCartToolTest's own constructor: the analyzer's
    // uninitialized-property check cannot see that toolWith()/tool() always replace these before
    // any assertion runs.
    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);
    }

    private function tool(): AddToCartTool
    {
        return $this->toolWith(FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'));
    }

    /**
     * A gateway that behaves the way Shopware does: it accepts the add, stores a quantity of its
     * own choosing, and reports why. `FixtureCommerceGateway` cannot express that — it stores what
     * it is given — and that gap is precisely why this defect survived so long.
     */
    private function correctingGateway(int $stores, CartNoticeReason $reason): CommerceGatewayInterface
    {
        $inner = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');

        return new class($inner, $stores, $reason) implements CommerceGatewayInterface {
            public function __construct(
                private readonly CommerceGatewayInterface $inner,
                private readonly int $stores,
                private readonly CartNoticeReason $reason,
            ) {}

            public function facets(CatalogScope $scope): FacetSet
            {
                return $this->inner->facets($scope);
            }

            /** @return list<ProductCard> */
            public function search(ProductQuery $query, CatalogScope $scope): array
            {
                return $this->inner->search($query, $scope);
            }

            public function product(string $productId, CatalogScope $scope): ?ProductCard
            {
                return $this->inner->product($productId, $scope);
            }

            /** @param list<VariantSelection> $selections */
            public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
            {
                return $this->inner->resolveVariant($parentId, $selections, $scope);
            }

            public function addToCart(string $variantId, int $quantity): CartSummary
            {
                $card = $this->inner->product($variantId, new CatalogScope());

                return new CartSummary(
                    lineItems: [new CartLine(
                        lineId: $variantId,
                        variantId: $variantId,
                        name: $card?->name ?? '',
                        quantity: $this->stores,
                        unitPrice: $card?->price ?? 0.0,
                        lineTotal: ($card?->price ?? 0.0) * $this->stores,
                    )],
                    total: ($card?->price ?? 0.0) * $this->stores,
                    itemCount: $this->stores,
                    notices: [new CartNotice($variantId, $this->reason)],
                );
            }

            public function cart(): CartSummary
            {
                return new CartSummary();
            }
        };
    }

    private function toolWith(CommerceGatewayInterface $gateway): AddToCartTool
    {
        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);

        return new AddToCartTool($gateway, new BlocklistFilter(), $this->renderer, $this->trace, new AssistantConfig());
    }

    public function testTheNoteReportsTheQuantityShopwareStoredRatherThanTheOneRequested(): void
    {
        // A product sold in fours, asked for in tens. Shopware rounds to eight and says so; the
        // shopper who is told "Added 10" finds out at checkout.
        $tool = $this->toolWith($this->correctingGateway(8, CartNoticeReason::PurchaseSteps));

        $result = $tool(variantId: 'fx-026-blue-l', quantity: 10, options: self::CHOSEN);

        self::assertStringContainsString('8', $result['note']);
        self::assertStringNotContainsString('Added 10', $result['note']);
        self::assertStringContainsString('steps', $result['note']);
    }

    public function testALineShopwareRefusedEntirelyIsNotReportedAsAnAdd(): void
    {
        // `ProductOutOfStockError` removes the line. Nothing was added, and the reply must say so
        // rather than confirming an add that did not happen.
        $tool = $this->toolWith($this->correctingGateway(0, CartNoticeReason::OutOfStock));

        $result = $tool(variantId: 'fx-026-blue-l', quantity: 2, options: self::CHOSEN);

        self::assertStringContainsString('Nothing was added', $result['note']);
    }

    public function testAnUncorrectedAddStillReadsAsBefore(): void
    {
        $result = $this->tool()(variantId: 'fx-026-blue-l', quantity: 2, options: self::CHOSEN);

        self::assertSame('Added 2 to the cart.', $result['note']);
    }

    public function testTheTraceRecordsBothTheRequestedAndTheStoredQuantity(): void
    {
        // A merchant reading the trace must be able to see the divergence that the shopper was
        // told about, without inferring it from prose.
        $tool = $this->toolWith($this->correctingGateway(8, CartNoticeReason::PurchaseSteps));
        $tool(variantId: 'fx-026-blue-l', quantity: 10, options: self::CHOSEN);

        $payloads = [];
        foreach ($this->trace->events() as $event) {
            if ($event->stage === AddToCartTool::TRACE_STAGE) {
                $payloads[] = $event->payload;
            }
        }

        self::assertNotSame([], $payloads);
        $last = $payloads[\count($payloads) - 1];
        self::assertSame(10, $last['quantity'] ?? null);
        self::assertSame(8, $last['storedQuantity'] ?? null);
    }
}
